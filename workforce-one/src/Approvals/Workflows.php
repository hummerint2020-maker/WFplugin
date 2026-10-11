<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * Approval workflow settings (wp-admin → Approval Workflows): which workflows the modules use, the
 * modes each supports, and the checks on a saved configuration. Pure: no WordPress calls.
 *
 * Modes: NONE (no approval), LEVEL_1, LEVEL_2 (fixed levels), SEQUENTIAL (the configured levels in
 * order) and PEER (the request's target employee approves).
 */
final class Workflows
{
    public const MODES = ['NONE' => 'No approval', 'LEVEL_1' => 'Level 1 approval', 'LEVEL_2' => 'Level 1 + Level 2 approval', 'SEQUENTIAL' => 'Sequential approval', 'PEER' => 'Peer approval'];

    /**
     * Workflows a module starts approval requests for: key => [label, modes the module handles].
     * Leave and Overtime act only on LEVEL_1 / LEVEL_2 (any other mode was ignored and managers
     * approved as with no approval); Face Reset runs whatever the engine runs.
     */
    public const IN_USE = [
        'vacation' => ['Vacation', ['NONE', 'LEVEL_1', 'LEVEL_2']],
        'overtime' => ['Overtime', ['NONE', 'LEVEL_1', 'LEVEL_2']],
        'face_reset' => ['Face Reset', ['NONE', 'LEVEL_1', 'LEVEL_2', 'SEQUENTIAL']],
        'attendance_correction' => ['Attendance Correction', ['NONE', 'LEVEL_1', 'LEVEL_2']],
        // 3.31.89: the colleague always accepts first; the levels, when chosen, decide after them.
        'shift_swap' => ['Shift Swap', ['PEER', 'LEVEL_1', 'LEVEL_2']],
    ];

    /** Mode names that read differently for one workflow. */
    public const MODE_LABELS = [
        'shift_swap' => ['PEER' => 'Colleague only', 'LEVEL_1' => 'Colleague, then Level 1', 'LEVEL_2' => 'Colleague, then Level 1 + Level 2'],
    ];

    /** What a mode does in one workflow, when it differs from the general help. */
    public const MODE_HELP = [
        'shift_swap' => [
            'PEER' => 'The colleague decides: the two days are swapped as soon as they accept.',
            'LEVEL_1' => 'After the colleague accepts, one approver decides; the days are swapped when they approve.',
            'LEVEL_2' => 'After the colleague accepts, two approvers decide in order; the days are swapped when the last one approves.',
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

    public const RESOLVERS = ['SUPERVISOR', 'TEAM_MANAGER', 'SPECIFIC_EMPLOYEE', 'SPECIFIC_USER'];

    public static function modeFamily(string $mode): string
    {
        $mode = strtoupper($mode);
        if (in_array($mode, ['LEVEL_1', 'LEVEL_2', 'SEQUENTIAL'], true)) return 'SEQUENTIAL';
        return $mode === 'PEER' ? 'PEER' : 'NONE';
    }

    /**
     * Approval levels a sequential mode uses (PEER: 1, NONE: 0).
     * @param array<int,array{resolver_type?:string,resolver_value?:int|string}> $configs level => config
     */
    public static function levelCount(string $mode, array $configs): int
    {
        $mode = strtoupper($mode);
        if ($mode === 'LEVEL_1' || $mode === 'PEER') return 1;
        if ($mode === 'LEVEL_2') return 2;
        if ($mode !== 'SEQUENTIAL') return 0;
        $count = 0;
        foreach ([1, 2] as $i) if (!empty($configs[$i]['resolver_type'])) $count = $i;
        return $count;
    }

    /**
     * Check a configuration and turn it into the workflow's steps.
     * @param array<int,array{resolver_type?:string,resolver_value?:int|string}> $configs level => config
     * @return array{ok:bool,code?:string,message?:string,steps?:array<int,array{step_order:int,step_type:string,resolver_type:string,resolver_value:?string}>}
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
            $steps[] = ['step_order' => $i, 'step_type' => $family === 'PEER' ? 'PEER' : 'APPROVAL', 'resolver_type' => $resolver, 'resolver_value' => $specific ? (string) $value : null];
        }
        return ['ok' => true, 'steps' => $steps];
    }
}
