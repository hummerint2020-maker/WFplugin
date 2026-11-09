<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * The Hijri date of a Gregorian day (HR letters, owner decision Q13): the Umm al-Qura calendar through PHP's intl extension when the
 * site has it, else the tabular (arithmetic) calendar, which often differs from Umm al-Qura by a day and sometimes by two (HR letters will say
 * so where the Hijri date is switched on). Pure: no WordPress calls.
 */
final class HijriDate
{
    public const MONTHS_AR = ['محرم', 'صفر', 'ربيع الأول', 'ربيع الآخر', 'جمادى الأولى', 'جمادى الآخرة', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذو القعدة', 'ذو الحجة'];
    public const MONTHS_EN = ['Muharram', 'Safar', 'Rabi al-Awwal', 'Rabi al-Thani', 'Jumada al-Ula', 'Jumada al-Akhirah', 'Rajab', "Sha'ban", 'Ramadan', 'Shawwal', "Dhu al-Qa'dah", 'Dhu al-Hijjah'];

    /**
     * @param string $ymd Y-m-d
     * @return array{y:int,m:int,d:int}|null month 1–12; null for a date that is not one
     */
    public static function fromGregorian(string $ymd): ?array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
        if (class_exists('IntlCalendar')) {
            $cal = \IntlCalendar::createInstance('UTC', 'en_US@calendar=islamic-umalqura');
            if ($cal instanceof \IntlCalendar && $cal->getType() === 'islamic-umalqura') {
                $cal->setTime(gmmktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) * 1000.0);
                return ['y' => (int) $cal->get(\IntlCalendar::FIELD_YEAR), 'm' => (int) $cal->get(\IntlCalendar::FIELD_MONTH) + 1, 'd' => (int) $cal->get(\IntlCalendar::FIELD_DAY_OF_MONTH)];
            }
        }
        return self::tabular((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /**
     * The tabular Islamic calendar (civil epoch, 16 July 622 Julian).
     * @return array{y:int,m:int,d:int}
     */
    public static function tabular(int $y, int $mo, int $d): array
    {
        // Julian day number of the Gregorian date (at noon).
        $a = intdiv(14 - $mo, 12);
        $yy = $y + 4800 - $a;
        $mm = $mo + 12 * $a - 3;
        $jd = $d + intdiv(153 * $mm + 2, 5) + 365 * $yy + intdiv($yy, 4) - intdiv($yy, 100) + intdiv($yy, 400) - 32045;
        $l = $jd - 1948440 + 10632;
        $n = intdiv($l - 1, 10631);
        $l = $l - 10631 * $n + 354;
        $j = intdiv(10985 - $l, 5316) * intdiv(50 * $l, 17719) + intdiv($l, 5670) * intdiv(43 * $l, 15238);
        $l = $l - intdiv(30 - $j, 15) * intdiv(17719 * $j, 50) - intdiv($j, 16) * intdiv(15238 * $j, 43) + 29;
        $m = intdiv(24 * $l, 709);
        return ['y' => 30 * $n + $j - 30, 'm' => $m, 'd' => $l - intdiv(709 * $m, 24)];
    }

    /** "17 Jumada al-Ula 1448 AH" or, in Arabic with Arabic-Indic digits, "١٧ جمادى الأولى ١٤٤٨ هـ". */
    public static function format(array $h, string $lang): string
    {
        if ($lang === 'ar') return NumberWords::arabicDigits($h['d'] . ' ' . self::MONTHS_AR[$h['m'] - 1] . ' ' . $h['y']) . ' هـ';
        return $h['d'] . ' ' . self::MONTHS_EN[$h['m'] - 1] . ' ' . $h['y'] . ' AH';
    }
}
