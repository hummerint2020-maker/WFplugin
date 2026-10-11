<?php
namespace WorkforceOne\Pdf;

if (!defined('ABSPATH')) exit;

/**
 * Arabic for PDF text drawn glyph by glyph: joins letters (Arabic Presentation Forms-B, with the
 * lam-alef ligatures) and puts a line in visual order (right-to-left runs reversed, Latin words and
 * numbers kept left-to-right). Enough for names and short reasons on a payslip; harakat are dropped.
 * Pure: no WordPress calls.
 */
final class ArabicText
{
    /** letter => [isolated, final, initial, medial]; 0 = the letter does not join that way */
    private const FORMS = [
        0x0621 => [0xFE80, 0, 0, 0],
        0x0622 => [0xFE81, 0xFE82, 0, 0], 0x0623 => [0xFE83, 0xFE84, 0, 0], 0x0624 => [0xFE85, 0xFE86, 0, 0],
        0x0625 => [0xFE87, 0xFE88, 0, 0], 0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], 0x0627 => [0xFE8D, 0xFE8E, 0, 0],
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92], 0x0629 => [0xFE93, 0xFE94, 0, 0], 0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98],
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C], 0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0], 0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8], 0x062F => [0xFEA9, 0xFEAA, 0, 0], 0x0630 => [0xFEAB, 0xFEAC, 0, 0],
        0x0631 => [0xFEAD, 0xFEAE, 0, 0], 0x0632 => [0xFEAF, 0xFEB0, 0, 0], 0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4],
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8], 0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC], 0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0],
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4], 0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8], 0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC],
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0], 0x0640 => [0x0640, 0x0640, 0x0640, 0x0640], 0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4],
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8], 0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC], 0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0],
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4], 0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8], 0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC],
        0x0648 => [0xFEED, 0xFEEE, 0, 0], 0x0649 => [0xFEEF, 0xFEF0, 0, 0], 0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
    ];
    /** alef after lam => [isolated, final] lam-alef ligature */
    private const LAM_ALEF = [0x0622 => [0xFEF5, 0xFEF6], 0x0623 => [0xFEF7, 0xFEF8], 0x0625 => [0xFEF9, 0xFEFA], 0x0627 => [0xFEFB, 0xFEFC]];
    private const MIRROR = [0x28 => 0x29, 0x29 => 0x28, 0x5B => 0x5D, 0x5D => 0x5B, 0x3C => 0x3E, 0x3E => 0x3C];

    /** Whether the text has Arabic letters. */
    public static function hasArabic(string $text): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }

    /**
     * Codepoints in the order they are drawn, left to right.
     * @return int[]
     */
    public static function visual(string $text): array
    {
        $cps = self::codepoints($text);
        if (!self::hasArabic($text)) return $cps;
        $shaped = self::shape($cps);
        // Direction of each character: R (Arabic), L (Latin letters, digits), N (spaces, punctuation).
        $dir = array_map(static function ($c) { return self::isArabic($c) ? 'R' : (self::isStrongLtr($c) ? 'L' : 'N'); }, $shaped);
        $para = 'L';
        foreach ($dir as $d) {
            if ($d !== 'N') {
                $para = $d;
                break;
            }
        }
        // A neutral takes the direction of its neighbours when both agree, else the paragraph's.
        $n = count($dir);
        for ($i = 0; $i < $n; $i++) {
            if ($dir[$i] !== 'N') continue;
            $j = $i; while ($j < $n && $dir[$j] === 'N') $j++;
            $before = $i > 0 ? $dir[$i - 1] : $para;
            $after = $j < $n ? $dir[$j] : $para;
            for ($k = $i; $k < $j; $k++) $dir[$k] = $before === $after ? $before : $para;
            $i = $j - 1;
        }
        $runs = [];
        foreach ($shaped as $i => $c) {
            if (!$runs || end($runs)['dir'] !== $dir[$i]) $runs[] = ['dir' => $dir[$i], 'cps' => []];
            $runs[count($runs) - 1]['cps'][] = $c;
        }
        $out = [];
        if ($para === 'R') $runs = array_reverse($runs);
        foreach ($runs as $r) {
            if ($r['dir'] === 'R') {
                foreach (array_reverse($r['cps']) as $c) $out[] = self::MIRROR[$c] ?? $c;
            } else {
                foreach ($r['cps'] as $c) $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * Contextual forms in logical order.
     * @param int[] $cps
     * @return int[]
     */
    public static function shape(array $cps): array
    {
        $cps = array_values(array_filter($cps, static function ($c) { return !($c >= 0x064B && $c <= 0x065F) && $c !== 0x0670; }));
        $out = [];
        $n = count($cps);
        for ($i = 0; $i < $n; $i++) {
            $c = $cps[$i];
            if (!isset(self::FORMS[$c])) { $out[] = $c; continue; }
            $joinsBefore = $i > 0 && self::joinsForward($cps[$i - 1]);
            if ($c === 0x0644 && $i + 1 < $n && isset(self::LAM_ALEF[$cps[$i + 1]])) {
                $out[] = self::LAM_ALEF[$cps[$i + 1]][$joinsBefore ? 1 : 0];
                $i++;
                continue;
            }
            $f = self::FORMS[$c];
            $joinsAfter = $f[2] !== 0 && $i + 1 < $n && isset(self::FORMS[$cps[$i + 1]]) && self::FORMS[$cps[$i + 1]][1] !== 0;
            if ($joinsBefore && $joinsAfter && $f[3]) $out[] = $f[3];
            elseif ($joinsBefore && $f[1]) $out[] = $f[1];
            elseif ($joinsAfter && $f[2]) $out[] = $f[2];
            else $out[] = $f[0];
        }
        return $out;
    }

    /** A letter that connects to the letter after it (dual-joining). */
    private static function joinsForward(int $c): bool
    {
        return isset(self::FORMS[$c]) && self::FORMS[$c][2] !== 0;
    }

    private static function isArabic(int $c): bool
    {
        return ($c >= 0x0600 && $c <= 0x06FF && !($c >= 0x0660 && $c <= 0x0669)) || ($c >= 0xFB50 && $c <= 0xFEFC);
    }

    private static function isStrongLtr(int $c): bool
    {
        return ($c >= 0x30 && $c <= 0x39) || ($c >= 0x41 && $c <= 0x5A) || ($c >= 0x61 && $c <= 0x7A) || ($c >= 0xC0 && $c <= 0x24F) || ($c >= 0x0660 && $c <= 0x0669);
    }

    /** @return int[] */
    public static function codepoints(string $text): array
    {
        $out = [];
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) $out[] = (int) mb_ord($ch, 'UTF-8');
        return $out;
    }
}
