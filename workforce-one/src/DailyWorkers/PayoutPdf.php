<?php
namespace WorkforceOne\DailyWorkers;

if (!defined('ABSPATH')) exit;

use WorkforceOne\Pdf\Document;
use WorkforceOne\Pdf\TrueTypeFont;

/**
 * The printed payout sheet (كشف اليومية, 3.31.76): A4, one row per worker with days, rate, extra
 * hours, amount, advance, net and an empty column for the signature or thumbprint; totals; the
 * workers' declaration; signature lines for the foreman, the cashier and the site manager.
 * Right-to-left when the labels are Arabic ($s['rtl']). Pure: no WordPress calls.
 */
final class PayoutPdf
{
    private const INK = [0.07, 0.07, 0.07];
    private const MUTED = [0.33, 0.33, 0.33];
    private const GRID = [0.53, 0.53, 0.53];
    private const HEAD = [0.925, 0.933, 0.957];
    private const LEFT = 30.0;
    private const RIGHT = 565.0;
    private const BOTTOM = 790.0;
    private const ROW = 26.0;
    /** Column widths (points), in reading order: #, name, trade, days, rate, extra, amount, advance, net, signature. */
    private const COLS = [22, 108, 66, 36, 44, 36, 50, 44, 52, 77];

    /**
     * @param array{rtl:bool,company:string,title:string,subtitle:string,meta:list<array{0:string,1:string}>,heads:list<string>,
     *   rows:list<list<string>>,total_label:string,totals:array{0:string,1:string,2:string},note:string,signatures:list<string>,generated:string,empty:string} $s
     */
    public static function render(TrueTypeFont $font, array $s): string
    {
        $doc = new Document($font, ['Title' => $s['title'], 'Author' => $s['company']]);
        $y = self::page($doc, $s, true);
        if (!$s['rows']) {
            $doc->text($s['rtl'] ? self::RIGHT : self::LEFT, $y + 20, $s['empty'], 11, $s['rtl'] ? 'right' : 'left', self::MUTED);
            $y += 34;
        }
        foreach ($s['rows'] as $row) {
            if ($y + self::ROW > self::BOTTOM) $y = self::page($doc, $s, false);
            self::row($doc, $s['rtl'], $y, $row, false);
            $y += self::ROW;
        }
        if ($s['rows']) {
            if ($y + self::ROW > self::BOTTOM) $y = self::page($doc, $s, false);
            $cells = array_fill(0, 10, '');
            $cells[1] = $s['total_label'];
            [$cells[6], $cells[7], $cells[8]] = $s['totals'];
            self::row($doc, $s['rtl'], $y, $cells, true);
            $y += self::ROW;
        }
        $lines = self::wrap($doc, $s['note'], 9, self::RIGHT - self::LEFT);
        if ($y + 30 + count($lines) * 14 + 70 > self::BOTTOM + 30) { $doc->addPage(); $y = 40.0; }
        $y += 22;
        foreach ($lines as $l) {
            $doc->text($s['rtl'] ? self::RIGHT : self::LEFT, $y, $l, 9, $s['rtl'] ? 'right' : 'left', self::MUTED);
            $y += 14;
        }
        $y += 46;
        $n = max(1, count($s['signatures']));
        $gap = 26.0;
        $w = (self::RIGHT - self::LEFT - $gap * ($n - 1)) / $n;
        foreach ($s['signatures'] as $i => $label) {
            $k = $s['rtl'] ? $n - 1 - $i : $i;
            $x = self::LEFT + $k * ($w + $gap);
            $doc->line($x, $y, $x + $w, $y, self::INK, 0.8);
            $doc->text($x + $w / 2, $y + 14, $label, 9.5, 'center', self::INK);
        }
        $doc->text(self::LEFT, 822, 'Workforce One · ' . $s['generated'], 7.5, 'left', self::MUTED);
        return $doc->output();
    }

    /** The heading (first page: title, company and the meta boxes) and the table head; returns the y of the first row. */
    private static function page(Document $doc, array $s, bool $first): float
    {
        $doc->addPage();
        $rtl = $s['rtl'];
        $y = 40.0;
        if ($first) {
            $doc->text($rtl ? self::RIGHT : self::LEFT, $y + 14, $s['title'], 20, $rtl ? 'right' : 'left', self::INK, true);
            $doc->text($rtl ? self::RIGHT : self::LEFT, $y + 32, $s['subtitle'], 10, $rtl ? 'right' : 'left', self::MUTED);
            $doc->line(self::LEFT, $y + 44, self::RIGHT, $y + 44, self::INK, 1.6);
            $y += 56;
            $n = max(1, count($s['meta']));
            $gap = 8.0;
            $w = (self::RIGHT - self::LEFT - $gap * ($n - 1)) / $n;
            foreach ($s['meta'] as $i => [$label, $value]) {
                $k = $rtl ? $n - 1 - $i : $i;
                $x = self::LEFT + $k * ($w + $gap);
                $doc->line($x, $y, $x + $w, $y, self::GRID);
                $doc->line($x, $y + 36, $x + $w, $y + 36, self::GRID);
                $doc->line($x, $y, $x, $y + 36, self::GRID);
                $doc->line($x + $w, $y, $x + $w, $y + 36, self::GRID);
                $tx = $rtl ? $x + $w - 7 : $x + 7;
                $doc->text($tx, $y + 13, $label, 7.5, $rtl ? 'right' : 'left', self::MUTED);
                $doc->text($tx, $y + 28, self::fit($doc, $value, 9.5, $w - 14), 9.5, $rtl ? 'right' : 'left', self::INK, true);
            }
            $y += 50;
        }
        self::row($doc, $rtl, $y, $s['heads'], true, true);
        return $y + self::ROW;
    }

    /** One table row: bordered cells, the name start-aligned, the rest centred. @param list<string> $cells */
    private static function row(Document $doc, bool $rtl, float $y, array $cells, bool $bold, bool $head = false): void
    {
        $x = $rtl ? self::RIGHT : self::LEFT;
        if ($head || $bold) $doc->rect(self::LEFT, $y, self::RIGHT - self::LEFT, self::ROW, $head ? self::HEAD : [0.965, 0.969, 0.98]);
        foreach (self::COLS as $i => $w) {
            $x0 = $rtl ? $x - $w : $x;
            $doc->line($x0, $y, $x0, $y + self::ROW, self::GRID);
            $doc->line($x0 + $w, $y, $x0 + $w, $y + self::ROW, self::GRID);
            $text = (string) ($cells[$i] ?? '');
            $size = $head ? 7.5 : 8.5;
            if ($i === 1 && !$head) $doc->text($rtl ? $x0 + $w - 5 : $x0 + 5, $y + 16, self::fit($doc, $text, $size, $w - 10), $size, $rtl ? 'right' : 'left', self::INK, true);
            else $doc->text($x0 + $w / 2, $y + 16, self::fit($doc, $text, $size, $w - 6), $size, 'center', self::INK, $bold);
            $x = $rtl ? $x - $w : $x + $w;
        }
        $doc->line(self::LEFT, $y, self::RIGHT, $y, self::GRID);
        $doc->line(self::LEFT, $y + self::ROW, self::RIGHT, $y + self::ROW, self::GRID);
    }

    /** @return list<string> */
    private static function wrap(Document $doc, string $text, float $size, float $width): array
    {
        $lines = [];
        $cur = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if ($cur !== '' && $doc->textWidth($try, $size) > $width) { $lines[] = $cur; $cur = $word; }
            else $cur = $try;
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines;
    }

    private static function fit(Document $doc, string $text, float $size, float $width): string
    {
        if ($doc->textWidth($text, $size) <= $width) return $text;
        while ($text !== '' && $doc->textWidth($text . '…', $size) > $width) $text = mb_substr($text, 0, -1);
        return $text . '…';
    }
}
