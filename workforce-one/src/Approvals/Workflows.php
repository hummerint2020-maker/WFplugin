<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * Approval workflow settings (wp-admin → Approval Workflows): which workflows the modules use, the
 * modes each supports, and the checks on a saved configuration. Pure: no WordPress calls.
 *
 * Modes: NONE (no approval), LEVEL_1 … LEVEL_4 (fixed levels; 3.31.100 added the third and fourth,
 * owner decisions 2026-10-14: three by default, four at most), SEQUENTIAL (the configured levels in
 * order) and PEER (the request's target employee approves).
 */
final class Workflows
{
    public const MODES = ['NONE' => 'No approval', 'LEVEL_1' => 'Level 1 approval', 'LEVEL_2' => 'Level 1 + Level 2 approval', 'LEVEL_3' => 'Levels 1, 2 and 3 approval', 'LEVEL_4' => 'Levels 1 to 4 approval', 'SEQUENTIAL' => 'Sequential approval', 'PEER' => 'Peer approval'];

    /** The most levels a workflow can have. */
    public const MAX_LEVELS = 4;

    /**
     * Workflows a module starts approval requests for: key => [label, modes the module handles].
     * Leave, Overtime and Corrections act only on the fixed levels (any other mode was ignored and
     * managers approved as with no approval); Face Reset runs whatever the engine runs.
     */
    public const IN_USE = [
        'vacation' => ['Vacation', ['NONE', 'LEVEL_1', 'LEVEL_2', 'LEVEL_3', 'LEVEL_4']],
        'overtime' => ['Overtime', ['NONE', 'LEVEL_1', 'LEVEL_2', 'LEVEL_3', 'LEVEL_4']],
        'face_reset' => ['Face Reset', ['NONE', 'LEVEL_1', 'LEVEL_2', 'LEVEL_3', 'LEVEL_4', 'SEQUENTIAL']],
        'attendance_correction' => ['Attendance Correction', ['NONE', 'LEVEL_1', 'LEVEL_2', 'LEVEL_3', 'LEVEL_4']],
        // 3.31.89: the colleague always accepts first; the levels, when chosen, decide after them.
        'shift_swap' => ['Shift Swap', ['PEER', 'LEVEL_1', 'LEVEL_2', 'LEVEL_3', 'LEVEL_4']],
    ];

    /** Mode names that read differently for one workflow. */
    public const MODE_LABELS = [
        'shift_swap' => ['PEER' => 'Colleague only', 'LEVEL_1' => 'Colleague, then Level 1', 'LEVEL_2' => 'Colleague, then Level 1 + Level 2', 'LEVEL_3' => 'Colleague, then Levels 1, 2 and 3', 'LEVEL_4' => 'Colleague, then Levels 1 to 4'],
    ];

    /** What a mode does in one workflow, when it differs from the general help. */
    public const MODE_HELP = [
        'shift_swap' => [
            'PEER' => 'The colleague decides: the two days are swapped as soon as they accept.',
            'LEVEL_1' => 'After the colleague accepts, one approver decides; the days are swapped when they approve.',
            'LEVEL_2' => 'After the colleague accepts, two approvers decide in order; the days are swapped when the last one approves.',
            'LEVEL_3' => 'After the colleague accepts, three approvers decide in order; the days are swapped when the last one approves.',
            'LEVEL_4' => 'After the colleague accepts, four approvers decide in order; the days are swapped when the last one approves.',
        ],
    ];

    /**
     * What happens while a workflow has never been saved (it is inactive): each module falls back to
     * its own rule, which is not always what "No approval" does once saved (3.31.89).
     */
    public const NOT_SET_MEANS = [
        'vacation' => 'Managers decide in the app and on the Requests Hub (the same as No approval).',
        'overtime' => 'Managers with Manage Time decide in the app and on the Requests Hub. Saved as No approval, requests are approved automatically.',
        'face_reset' => 'Users with Manage Settings decide on the Face Reset Requests page. Saved as No approval, requests are approved automatically.',
        'attendance_correction' => 'Managers with Manage Time decide in the app. Saved as No approval, requests are approved at once, except the cases that go to HR.',
        'shift_swap' => 'The colleague decides: the two days are swapped as soon as they accept (the same as Colleague only).',
    ];

    /** What "No approval" does in each module (they differ). */
    public const NONE_MEANS = [
        'vacation' => 'No approval chain: managers decide in the app or on the Requests page.',
        'overtime' => 'Requests are approved automatically.',
        'face_reset' => 'Requests are approved automatically.',
        'attendance_correction' => 'No manager level: requests are approved at once, or go straight to HR when they need the second level (a Sign In moved earlier, above the monthly limit).',
        'shift_swap' => '',
    ];

    /** Workflows seeded in the database that no module uses yet. */
    public const NOT_IN_USE = ['early_leave' => 'Early Leave'];

    /** Who approves a level. 3.31.100 added the manager's manager, the department manager, HR and Finance. */
    public const RESOLVERS = ['SUPERVISOR', 'MANAGERS_MANAGER', 'DEPARTMENT_MANAGER', 'TEAM_MANAGER', 'HR', 'FINANCE', 'SPECIFIC_EMPLOYEE', 'SPECIFIC_USER'];

    public const RESOLVER_LABELS = [
        'SUPERVISOR' => 'Direct manager', 'MANAGERS_MANAGER' => "Manager's manager", 'DEPARTMENT_MANAGER' => 'Department manager',
        'TEAM_MANAGER' => 'Team manager', 'HR' => 'HR', 'FINANCE' => 'Finance',
        'SPECIFIC_EMPLOYEE' => 'A specific employee', 'SPECIFIC_USER' => 'A specific WordPress user',
    ];

    /** What a level's condition counts, per workflow ("only if above N …"); the others have no amount. */
    public const AMOUNT_UNITS = ['vacation' => 'days', 'overtime' => 'hours'];

    /**
     * When the approver of a level is the person who made the request: they decide it (allow), it goes
     * to the next level (next; to the administrators when there is none) or to the administrators.
     */
    public const SELF_RULES = ['allow' => 'They decide it', 'next' => 'It goes to the next level', 'admins' => 'It goes to administrators'];

    /** The longest reminder / move-up time, in working hours. */
    public const MAX_DEADLINE_HOURS = 720;

    public static function modeFamily(string $mode): string
    {
        $mode = strtoupper($mode);
        if (self::isLevels($mode) || $mode === 'SEQUENTIAL') return 'SEQUENTIAL';
        return $mode === 'PEER' ? 'PEER' : 'NONE';
    }

    /** A fixed number of levels: LEVEL_1 … LEVEL_4. */
    public static function isLevels(string $mode): bool
    {
        return (bool) preg_match('/^LEVEL_[1-' . self::MAX_LEVELS . ']$/', strtoupper($mode));
    }

    /** A level's condition: only when the request's amount is above it (null = always). @param mixed $v */
    public static function minAmount($v): ?float
    {
        if (!is_numeric($v)) return null;
        $v = round((float) $v, 2);
        return $v > 0 ? $v : null;
    }

    /** Whether a level with this condition applies to a request of this amount (null amount: it applies). */
    public static function applies(?float $min, ?float $amount): bool
    {
        return $min === null || $amount === null || $amount > $min;
    }

    /**
     * Remind the approver, and move the request up, after these working hours (0 = never). A reminder
     * at or after the move up is dropped. @param mixed $remind @param mixed $escalate
     * @return array{remind:int,escalate:int}
     */
    public static function deadlines($remind, $escalate): array
    {
        $r = max(0, min(self::MAX_DEADLINE_HOURS, (int) $remind));
        $e = max(0, min(self::MAX_DEADLINE_HOURS, (int) $escalate));
        if ($e > 0 && $r >= $e) $r = 0;
        return ['remind' => $r, 'escalate' => $e];
    }

    public static function selfRule(string $rule): string
    {
        return isset(self::SELF_RULES[$rule]) ? $rule : 'allow';
    }

    /**
     * Approval levels a sequential mode uses (PEER: 1, NONE: 0).
     * @param array<int,array{resolver_type?:string,resolver_value?:int|string}> $configs level => config
     */
    public static function levelCount(string $mode, array $configs): int
    {
        $mode = strtoupper($mode);
        if ($mode === 'PEER') return 1;
        if (self::isLevels($mode)) return (int) substr($mode, 6);
        if ($mode !== 'SEQUENTIAL') return 0;
        $count = 0;
        for ($i = 1; $i <= self::MAX_LEVELS; $i++) if (!empty($configs[$i]['resolver_type'])) $count = $i;
        return $count;
    }

    /**
     * Check a configuration and turn it into the workflow's steps.
     * A level's min_amount is its condition (only when the request's amount is above it).
     * @param array<int,array{resolver_type?:string,resolver_value?:int|string,min_amount?:mixed}> $configs level => config
     * @return array{ok:bool,code?:string,message?:string,steps?:array<int,array{step_order:int,step_type:string,resolver_type:string,resolver_value:?string,min_amount:?float}>}
     */
    public static function plan(string $mode, array $configs, ?array $supported = null): array
    {
        $mode = strtoupper($mode);
        if (!isset(self::MODES[$mode])) return ['ok' => false, 'code' => 'invalid_mode', 'message' => 'Invalid approval mode: ' . $mode];
        if ($supported !== null && !in_array($mode, $supported, true)) {
            return ['ok' => false, 'code' => 'unsupported_mode', 'message' => 'This workflow supports: ' . implode(', ', array_map(static function ($m) { return self::MODES[$m]; }, $supported)) . '.'];
        }
        $family = self::modeFamily($mode);
        $steps = [];
        for ($i = 1, $n = self::levelCount($mode, $configs); $i <= $n; $i++) {
            $cfg = $configs[$i] ?? [];
            $resolver = strtoupper((string) ($cfg['resolver_type'] ?? ($family === 'PEER' ? 'TARGET_EMPLOYEE' : '')));
            $value = (int) ($cfg['resolver_value'] ?? 0);
            $allowed = $family === 'PEER' ? ['TARGET_EMPLOYEE'] : self::RESOLVERS;
            if (!in_array($resolver, $allowed, true)) return ['ok' => false, 'code' => 'invalid_resolver', 'message' => 'Invalid approver resolver for step ' . $i . ': ' . $resolver];
            $specific = in_array($resolver, ['SPECIFIC_EMPLOYEE', 'SPECIFIC_USER'], true);
            if ($specific && $value <= 0) return ['ok' => false, 'code' => 'missing_resolver_value', 'message' => 'A specific approver is required for step ' . $i . '.'];
            $steps[] = ['step_order' => $i, 'step_type' => $family === 'PEER' ? 'PEER' : 'APPROVAL', 'resolver_type' => $resolver, 'resolver_value' => $specific ? (string) $value : null,
                'min_amount' => $family === 'PEER' ? null : self::minAmount($cfg['min_amount'] ?? null)];
        }
        return ['ok' => true, 'steps' => $steps];
    }
}
