<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Which branch (work location) a Sign In is checked against (3.31.72). Pure: no WordPress calls.
 * See Settings\BranchSettings for the three modes.
 */
final class BranchRules
{
    /** At another of the employee's branches than the one planned for the day ("By the schedule"). */
    public const OTHER = 'other_branch';

    /**
     * The branch expected today and the branches accepted today, ids only (0 = none / the default
     * location). The expected branch is always first in the accepted list.
     *
     * @param int[] $others the employee's other branches
     * @return array{expected:int, allowed:int[], flag_other:bool}
     */
    public static function candidates(string $mode, int $main, array $others, int $planned, bool $allowOthers): array
    {
        $others = array_values(array_filter(array_map('intval', $others), static function ($id) use ($main) { return $id > 0 && $id !== $main; }));
        if ($mode === 'any') return ['expected' => $main, 'allowed' => self::unique(array_merge([$main], $others)), 'flag_other' => false];
        if ($mode === 'schedule') {
            $expected = $planned > 0 ? $planned : $main;
            $allowed = $allowOthers ? self::unique(array_merge([$expected, $main], $others)) : [$expected];
            return ['expected' => $expected, 'allowed' => $allowed, 'flag_other' => true];
        }
        return ['expected' => $main, 'allowed' => [$main], 'flag_other' => false];
    }

    /**
     * The branch the employee is at: the expected one when they are inside it, else the nearest
     * accepted branch they are inside, else the expected one (whose geofence then decides as before).
     *
     * @param int[] $allowed accepted branch ids, expected first
     * @param array<int,array{distance:float|null,radius:float}> $sites per branch id
     * @return array{0:int,1:string} [branch id, flag ('' or OTHER)]
     */
    public static function pick(array $allowed, int $expected, array $sites, bool $flagOther): array
    {
        $inside = static function (int $id) use ($sites): ?float {
            $s = $sites[$id] ?? null;
            if (!$s || $s['distance'] === null) return null;
            return $s['distance'] <= $s['radius'] ? (float) $s['distance'] : null;
        };
        if ($inside($expected) !== null) return [$expected, ''];
        $best = 0;
        $bestDistance = null;
        foreach ($allowed as $id) {
            $id = (int) $id;
            if ($id === $expected) continue;
            $d = $inside($id);
            if ($d !== null && ($bestDistance === null || $d < $bestDistance)) { $best = $id; $bestDistance = $d; }
        }
        if ($best) return [$best, $flagOther ? self::OTHER : ''];
        return [$expected, ''];
    }

    /** May a kiosk at this branch take this employee's Sign In today? */
    public static function kioskAllowed(int $kioskBranch, array $allowed, bool $kioskAny, int $expected): bool
    {
        if ($kioskAny) return in_array($kioskBranch, array_map('intval', $allowed), true);
        return $kioskBranch === $expected;
    }

    /** @param int[] $ids @return int[] */
    private static function unique(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) if (!in_array((int) $id, $out, true)) $out[] = (int) $id;
        return $out;
    }
}
