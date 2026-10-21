<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

use WorkforceOne\Pdf\Document;
use WorkforceOne\Pdf\TrueTypeFont;

/**
 * The shared Team Schedule of one week as a PDF (A4; names and statuses may be Arabic): one row per
 * employee, one column per working day, each day a coloured box with the status in words.
 * Used by the app's PDF and WhatsApp buttons (includes/trait-schedule-view.php → schedule_pdf()).
 * Pure: no WordPress calls.
 */
final class SchedulePdf
{
    private const INK = [0.078, 0.102, 0.2];
    private const MUTED = [0.416, 0.439, 0.565];
    private const LINE = [0.91, 0.914, 0.949];
    private const LEFT = 32.0;
    private const RIGHT = 563.0;
    private const BOTTOM = 800.0;
    private const ROW = 30.0;
    /** Box fill and text colour per tone of Ui\Icons::forStatus(). */
    public const TONES = [
        'office' => [[0.902, 0.969, 0.937], [0.059, 0.42, 0.263]],
        'wfh' => [[0.906, 0.933, 1.0], [0.122, 0.31, 0.722]],
        'leave' => [[0.945, 0.918, 1.0], [0.357, 0.204, 0.71]],
        'away' => [[1.0, 0.949, 0.871], [0.541, 0.31, 0.0]],
        'absent' => [[1.0, 0.91, 0.925], [0.647, 0.114, 0.243]],
        'off' => [[0.933, 0.941, 0.961], [0.231, 0.267, 0.4]],
        'none' => [[0.965, 0.969, 0.98], [0.416, 0.439, 0.565]],
    ];

    /**
     * @param array{company:string,title:string,heading:string,employee:string,empty:string,range:string,days:list<array{name:string,date:string}>,rows:list<array{name:string,team:string,cells:list<array{label:string,tone:string}>}>,generated:string} $s
     */
    public static function render(TrueTypeFont $font, array $s): string
    {
        $doc = new Document($font, ['Title' => $s['title'], 'Author' => $s['company']]);
        $days = count($s['days']) ?: 1;
        $nameW = 150.0;
        $colW = (self::RIGHT - self::LEFT - $nameW) / $days;
        $y = self::page($doc, $s, $nameW, $colW);
        if (!$s['rows']) {
            $doc->text(self::LEFT, $y + 20, $s['empty'], 11, 'left', self::MUTED);
        }
        foreach ($s['rows'] as $i => $r) {
            if ($y + self::ROW > self::BOTTOM) $y = self::page($doc, $s, $nameW, $colW);
            if ($i % 2 === 1) $doc->rect(self::LEFT, $y, self::RIGHT - self::LEFT, self::ROW, [0.984, 0.984, 0.996]);
            $doc->text(self::LEFT + 6, $y + 13, self::fit($doc, $r['name'], 9.5, $nameW - 10), 9.5, 'left', self::INK, true);
            if ($r['team'] !== '') $doc->text(self::LEFT + 6, $y + 24, self::fit($doc, $r['team'], 7.5, $nameW - 10), 7.5, 'left', self::MUTED);
            foreach ($r['cells'] as $c => $cell) {
                [$fill, $ink] = self::TONES[$cell['tone']] ?? self::TONES['none'];
                $x = self::LEFT + $nameW + $c * $colW;
                $doc->rect($x + 2, $y + 4, $colW - 4, self::ROW - 8, $fill);
                $doc->text($x + $colW / 2, $y + 18, self::fit($doc, $cell['label'], 8, $colW - 8), 8, 'center', $ink, true);
            }
            $doc->line(self::LEFT, $y + self::ROW, self::RIGHT, $y + self::ROW, self::LINE);
            $y += self::ROW;
        }
        $doc->text(self::LEFT, 822, 'Workforce One · ' . $s['generated'], 7.5, 'left', self::MUTED);
        return $doc->output();
    }

    /** A new page with the title band and the day headings; returns the y of the first row. */
    private static function page(Document $doc, array $s, float $nameW, float $colW): float
    {
        $doc->addPage();
        $doc->rect(0, 0, Document::WIDTH, 78, self::INK);
        $doc->text(self::LEFT, 38, $s['heading'], 20, 'left', [1, 1, 1], true);
        $doc->text(self::LEFT, 60, $s['range'], 11, 'left', [0.8, 0.84, 0.92]);
        $doc->text(self::RIGHT, 38, $s['company'], 12, 'right', [1, 1, 1], true);
        $y = 96.0;
        $doc->rect(self::LEFT, $y, self::RIGHT - self::LEFT, 30, [0.953, 0.957, 0.98]);
        $doc->text(self::LEFT + 6, $y + 19, $s['employee'], 8.5, 'left', self::MUTED, true);
        foreach ($s['days'] as $c => $d) {
            $x = self::LEFT + $nameW + $c * $colW + $colW / 2;
            $doc->text($x, $y + 13, $d['name'], 8.5, 'center', self::INK, true);
            $doc->text($x, $y + 24, $d['date'], 7.5, 'center', self::MUTED);
        }
        return $y + 30;
    }

    /** The text, cut with an ellipsis to fit a width. */
    private static function fit(Document $doc, string $text, float $size, float $width): string
    {
        if ($doc->textWidth($text, $size) <= $width) return $text;
        while ($text !== '' && $doc->textWidth($text . '…', $size) > $width) $text = mb_substr($text, 0, -1);
        return $text . '…';
    }
}
