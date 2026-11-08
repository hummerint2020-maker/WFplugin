<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * Numbers for letters (HR letters, owner decision Q13): Arabic-Indic digits, and whole amounts in words in Arabic and English
 * ("ثمانية عشر ألفًا وخمسمائة" · "eighteen thousand five hundred"). The Arabic words use the masculine counting forms (the pound,
 * the riyal, the dirham, the dinar are masculine). Pure: no WordPress calls.
 */
final class NumberWords
{
    private const AR_ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];
    private const AR_TENS = ['', 'عشرة', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    private const AR_HUNDREDS = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];
    /** scale => [one, two, 3–10, 11–99] */
    private const AR_SCALES = [
        1000000000 => ['مليار', 'ملياران', 'مليارات', 'مليارًا'],
        1000000 => ['مليون', 'مليونان', 'ملايين', 'مليونًا'],
        1000 => ['ألف', 'ألفان', 'آلاف', 'ألفًا'],
    ];
    private const EN_ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    private const EN_TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    private const EN_SCALES = [1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'];

    /** Western digits (and the decimal and thousands separators between digits) as Arabic-Indic: 18,500.50 → ١٨٬٥٠٠٫٥٠. */
    public static function arabicDigits(string $s): string
    {
        $s = (string) preg_replace_callback('/(?<=\d)[,.](?=\d)/', static function ($m) { return $m[0] === ',' ? '٬' : '٫'; }, $s);
        return strtr($s, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }

    /** A whole number in Arabic words (0 to 999,999,999,999). @throws \InvalidArgumentException beyond that */
    public static function arabic(int $n): string
    {
        if ($n > 999999999999 || $n < -999999999999) throw new \InvalidArgumentException('number_range');
        if ($n < 0) return 'سالب ' . self::arabic(-$n);
        if ($n === 0) return 'صفر';
        $parts = [];
        foreach (self::AR_SCALES as $scale => $forms) {
            $g = intdiv($n, $scale);
            $n %= $scale;
            if ($g > 0) $parts[] = self::arabicScale($g, $forms);
        }
        if ($n > 0) $parts[] = self::arabicBelow1000($n);
        return implode(' و', $parts);
    }

    /** A whole number in English words (0 to 999,999,999,999). @throws \InvalidArgumentException beyond that */
    public static function english(int $n): string
    {
        if ($n > 999999999999 || $n < -999999999999) throw new \InvalidArgumentException('number_range');
        if ($n < 0) return 'minus ' . self::english(-$n);
        if ($n === 0) return 'zero';
        $parts = [];
        foreach (self::EN_SCALES as $scale => $word) {
            $g = intdiv($n, $scale);
            $n %= $scale;
            if ($g > 0) $parts[] = self::englishBelow1000($g) . ' ' . $word;
        }
        if ($n > 0) $parts[] = self::englishBelow1000($n);
        return implode(' ', $parts);
    }

    /** @param string[] $forms [one, two, 3–10, 11–99] */
    private static function arabicScale(int $g, array $forms): string
    {
        if ($g === 1) return $forms[0];
        if ($g === 2) return $forms[1];
        $r = $g % 100;
        if ($g >= 100 && $r === 0) {
            $h = intdiv($g, 100);
            // Two hundred thousand: the dual in construct (مائتا ألف).
            $head = $h === 2 ? 'مائتا' : self::AR_HUNDREDS[$h];
            return $head . ' ' . $forms[0];
        }
        // 101 000 / 102 000: "مائة ألف وألف" / "مائة ألف وألفان" (the one or two is counted by the noun itself).
        if ($g > 100 && ($r === 1 || $r === 2)) return self::arabicScale($g - $r, $forms) . ' و' . $forms[$r - 1];
        $words = self::arabicBelow1000($g);
        if ($r >= 3 && $r <= 10) return $words . ' ' . $forms[2];
        if ($r >= 11) return $words . ' ' . $forms[3];
        return $words . ' ' . $forms[0];
    }

    private static function arabicBelow1000(int $n): string
    {
        $parts = [];
        $h = intdiv($n, 100);
        $t = $n % 100;
        if ($h > 0) $parts[] = self::AR_HUNDREDS[$h];
        if ($t > 0) $parts[] = self::arabicBelow100($t);
        return implode(' و', $parts);
    }

    private static function arabicBelow100(int $t): string
    {
        if ($t < 10) return self::AR_ONES[$t];
        if ($t === 10) return 'عشرة';
        if ($t === 11) return 'أحد عشر';
        if ($t === 12) return 'اثنا عشر';
        if ($t < 20) return self::AR_ONES[$t - 10] . ' عشر';
        $u = $t % 10;
        $tens = self::AR_TENS[intdiv($t, 10)];
        return $u === 0 ? $tens : self::AR_ONES[$u] . ' و' . $tens;
    }

    private static function englishBelow1000(int $n): string
    {
        $parts = [];
        $h = intdiv($n, 100);
        $t = $n % 100;
        if ($h > 0) $parts[] = self::EN_ONES[$h] . ' hundred';
        if ($t > 0) $parts[] = $t < 20 ? self::EN_ONES[$t] : self::EN_TENS[intdiv($t, 10)] . ($t % 10 ? '-' . self::EN_ONES[$t % 10] : '');
        return implode(' ', $parts);
    }
}
