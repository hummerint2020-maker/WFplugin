<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

final class Geo
{
    /**
     * Great-circle distance in metres, or null when any coordinate is missing/non-numeric.
     *
     * @param mixed $lat1
     * @param mixed $lon1
     * @param mixed $lat2
     * @param mixed $lon2
     */
    public static function distanceMeters($lat1, $lon1, $lat2, $lon2): ?float
    {
        if (!is_numeric($lat1) || !is_numeric($lon1) || !is_numeric($lat2) || !is_numeric($lon2)) {
            return null;
        }
        $earth = 6371000.0;
        $p1 = deg2rad((float) $lat1);
        $p2 = deg2rad((float) $lat2);
        $dp = deg2rad((float) $lat2 - (float) $lat1);
        $dl = deg2rad((float) $lon2 - (float) $lon1);
        $a = sin($dp / 2) * sin($dp / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);
        return 2 * $earth * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }
}
