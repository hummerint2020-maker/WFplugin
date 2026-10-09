<?php
namespace WorkforceOne\DailyWorkers;

if (!defined('ABSPATH')) exit;

/**
 * A daily worker's national ID (3.31.76): checking an Egyptian number, keeping it encrypted
 * (authenticated encryption, libsodium secretbox), searching it by a keyed hash and showing it masked.
 * Pure: no WordPress calls; the keys come from the caller (derived from wp-config salts, never
 * stored in the database).
 *
 * Egyptian number: 14 digits = century (2 = 1900s, 3 = 2000s) + YYMMDD birth date + governorate
 * code + 4-digit serial (odd = male) + a check digit.
 */
final class NationalId
{
    public const GOVERNORATES = [
        '01' => ['القاهرة', 'Cairo'], '02' => ['الإسكندرية', 'Alexandria'], '03' => ['بورسعيد', 'Port Said'], '04' => ['السويس', 'Suez'],
        '11' => ['دمياط', 'Damietta'], '12' => ['الدقهلية', 'Dakahlia'], '13' => ['الشرقية', 'Sharqia'], '14' => ['القليوبية', 'Qalyubia'],
        '15' => ['كفر الشيخ', 'Kafr El Sheikh'], '16' => ['الغربية', 'Gharbia'], '17' => ['المنوفية', 'Monufia'], '18' => ['البحيرة', 'Beheira'],
        '19' => ['الإسماعيلية', 'Ismailia'], '21' => ['الجيزة', 'Giza'], '22' => ['بني سويف', 'Beni Suef'], '23' => ['الفيوم', 'Fayoum'],
        '24' => ['المنيا', 'Minya'], '25' => ['أسيوط', 'Asyut'], '26' => ['سوهاج', 'Sohag'], '27' => ['قنا', 'Qena'], '28' => ['أسوان', 'Aswan'],
        '29' => ['الأقصر', 'Luxor'], '31' => ['البحر الأحمر', 'Red Sea'], '32' => ['الوادي الجديد', 'New Valley'], '33' => ['مطروح', 'Matrouh'],
        '34' => ['شمال سيناء', 'North Sinai'], '35' => ['جنوب سيناء', 'South Sinai'], '88' => ['خارج مصر', 'Born abroad'],
    ];

    /** Western digits from Arabic-Indic ones, spaces and dashes removed. */
    public static function normalize(string $value): string
    {
        $value = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        return (string) preg_replace('/[\s\-]+/u', '', trim($value));
    }

    /**
     * Checks an Egyptian number.
     * @return array{ok:bool,error:string,birth?:string,governorate?:string,male?:bool}
     *   error: '' | length | digits | century | birth | governorate
     */
    public static function checkEgyptian(string $value, string $today): array
    {
        $v = self::normalize($value);
        if (!ctype_digit($v)) return ['ok' => false, 'error' => 'digits'];
        if (strlen($v) !== 14) return ['ok' => false, 'error' => 'length'];
        $c = $v[0];
        if ($c !== '2' && $c !== '3') return ['ok' => false, 'error' => 'century'];
        $y = ($c === '2' ? 1900 : 2000) + (int) substr($v, 1, 2);
        $m = (int) substr($v, 3, 2);
        $d = (int) substr($v, 5, 2);
        if (!checkdate($m, $d, $y)) return ['ok' => false, 'error' => 'birth'];
        $birth = sprintf('%04d-%02d-%02d', $y, $m, $d);
        if ($birth > $today) return ['ok' => false, 'error' => 'birth'];
        $g = substr($v, 7, 2);
        if (!isset(self::GOVERNORATES[$g])) return ['ok' => false, 'error' => 'governorate'];
        return ['ok' => true, 'error' => '', 'birth' => $birth, 'governorate' => $g, 'male' => ((int) $v[12]) % 2 === 1];
    }

    /** 2900••••••1234 (first and last four; shorter numbers keep only the last two). */
    public static function mask(string $first, string $last, int $length): string
    {
        $hidden = max(2, $length - strlen($first) - strlen($last));
        return $first . str_repeat('•', $hidden) . $last;
    }

    /** @return array{first:string,last:string,length:int} what may be kept in plain text for the mask */
    public static function maskParts(string $value): array
    {
        $v = self::normalize($value);
        $n = mb_strlen($v);
        if ($n >= 12) return ['first' => mb_substr($v, 0, 4), 'last' => mb_substr($v, -4), 'length' => $n];
        return ['first' => '', 'last' => $n > 4 ? mb_substr($v, -2) : '', 'length' => $n];
    }

    /** The search key: HMAC-SHA256 of the normalized number. */
    public static function hash(string $value, string $key): string
    {
        return hash_hmac('sha256', self::normalize($value), $key);
    }

    /** Authenticated encryption (XSalsa20-Poly1305). @param string $key 32 bytes */
    public static function seal(string $value, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox(self::normalize($value), $nonce, $key));
    }

    /** @return string|null null when the value was tampered with or the key is wrong */
    public static function open(string $sealed, string $key): ?string
    {
        if (strpos($sealed, 'v1:') !== 0) return null;
        $raw = base64_decode(substr($sealed, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        return $plain === false ? null : $plain;
    }
}
