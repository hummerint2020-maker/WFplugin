<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/** Values of the Notification Settings page. Pure: no WordPress calls. */
final class NotificationSettings
{
    /** Retention choices in days; 0 keeps notifications forever. */
    public const RETENTION_DAYS = [7, 30, 90, 180, 365, 0];
    public const DEFAULT_RETENTION = 90;

    public static function retention($days): int
    {
        $days = (int) $days;
        return in_array($days, self::RETENTION_DAYS, true) ? $days : self::DEFAULT_RETENTION;
    }

    /** A Web Push (VAPID) subject must be a mailto: address or an https URL; anything else is null. */
    public static function vapidSubject(string $subject): ?string
    {
        $subject = trim($subject);
        if (preg_match('/^mailto:[^@\s]+@[^@\s]+\.[^@\s]+$/', $subject)) return $subject;
        if (preg_match('#^https://[^\s/]+\.[^\s]+#', $subject)) return $subject;
        return null;
    }

    /**
     * @param mixed $posted policy[category][in_app|push|mandatory]
     * @param string[] $categories
     * @return array<string, array{in_app: int, push: int, mandatory: int}> mandatory push always pushes
     */
    public static function policy($posted, array $categories): array
    {
        $posted = is_array($posted) ? $posted : [];
        $out = [];
        foreach ($categories as $key) {
            $row = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : [];
            $mandatory = !empty($row['mandatory']) ? 1 : 0;
            $out[$key] = ['in_app' => !empty($row['in_app']) ? 1 : 0, 'push' => ($mandatory || !empty($row['push'])) ? 1 : 0, 'mandatory' => $mandatory];
        }
        return $out;
    }
}
