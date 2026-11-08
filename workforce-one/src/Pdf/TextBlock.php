<?php
namespace WorkforceOne\Pdf;

if (!defined('ABSPATH')) exit;

/**
 * Running text for Document (HR letters): paragraphs wrapped to a width in logical order (word by word, as the reader reads them),
 * then each line drawn in visual order with the document's direction, right-aligned in Arabic and left-aligned in English. A line
 * that would pass the bottom margin starts a new page. Pure: no WordPress calls.
 */
final class TextBlock
{
    /**
     * The lines of a text at a width, in logical order. A line break in the text starts a new line; an empty line is kept as ''.
     * A word wider than the line is put on a line of its own (never cut).
     * @return string[]
     */
    public static function wrap(Document $doc, string $text, float $size, float $width): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $para) {
            $para = trim((string) preg_replace('/[ \t]+/u', ' ', $para));
            if ($para === '') { $lines[] = ''; continue; }
            $cur = '';
            foreach (explode(' ', $para) as $word) {
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur !== '' && $doc->textWidth($try, $size) > $width) {
                    $lines[] = $cur;
                    $cur = $word;
                } else {
                    $cur = $try;
                }
            }
            $lines[] = $cur;
        }
        return $lines;
    }

    /**
     * Draws a text between two margins from $y down, and returns the y below its last line.
     * @param bool $rtl an Arabic document: lines right-aligned at $right and laid out right to left
     * @param array{top:float,bottom:float} $page where text may go on a page; a new page starts at top
     * @param float[] $rgb
     */
    public static function draw(Document $doc, string $text, float $left, float $right, float $y, float $size, float $leading, bool $rtl, array $page, array $rgb = [0.06, 0.09, 0.16]): float
    {
        foreach (self::wrap($doc, $text, $size, $right - $left) as $line) {
            if ($y + $leading > $page['bottom']) {
                $doc->addPage();
                $y = $page['top'];
            }
            if ($line !== '') {
                if ($rtl) $doc->text($right, $y, $line, $size, 'right', $rgb, false, 'R');
                else $doc->text($left, $y, $line, $size, 'left', $rgb, false, 'L');
            }
            $y += $leading;
        }
        return $y;
    }
}
