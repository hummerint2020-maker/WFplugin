<?php
namespace WorkforceOne\Locations;

if (!defined('ABSPATH')) exit;

/** Work location checks (wp-admin → Work Locations). Pure: no WordPress calls. */
final class LocationRules
{
    public const MIN_RADIUS = 10;
    public const MAX_RADIUS = 5000;

    /** A location needs a name and coordinates on the globe. */
    public static function valid(string $name, string $lat, string $lng): bool
    {
        return $name !== '' && is_numeric($lat) && is_numeric($lng) && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lng >= -180 && (float) $lng <= 180;
    }

    /** The allowed Sign In radius in metres, kept within 10–5,000. */
    public static function radius(int $metres): int
    {
        return max(self::MIN_RADIUS, min(self::MAX_RADIUS, $metres));
    }

    /** Seats: how many people the location holds; null (0 or empty) = no limit. */
    public static function seats(int $seats): ?int
    {
        return $seats > 0 ? $seats : null;
    }

    /** Whether a saved location is the default: when asked, or when it is the first one added. */
    public static function isDefault(bool $makeDefault, bool $isNew, int $activeLocations): bool
    {
        return $makeDefault || ($isNew && $activeLocations === 0);
    }
}
