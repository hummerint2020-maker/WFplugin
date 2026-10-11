<?php
namespace WorkforceOne\Requests;

if (!defined('ABSPATH')) exit;

/**
 * Presentation rules of the wp-admin request pages (Requests Hub, Face Reset Requests).
 * Pure: no WordPress calls.
 */
final class Hub
{
    /** Request types the hub can decide; the value is the row title prefix. */
    public const TYPES = ['leave', 'leave_cancellation', 'overtime', 'early_leave', 'shift_swap', 'face_reset', 'legacy_vacation', 'correction'];

    /** Outcomes of a decision; also the ?request_notice= / ?face_reset_notice= values. */
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const ADVANCED = 'advanced';
    public const ERROR = 'error';

    public static function isDecision(string $decision): bool
    {
        return $decision === 'approve' || $decision === 'reject';
    }

    /** Outcome of a decision that completed (not moved to the next approval level). */
    public static function outcome(string $decision): string
    {
        return $decision === 'approve' ? self::APPROVED : self::REJECTED;
    }

    /** Outcome after the approval engine acted: final status, or ADVANCED while levels remain. */
    public static function workflowOutcome(?string $approvalStatus): string
    {
        $status = strtoupper((string) $approvalStatus);
        if ($status === 'APPROVED') return self::APPROVED;
        if ($status === 'REJECTED') return self::REJECTED;
        return self::ADVANCED;
    }

    /** True while an approval request still waits for a decision. */
    public static function isOpenApproval(?string $approvalStatus): bool
    {
        return $approvalStatus !== null && !in_array(strtoupper($approvalStatus), ['APPROVED', 'REJECTED'], true);
    }

    /** "Approval" column: workflow level, peer (swaps) or a direct decision. */
    public static function approvalLabel(string $type, int $approvalId, ?int $stepOrder, bool $assigned, bool $forAdministrators = false): string
    {
        if ($approvalId) {
            $label = 'Approval #' . $approvalId;
            if ($stepOrder !== null) $label .= ' · Level ' . $stepOrder . ($assigned ? ' · Assigned' : ($forAdministrators ? ' · For an administrator (the approver made the request)' : ''));
            return $label;
        }
        return $type === 'shift_swap' ? 'Peer' : 'Legacy / direct';
    }

    /** "2h 5m" for a number of minutes. */
    public static function duration(int $minutes): string
    {
        return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
    }

    /** Joins the non-empty parts of a details cell with " · ". */
    public static function details(string ...$parts): string
    {
        return implode(' · ', array_values(array_filter($parts, static function ($p) { return $p !== ''; })));
    }

    /**
     * Oldest first, so the requests waiting longest are on top.
     * @param array<int, array{requested_at: string}> $rows
     * @return array<int, array{requested_at: string}>
     */
    public static function sortOldestFirst(array $rows): array
    {
        usort($rows, static function ($a, $b) { return strcmp((string) $a['requested_at'], (string) $b['requested_at']); });
        return $rows;
    }

    /** Notice shown after a hub decision, or null for an unknown key. */
    public static function hubNotice(string $key): ?string
    {
        $messages = [
            self::APPROVED => 'Request approved successfully.',
            self::REJECTED => 'Request rejected successfully.',
            self::ADVANCED => 'Approval recorded and moved to the next level.',
            self::ERROR => 'The request could not be processed.',
        ];
        return $messages[$key] ?? null;
    }

    /** Notice shown after a Face Reset page decision, or null for an unknown key. */
    public static function faceResetNotice(string $key): ?string
    {
        $messages = [
            self::APPROVED => 'Face reset approved. The employee can now enroll a new face.',
            self::REJECTED => 'Face reset request rejected. The current enrollment remains active.',
            self::ADVANCED => 'Approval recorded and moved to the next level.',
            self::ERROR => 'The face reset request could not be processed.',
        ];
        return $messages[$key] ?? null;
    }
}
