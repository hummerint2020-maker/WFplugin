<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/** Employee Moments: stored dates and which celebrations apply on a day. Pure: no WordPress calls. */
final class Moments
{
    public static function validDate(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return false;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Merges the posted dates into the stored ones. Employees not on the form (e.g. archived)
     * keep their dates. A birthday must be a real date that is not in the future.
     * @param mixed $posted moments[employee_id][birthday|join_date]
     * @param array<int|string, mixed> $stored
     * @param int[] $formIds employees shown on the form
     * @return array{0: array<int, array{birthday: string, join_date: string}>, 1: ?string}
     */
    public static function merge($posted, array $stored, array $formIds, string $today): array
    {
        $posted = is_array($posted) ? $posted : [];
        $out = [];
        foreach ($stored as $id => $row) {
            if (is_array($row) && !in_array((int) $id, $formIds, true)) $out[(int) $id] = ['birthday' => (string) ($row['birthday'] ?? ''), 'join_date' => (string) ($row['join_date'] ?? '')];
        }
        foreach ($formIds as $id) {
            $row = isset($posted[$id]) && is_array($posted[$id]) ? $posted[$id] : [];
            $birthday = trim((string) ($row['birthday'] ?? ''));
            $join = trim((string) ($row['join_date'] ?? ''));
            if (($birthday !== '' && (!self::validDate($birthday) || $birthday > $today)) || ($join !== '' && !self::validDate($join))) return [[], 'date'];
            if ($birthday !== '' || $join !== '') $out[$id] = ['birthday' => $birthday, 'join_date' => $join];
        }
        ksort($out);
        return [$out, null];
    }

    /**
     * Celebrations on $today: birthday (Feb 29 birthdays are celebrated on Feb 28 in other years),
     * work anniversary (years > 0) and the first 30 days after joining.
     * @return array<int, array{type: string, years?: int}>
     */
    public static function forDate(string $birthday, string $join, string $today): array
    {
        $out = [];
        $md = substr($today, 5, 5);
        $leap = checkdate(2, 29, (int) substr($today, 0, 4));
        $same = static function (string $date) use ($md, $leap): bool {
            $d = substr($date, 5, 5);
            return $d === $md || ($d === '02-29' && !$leap && $md === '02-28');
        };
        if ($birthday !== '' && self::validDate($birthday) && $same($birthday)) $out[] = ['type' => 'birthday'];
        if ($join !== '' && self::validDate($join)) {
            $years = (int) substr($today, 0, 4) - (int) substr($join, 0, 4);
            if ($years > 0 && $same($join)) $out[] = ['type' => 'anniversary', 'years' => $years];
            $days = (int) floor((strtotime($today . ' 00:00:00 UTC') - strtotime($join . ' 00:00:00 UTC')) / 86400);
            if ($days >= 0 && $days < 30) $out[] = ['type' => 'welcome'];
        }
        return $out;
    }
}
