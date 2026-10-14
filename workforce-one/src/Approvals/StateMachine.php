<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * The approval request's states. A request waits at one level at a time (its step is PENDING, the
 * later ones WAITING); an approval moves it to the next level or approves it; a rejection ends it.
 * Levels whose condition does not apply, and (rule "next") levels whose approver is the requester,
 * are SKIPPED when the request starts (3.31.100). Pure.
 */
final class StateMachine
{
    /** Status of a request when it starts. */
    public static function initialStatus(string $family): string
    {
        return $family === 'PEER' ? 'WAITING_FOR_PEER' : 'WAITING_FOR_LEVEL_1';
    }

    /** A request waiting at one of its levels (WAITING_FOR_LEVEL_1 … _4). */
    public static function isWaitingForLevel(string $status): bool
    {
        return (bool) preg_match('/^WAITING_FOR_LEVEL_\d+$/', $status);
    }

    /**
     * The steps and the request when it starts.
     * Each step: skip 'condition' (its condition does not apply), 'self' (the approver is the requester
     * and the rule sends it to the next level) or '' (it applies). A 'self' step with no level acting
     * after it goes to the administrators instead (rerouted).
     * @param array<int,array{skip:string}> $steps step order => facts, in order
     * @return array{steps:array<int,array{status:string,rerouted:bool,skip:string}>,request:string,current_step:?int}
     */
    public static function start(array $steps, string $family = 'SEQUENTIAL'): array
    {
        ksort($steps);
        $out = [];
        $later = false;
        foreach (array_reverse(array_keys($steps)) as $order) {
            $skip = (string) ($steps[$order]['skip'] ?? '');
            $rerouted = false;
            if ($skip === 'self' && !$later) { $skip = ''; $rerouted = true; }
            $out[$order] = ['status' => $skip !== '' ? 'SKIPPED' : 'WAITING', 'rerouted' => $rerouted, 'skip' => $skip];
            if ($skip === '') $later = true;
        }
        ksort($out);
        $first = null;
        foreach ($out as $order => $s) {
            if ($s['status'] === 'WAITING') { $out[$order]['status'] = 'PENDING'; $first = $order; break; }
        }
        if ($first === null) return ['steps' => $out, 'request' => 'APPROVED', 'current_step' => null];
        return ['steps' => $out, 'request' => $family === 'PEER' ? 'WAITING_FOR_PEER' : 'WAITING_FOR_LEVEL_' . $first, 'current_step' => $first];
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
