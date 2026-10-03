<?php
namespace WorkforceOne\Attendance;

use WorkforceOne\Support\Geo;

if (!defined('ABSPATH')) exit;

/**
 * Evaluates the device location sent with a Sign In / Sign Out. Pure: no WordPress calls.
 */
final class LocationAssessment
{
    public const MAX_ACCURACY_METERS = 100;
    public const MAX_CLOCK_SKEW_SECONDS = 300;
    public const MAX_SPEED_KMH = 180;
    public const MOVEMENT_WINDOW_SECONDS = 7200;

    public const QR_KIOSK_NO_COORDS = 'kiosk_no_coords';
    public const QR_LOCATION_REQUIRED = 'location_required';
    public const QR_OUTSIDE = 'outside_kiosk';

    /**
     * How trustworthy the reported position is. Recorded with the event; never blocks it on its own.
     *
     * @param int $nowTs Unix time (time()), never WordPress local time: the phone's timestamp is UTC.
     * @param array{latitude: mixed, longitude: mixed, location_timestamp: mixed}|null $previous
     *        The employee's last event that had coordinates (timestamps in milliseconds).
     * @return array{0: string, 1: string} [status, reason]
     */
    public static function integrity(?float $lat, ?float $lng, ?float $accuracy, ?int $timestampMs, int $nowTs, ?array $previous): array
    {
        if ($lat === null || $lng === null) {
            return ['unreliable', 'location_not_available'];
        }
        $status = 'verified';
        $reason = '';
        if ($accuracy === null) {
            $status = 'unreliable';
            $reason = 'missing_accuracy';
        } elseif ($accuracy > self::MAX_ACCURACY_METERS) {
            $status = 'unreliable';
            $reason = 'low_accuracy';
        }
        if ($timestampMs === null) {
            $status = 'unreliable';
            $reason = $reason ?: 'missing_timestamp';
        } elseif (abs((int) round($timestampMs / 1000) - $nowTs) > self::MAX_CLOCK_SKEW_SECONDS) {
            $status = 'unreliable';
            $reason = 'stale_timestamp';
        }
        if ($previous && $timestampMs !== null && !empty($previous['location_timestamp'])) {
            $elapsed = max(1, abs($timestampMs - (int) $previous['location_timestamp']) / 1000);
            if ($elapsed <= self::MOVEMENT_WINDOW_SECONDS) {
                $move = Geo::distanceMeters((float) $previous['latitude'], (float) $previous['longitude'], $lat, $lng);
                if ($move !== null && ($move / $elapsed) * 3.6 > self::MAX_SPEED_KMH) {
                    $status = 'suspicious';
                    $reason = 'impossible_movement';
                }
            }
        }
        return [$status, $reason];
    }

    /**
     * Position relative to the employee's work location.
     *
     * @param mixed $siteLat Configured latitude ('' when not configured).
     * @param mixed $siteLng Configured longitude ('' when not configured).
     * @return array{0: string, 1: float|null} [location_status, distance in metres]
     *         location_status: inside | outside | recorded (no site configured) | not_available
     */
    public static function geofence(?float $lat, ?float $lng, $siteLat, $siteLng, float $radius): array
    {
        $status = ($lat !== null && $lng !== null) ? 'recorded' : 'not_available';
        $distance = Geo::distanceMeters($lat, $lng, $siteLat, $siteLng);
        if ($distance !== null && $siteLat !== '' && $siteLng !== '') {
            $status = $distance <= $radius ? 'inside' : 'outside';
        }
        return [$status, $distance];
    }

    /**
     * Extra check for QR Sign-In: a photographed QR must not work away from the kiosk.
     *
     * @param mixed $kioskLat
     * @param mixed $kioskLng
     * @return array{0: string|null, 1: float|null} [error code or null, distance in metres]
     */
    public static function qrCheck(?float $lat, ?float $lng, $kioskLat, $kioskLng, float $radius, string $integrityStatus, string $integrityReason): array
    {
        $distance = Geo::distanceMeters($lat, $lng, $kioskLat, $kioskLng);
        if (!is_numeric($kioskLat) || !is_numeric($kioskLng)) {
            return [self::QR_KIOSK_NO_COORDS, $distance];
        }
        if ($distance === null || $integrityReason === 'stale_timestamp') {
            return [self::QR_LOCATION_REQUIRED, $distance];
        }
        if ($integrityStatus === 'suspicious' || $distance > $radius) {
            return [self::QR_OUTSIDE, $distance];
        }
        return [null, $distance];
    }
}
