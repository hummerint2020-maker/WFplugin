<?php
namespace WorkforceOne\DailyWorkers;

if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\LocationAssessment;

/**
 * Where and by whom a daily workers' day may be recorded (3.31.76). Pure: no WordPress calls.
 *
 * The foreman's day sheet and a worker's own Sign In both need the phone's position inside the site,
 * checked with the same integrity rules as the staff Sign In (src/Attendance/LocationAssessment.php):
 * no position, a stale phone clock or an impossible movement is refused, as is a site without
 * coordinates.
 */
final class SiteRules
{
    /** May this kind of recorder record at a site in this mode? $who: foreman | self */
    public static function allows(string $mode, string $who): bool
    {
        if ($mode === 'both') return true;
        return $mode === $who;
    }

    /**
     * @param mixed $siteLat
     * @param mixed $siteLng
     * @param array{latitude: mixed, longitude: mixed, location_timestamp: mixed}|null $previous
     * @return array{error:string,distance:?float,integrity:string,reason:string}
     *   error: '' | site (no coordinates) | location (none / stale clock) | suspicious | outside
     */
    public static function location(?float $lat, ?float $lng, ?float $accuracy, ?int $timestampMs, int $nowTs, $siteLat, $siteLng, float $radius, ?array $previous = null): array
    {
        [$integrity, $reason] = LocationAssessment::integrity($lat, $lng, $accuracy, $timestampMs, $nowTs, $previous);
        $out = ['error' => '', 'distance' => null, 'integrity' => $integrity, 'reason' => $reason];
        if (!is_numeric($siteLat) || !is_numeric($siteLng) || ((float) $siteLat === 0.0 && (float) $siteLng === 0.0)) {
            $out['error'] = 'site';
            return $out;
        }
        [$status, $distance] = LocationAssessment::geofence($lat, $lng, (float) $siteLat, (float) $siteLng, $radius);
        $out['distance'] = $distance;
        if ($distance === null || $reason === 'stale_timestamp' || $reason === 'location_not_available') $out['error'] = 'location';
        elseif ($integrity === 'suspicious') $out['error'] = 'suspicious';
        elseif ($status !== 'inside') $out['error'] = 'outside';
        return $out;
    }
}
