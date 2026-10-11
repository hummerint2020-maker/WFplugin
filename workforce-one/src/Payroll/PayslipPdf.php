<?php
namespace WorkforceOne\Payroll;

if (!defined('ABSPATH')) exit;

use WorkforceOne\Pdf\Document;
use WorkforceOne\Pdf\TrueTypeFont;

/**
 * A closed month's payslip as a PDF (A4, English; names and reasons may be Arabic).
 * Same lines as My Pay: earnings, deductions with the days behind them, net pay.
 * Pure: no WordPress calls.
 */
final class PayslipPdf
{
    private const INK = [0.063, 0.094, 0.157];
    private const MUTED = [0.4, 0.44, 0.52];
    private const LINE = [0.89, 0.91, 0.93];
    private const GREEN = [0.024, 0.463, 0.278];
    private const RED = [0.706, 0.137, 0.094];
    private const LEFT = 48.0;
    private const RIGHT = 547.0;
    private const BOTTOM = 790.0;

    /** @var Document */
    private $doc;
    /** @var float */
    private $y = 0;
    /** @var bool the last thing drawn was a day line */
    private $afterDetail = false;

    /**
     * @param array{company:string,employee:string,month_label:string,closed_at:string,currency:string,rules:array<string,mixed>,pay:array<string,mixed>,generated:string} $s
     */
    public static function render(TrueTypeFont $font, array $s): string
    {
        $self = new self();
        $self->doc = new Document($font, ['Title' => 'Payslip ' . $s['month_label'] . ' - ' . $s['employee'], 'Author' => $s['company']]);
        $self->page($s);
        $self->body($s);
        return $self->doc->output();
    }

    /** @param array<string,mixed> $s */
    private function page(array $s): void
    {
        $d = $this->doc;
        $d->addPage();
        $d->rect(0, 0, Document::WIDTH, 92, self::INK);
        $d->text(self::LEFT, 46, 'Payslip', 24, 'left', [1, 1, 1], true);
        $d->text(self::LEFT, 70, (string) $s['month_label'], 12, 'left', [0.8, 0.84, 0.92]);
        $d->text(self::RIGHT, 46, (string) $s['company'], 13, 'right', [1, 1, 1], true);
        $d->text(self::RIGHT, 70, 'Workforce One', 9, 'right', [0.8, 0.84, 0.92]);
        $this->y = 124;
        if ($d->pageCount() > 1) {
            $d->text(self::LEFT, $this->y, $s['employee'] . ' · ' . $s['month_label'] . ' (continued)', 10, 'left', self::MUTED);
            $this->y += 22;
        }
    }

    private function room(float $needed, array $s): void
    {
        if ($this->y + $needed > self::BOTTOM) $this->page($s);
    }

    /** @param array<string,mixed> $s */
    private function body(array $s): void
    {
        $d = $this->doc;
        $p = $s['pay'];
        $cur = (string) $s['currency'];
        $money = static function ($v) { return PayRules::money((float) $v); };
        $day = static function ($date) { return date('D j M', (int) strtotime((string) $date)); };

        // Employee and status.
        $d->text(self::LEFT, $this->y, 'Employee', 9, 'left', self::MUTED);
        $d->text(300, $this->y, 'Status', 9, 'left', self::MUTED);
        $d->text(self::LEFT, $this->y + 18, (string) $s['employee'], 15, 'left', self::INK, true);
        $d->text(300, $this->y + 18, 'Final · closed ' . date('j M Y', (int) strtotime((string) $s['closed_at'])), 11, 'left', self::GREEN, true);
        $this->y += 44;

        // Net pay.
        $d->rect(self::LEFT, $this->y, self::RIGHT - self::LEFT, 64, [0.937, 0.953, 1.0]);
        $d->text(self::LEFT + 16, $this->y + 22, 'Net pay · before income tax and social insurance', 9.5, 'left', self::MUTED);
        $d->text(self::LEFT + 16, $this->y + 50, $cur . ' ' . $money($p['net']), 24, 'left', self::INK, true);
        $this->y += 82;

        // Figures.
        $stats = [[$p['stats']['worked_days'] . '/' . $p['stats']['expected_days'], 'Days worked'], [(string) $p['stats']['late_days'], 'Late days'],
            [(string) $p['stats']['absent_days'], 'Absent'], [PayCalculator::hm((int) $p['overtime']['minutes']), 'Overtime']];
        $w = (self::RIGHT - self::LEFT - 24) / 4;
        foreach ($stats as $i => [$v, $label]) {
            $x = self::LEFT + $i * ($w + 8);
            $d->rect($x, $this->y, $w, 44, [0.973, 0.976, 0.988]);
            $d->text($x + $w / 2, $this->y + 20, $v, 13, 'center', self::INK, true);
            $d->text($x + $w / 2, $this->y + 35, $label, 8.5, 'center', self::MUTED);
        }
        $this->y += 66;

        // Earnings.
        $this->heading('Earnings', $s);
        $this->row('Basic salary', '', $money($p['basic']), self::INK, $s);
        foreach ($p['allowances'] as $a) $this->row((string) $a['name'], 'allowance', $money($a['amount']), self::INK, $s);
        if (!empty($p['prorated'])) $this->row('Paid from ' . $day($p['prorated']['from']), $p['prorated']['days'] . ' days × ' . $money($p['day_value']), $money($p['earned']), self::INK, $s);
        if ($p['overtime']['days']) {
            $this->row('Overtime (approved and worked)', PayCalculator::hm((int) $p['overtime']['minutes']), '+' . $money($p['overtime']['amount']), self::GREEN, $s);
            foreach ($p['overtime']['days'] as $o) $this->detail($day($o['date']) . ' · ' . PayCalculator::hm((int) $o['minutes']) . ' × ' . rtrim(rtrim(number_format((float) $o['rate'], 2, '.', ''), '0'), '.') . ($o['off'] ? ' (day off)' : ''), '+' . $money($o['amount']), $s);
        }
        foreach ($p['bonuses']['items'] ?? [] as $a) $this->row('Bonus', (string) $a['reason'], '+' . $money($a['amount']), self::GREEN, $s);
        $this->y += 12;

        // Deductions.
        $this->heading('Deductions', $s);
        $any = false;
        if ($p['absence']['days']) {
            $any = true;
            $this->row('Absent without leave', count($p['absence']['days']) . ' day(s)', '−' . $money($p['absence']['amount']), self::RED, $s);
            foreach ($p['absence']['days'] as $a) $this->detail($day($a['date']), '−' . $money($a['amount']), $s);
        }
        if ($p['late']['days']) {
            $any = true;
            $this->row('Late arrival', $p['late']['minutes'] . ' min, after the grace period', '−' . $money($p['late']['amount']), self::RED, $s);
            foreach ($p['late']['days'] as $a) $this->detail($day($a['date']) . ' · in ' . $a['sign_in'] . ' · ' . $a['minutes'] . ' min', '−' . $money($a['amount']), $s);
        }
        if ($p['early']['days']) {
            $any = true;
            $this->row('Early leave without approval', $p['early']['minutes'] . ' min', '−' . $money($p['early']['amount']), self::RED, $s);
            foreach ($p['early']['days'] as $a) $this->detail($day($a['date']) . ' · out ' . $a['sign_out'] . ' · ' . $a['minutes'] . ' min', '−' . $money($a['amount']), $s);
        }
        foreach ($p['leave']['types'] as $type => $t) {
            $any = true;
            $this->row((string) $type, $t['days'] . ' day(s), ' . $t['paid'] . '% paid', '−' . $money($t['amount']), self::RED, $s);
            $this->detail(implode(', ', array_map($day, $t['dates'])), '', $s);
        }
        foreach ($p['manual']['items'] ?? [] as $a) { $any = true; $this->row('Deduction', (string) $a['reason'], '−' . $money($a['amount']), self::RED, $s); }
        if ($p['cap'] !== null) $this->row('Limited to ' . $s['rules']['max_deduction_days'] . " day(s)' pay", 'attendance deductions before the limit: ' . $money($p['deductions_before_cap']), '−' . $money($p['cap']), self::RED, $s);
        if (!$any) $this->row('None this month', '', '—', self::MUTED, $s);
        $this->y += 12;

        // Net pay again, and how a day is valued.
        $this->room(80, $s);
        $d->line(self::LEFT, $this->y, self::RIGHT, $this->y, self::INK, 1.2);
        $this->y += 22;
        $d->text(self::LEFT, $this->y, 'Net pay', 14, 'left', self::INK, true);
        $d->text(self::RIGHT, $this->y, $cur . ' ' . $money($p['net']), 14, 'right', self::INK, true);
        $this->y += 28;
        $base = ($s['rules']['day_base'] ?? 'gross') === 'basic' ? 'basic' : '(basic + allowances)';
        $d->text(self::LEFT, $this->y, "A day's pay = " . $base . ' ÷ ' . (int) ($s['rules']['day_divisor'] ?? 30) . ' = ' . $money($p['day_value']) . '. Amounts are before income tax and social insurance.', 8.5, 'left', self::MUTED);
        $d->text(self::LEFT, $this->y + 13, 'Questions about this payslip? Contact HR. Generated ' . $s['generated'] . '.', 8.5, 'left', self::MUTED);
    }

    private function heading(string $title, array $s): void
    {
        $this->room(40, $s);
        $this->doc->text(self::LEFT, $this->y, $title, 12, 'left', self::INK, true);
        $this->y += 8;
        $this->doc->line(self::LEFT, $this->y, self::RIGHT, $this->y, self::LINE, 1);
        $this->y += 18;
    }

    /** @param float[] $rgb */
    private function row(string $label, string $sub, string $amount, array $rgb, array $s): void
    {
        if ($this->afterDetail) { $this->y += 6; $this->afterDetail = false; }
        $this->room($sub !== '' ? 30 : 20, $s);
        $this->doc->text(self::LEFT, $this->y, $label, 10.5);
        if ($sub !== '') $this->doc->text(self::LEFT + 4 + $this->doc->textWidth($label, 10.5) + 6, $this->y, $sub, 8.5, 'left', self::MUTED);
        $this->doc->text(self::RIGHT, $this->y, $amount, 10.5, 'right', $rgb, true);
        $this->y += 19;
    }

    private function detail(string $text, string $amount, array $s): void
    {
        $this->room(16, $s);
        $this->doc->text(self::LEFT + 14, $this->y, $text, 8.5, 'left', self::MUTED);
        if ($amount !== '') $this->doc->text(self::RIGHT, $this->y, $amount, 8.5, 'right', self::MUTED);
        $this->y += 14;
        $this->afterDetail = true;
    }
}
