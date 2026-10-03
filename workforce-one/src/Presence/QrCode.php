<?php
namespace WorkforceOne\Presence;

if (!defined('ABSPATH')) exit;

/**
 * The kiosk's rotating QR code: "wfo1|<kiosk id>|<time slot>|<HMAC-SHA256 signature>". A slot lasts
 * 15 seconds and a code is accepted in its own slot and the ones next to it. The signing key is the
 * kiosk's secret plus a site salt (AUTH_KEY). Pure: the caller passes the time and the key.
 */
final class QrCode
{
    public const PREFIX = 'wfo1';
    public const SLOT_SECONDS = 15;

    public static function slot(int $unixTime): int
    {
        return intdiv($unixTime, self::SLOT_SECONDS);
    }

    public static function payload(int $kioskId, int $slot, string $key): string
    {
        $data = self::PREFIX . '|' . $kioskId . '|' . $slot;
        return $data . '|' . hash_hmac('sha256', $data, $key);
    }

    /**
     * The parts of a scanned code, or null when it is not a Workforce One code.
     * @return array{kiosk_id:int,slot:int,signature:string}|null
     */
    public static function parse(string $raw): ?array
    {
        $parts = explode('|', trim($raw));
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) return null;
        $kid = (int) $parts[1];
        if ($kid <= 0 || (string) $kid !== $parts[1] || !preg_match('/^-?\d+$/', $parts[2]) || !preg_match('/^[a-f0-9]{64}$/', $parts[3])) return null;
        return ['kiosk_id' => $kid, 'slot' => (int) $parts[2], 'signature' => $parts[3]];
    }

    /** The code's slot is the current one or next to it. */
    public static function fresh(int $slot, int $nowSlot): bool
    {
        return abs($nowSlot - $slot) <= 1;
    }

    public static function signatureValid(array $parsed, string $key): bool
    {
        $expected = hash_hmac('sha256', self::PREFIX . '|' . $parsed['kiosk_id'] . '|' . $parsed['slot'], $key);
        return hash_equals($expected, $parsed['signature']);
    }
}
