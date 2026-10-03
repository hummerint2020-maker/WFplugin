<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * The approval request's states. A request waits at one level at a time (its step is PENDING, the
 * later ones WAITING); an approval moves it to the next level or approves it; a rejection ends it.
 * Pure.
 */
final class StateMachine
{
    /** Status of a request when it starts. */
    public static function initialStatus(string $family): string
    {
        return $family === 'PEER' ? 'WAITING_FOR_PEER' : 'WAITING_FOR_LEVEL_1';
    }

    /** Status of a step when the request starts: the first one acts now; a step with no approver is skipped. */
    public static function stepStatus(int $stepOrder, bool $hasApprover): string
    {
        if (!$hasApprover) return 'SKIPPED';
        return $stepOrder === 1 ? 'PENDING' : 'WAITING';
    }

    /**
     * The request after a decision on its pending step.
     * @param int|null $nextWaiting the next WAITING step's order, if any
     * @return array{step:string,request:string,current_step:?int,completed:bool}
     */
    public static function afterDecision(string $decision, ?int $nextWaiting): array
    {
        if ($decision === 'reject') return ['step' => 'REJECTED', 'request' => 'REJECTED', 'current_step' => null, 'completed' => true];
        if ($nextWaiting !== null) return ['step' => 'APPROVED', 'request' => 'WAITING_FOR_LEVEL_' . $nextWaiting, 'current_step' => $nextWaiting, 'completed' => false];
        return ['step' => 'APPROVED', 'request' => 'APPROVED', 'current_step' => null, 'completed' => true];
    }
}
