<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Validates and normalises the Schedule Configuration settings (working hours, working days,
 * shifts, schedule types). Pure: no WordPress calls. Error codes are the ?schedule_error= values.
 */
final class ConfigRules
{
    /** Types the plugin itself relies on (leave, swaps, early leave, attendance): never renamed or removed. */
    public const CORE_TYPES = ['Office', 'WFH', 'Vacation'];
    public const ATTENDANCE_RULES = ['attendance', 'leave', 'business_trip'];

    private const STYLE_DEFAULTS = [
        'Office' => ['icon' => '🏢', 'bg_color' => '#dcfce7', 'text_color' => '#166534', 'border_color' => '#bbf7d0'],
        'WFH' => ['icon' => '🏠', 'bg_color' => '#dbeafe', 'text_color' => '#1d4ed8', 'border_color' => '#bfdbfe'],
        'Vacation' => ['icon' => '🌴', 'bg_color' => '#fef3c7', 'text_color' => '#92400e', 'border_color' => '#fde68a'],
        'Business Trip' => ['icon' => '🚗', 'bg_color' => '#ede9fe', 'text_color' => '#6d28d9', 'border_color' => '#ddd6fe'],
        'Training Course' => ['icon' => '🎓', 'bg_color' => '#e0f2fe', 'text_color' => '#0369a1', 'border_color' => '#bae6fd'],
    ];
    private const STYLE_FALLBACK = ['icon' => '•', 'bg_color' => '#f2f4f7', 'text_color' => '#667085', 'border_color' => '#e5e7eb'];

    public static function isTime($value): bool
    {
        return is_string($value) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /** A day (or shift) that ends before it starts crosses midnight and needs overnight mode. */
    public static function hoursError($start, $end, bool $overnight): ?string
    {
        if (!self::isTime($start) || !self::isTime($end)) return 'hours_invalid';
        if ($start === $end) return 'hours_same';
        if ($end < $start && !$overnight) return 'hours_overnight';
        return null;
    }

    public static function graceMinutes($value): int
    {
        return max(0, min(180, (int) $value));
    }

    /** Minutes after a shift's start when a no-show counts as Absent today: 15 to 720. @param mixed $value */
    public static function absentAfterMinutes($value): int
    {
        return max(15, min(720, (int) $value));
    }

    /** @return int[] WordPress weekdays (0 = Sunday), unique and sorted. */
    public static function workingDays($raw): array
    {
        $days = [];
        foreach ((array) $raw as $day) {
            if (is_scalar($day) && ctype_digit((string) $day) && (int) $day <= 6) $days[(int) $day] = (int) $day;
        }
        sort($days, SORT_NUMERIC);
        return array_values($days);
    }

    /**
     * Posted shift rows (a row with an empty name is removed) plus an optional new one. A new shift
     * gets an id above every id ever handed out ($highestId), so employees whose default shift was
     * removed are never moved onto it.
     * @return array{0: array<int, array<string, mixed>>, 1: ?string} [shifts, error code]
     */
    public static function shifts($rows, $new, int $highestId): array
    {
        $clean = [];
        $names = [];
        $add = static function (array $s, int $id) use (&$clean, &$names): ?string {
            $name = trim((string) ($s['name'] ?? ''));
            $start = (string) ($s['start'] ?? '');
            $end = (string) ($s['end'] ?? '');
            $overnight = !empty($s['overnight']);
            if (!self::isTime($start) || !self::isTime($end) || $start === $end) return 'shift_invalid';
            if ($end < $start && !$overnight) return 'shift_overnight';
            $key = strtolower($name);
            if (isset($names[$key])) return 'shift_duplicate';
            $names[$key] = true;
            $clean[] = ['id' => $id, 'name' => $name, 'start' => $start, 'end' => $end,
                'grace' => self::graceMinutes($s['grace'] ?? 10),
                'sign_in_cutoff_minutes' => max(0, min(1440, (int) ($s['sign_in_cutoff_minutes'] ?? 240))),
                'overnight' => $overnight ? 1 : 0, 'active' => !empty($s['active']) ? 1 : 0];
            return null;
        };
        foreach ((array) $rows as $s) {
            if (!is_array($s) || trim((string) ($s['name'] ?? '')) === '') continue;
            $id = (int) ($s['id'] ?? 0);
            if ($id < 1) return [[], 'shift_invalid'];
            $highestId = max($highestId, $id);
            if ($error = $add($s, $id)) return [[], $error];
        }
        if (is_array($new) && trim((string) ($new['name'] ?? '')) !== '') {
            if ($error = $add($new, $highestId + 1)) return [[], $error];
        }
        return $clean ? [$clean, null] : [[], 'shifts_empty'];
    }

    /**
     * Posted schedule types, keyed like the form: the index of the stored type, or "new".
     * A row with an empty name removes that type.
     * @param array<int, array<string, mixed>> $previous stored types (name, …) in form order
     * @param string[] $usedNames statuses present in the schedule
     * @return array{0: array<int, array<string, mixed>>, 1: ?string} [types, error code]
     */
    public static function types($posted, array $previous, array $usedNames): array
    {
        $posted = is_array($posted) ? $posted : [];
        foreach ($previous as $i => $old) {
            $oldName = (string) ($old['name'] ?? '');
            $row = $posted[$i] ?? [];
            $newName = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
            if ($newName === $oldName) continue;
            if (in_array($oldName, self::CORE_TYPES, true)) return [[], 'type_core'];
            if (in_array($oldName, $usedNames, true)) return [[], 'type_in_use'];
        }
        $clean = [];
        $names = [];
        foreach ($posted as $key => $row) {
            if (!is_array($row)) continue;
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') continue;
            if ($key !== 'new' && !isset($previous[$key])) continue;
            if (isset($names[strtolower($name)])) return [[], 'type_duplicate'];
            $names[strtolower($name)] = true;
            $rule = (string) ($row['attendance_rule'] ?? 'attendance');
            $sd = self::STYLE_DEFAULTS[$name] ?? self::STYLE_FALLBACK;
            $icon = trim((string) ($row['icon'] ?? ''));
            $clean[] = [
                'name' => $name,
                'requires_sign_in' => !empty($row['requires_sign_in']) ? 1 : 0,
                'requires_location' => !empty($row['requires_location']) ? 1 : 0,
                'attendance_rule' => in_array($rule, self::ATTENDANCE_RULES, true) ? $rule : 'attendance',
                'active' => !empty($row['active']) ? 1 : 0,
                'icon' => $icon !== '' ? $icon : $sd['icon'],
                'bg_color' => self::hex($row['bg_color'] ?? '', $sd['bg_color']),
                'text_color' => self::hex($row['text_color'] ?? '', $sd['text_color']),
                'border_color' => self::hex($row['border_color'] ?? '', $sd['border_color']),
            ];
        }
        return $clean ? [$clean, null] : [[], 'types_empty'];
    }

    private static function hex($value, string $fallback): string
    {
        $value = (string) $value;
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
    }

    public static function errorMessage(string $code): string
    {
        $messages = [
            'hours_invalid' => 'Enter Work Start and Work End as HH:MM.',
            'hours_same' => 'Work Start and Work End cannot be the same.',
            'hours_overnight' => 'Work End is before Work Start. Turn on "Allow Overnight Shift" if the workday crosses midnight.',
            'days_empty' => 'Select at least one working day.',
            'shift_invalid' => 'Each shift needs a valid start and end time (HH:MM) that are not the same.',
            'shift_overnight' => 'A shift that ends before it starts crosses midnight: tick Overnight for it.',
            'shift_duplicate' => 'Two shifts cannot have the same name.',
            'shifts_empty' => 'At least one Shift is required.',
            'types_empty' => 'At least one Schedule Type is required.',
            'type_duplicate' => 'Two schedule types cannot have the same name.',
            'type_core' => 'Office, WFH and Vacation are built-in schedule types: they can be restyled or deactivated, but not renamed or removed.',
            'type_in_use' => 'This schedule type is used in employee schedules, so it cannot be renamed or removed. Deactivate it instead.',
            'holiday_invalid' => 'Enter a valid date and a title for the holiday.',
            'holiday_duplicate' => 'There is already a holiday on that date.',
        ];
        return $messages[$code] ?? 'The settings could not be saved.';
    }

    public static function noticeMessage(string $key): ?string
    {
        $messages = [
            'hours_saved' => 'Working hours saved.',
            'days_saved' => 'Working days saved.',
            'shifts_saved' => 'Shifts saved.',
            'types_saved' => 'Schedule types saved.',
            'holiday_added' => 'General Leave added.',
            'holiday_removed' => 'General Leave removed.',
        ];
        return $messages[$key] ?? null;
    }
}
