<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** The notifications of the correction requests (3.31.143). */
interface CorrectionNotices
{
    /** A request to review, to someone who may decide it. */
    public function decider(int $userId, ?object $request): void;

    /** "You did not sign out today", with a link to the correction form for the day. $signedIn: the Sign In (Y-m-d H:i:s). */
    public function signOutReminder(int $userId, string $date, string $signedIn): void;
}
