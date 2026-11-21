<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/**
 * Leave in My requests (3.31.158, docs/tasks/leave-in-requests.md). Pure: no WordPress calls.
 * - itemState(): what My requests shows for one leave request (its state, a note, whether it may be cancelled);
 * - pageTarget(): where the Leave page's links go while it is hidden (never back to the Leave page).
 */
final class MyRequestsLeave
{
    /**
     * @return array{state:string,cancel_asked:bool,cancel_decided:string,cancellable:bool,started:bool}
     *   state: waiting | approved | rejected | withdrawn | cancelled; cancel_decided: '' | 'approved' | 'rejected'
     */
    public static function itemState(string $status, ?string $cancellation, ?string $start, ?string $end, string $today): array
    {
        $c = strtolower(trim((string) $cancellation));
        $asked = $status === 'Approved' && $c === 'pending';
        if ($asked) $state = 'waiting';
        elseif ($status === 'Pending') $state = 'waiting';
        elseif ($status === 'Approved') $state = 'approved';
        elseif ($status === 'Rejected') $state = 'rejected';
        elseif ($status === 'Cancelled') $state = $c === 'approved' ? 'cancelled' : 'withdrawn';
        else $state = 'cancelled';
        $decided = $status === 'Cancelled' && $c === 'approved' ? 'approved' : ($status === 'Approved' && $c === 'rejected' ? 'rejected' : '');
        $started = $status === 'Approved' && !empty($start) && $today >= (string) $start && (empty($end) || $today <= (string) $end);
        return ['state' => $state, 'cancel_asked' => $asked, 'cancel_decided' => $decided,
            'cancellable' => CancellationRules::check($status, $cancellation, $start, $today) === null, 'started' => $started];
    }

    /**
     * The view the Leave page's links open while it is hidden: My approvals for a decision (or when My requests is not
     * there), else My requests, else the dashboard.
     */
    public static function pageTarget(bool $shown, bool $requestsAllowed, bool $approvalsAllowed, bool $isDecision): string
    {
        if ($shown) return 'vacation';
        if ($isDecision && $approvalsAllowed) return 'approvals';
        if ($requestsAllowed) return 'requests';
        if ($approvalsAllowed) return 'approvals';
        return 'dashboard';
    }
}
