<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * A port: what Schedule tells people. The words, the links and the notification itself are the plugin's (the adapter),
 * so the services never build text or HTML.
 */
interface ScheduleNotifier
{
    /** "Schedule Updated": the person's day $date is now $status (row $scheduleId). */
    public function dayChanged(int $wpUserId, string $date, string $status, int $scheduleId): void;

    /** "Next week's schedule is final", with the person's days. @param array<string,string> $days date => status */
    public function weekFinal(int $wpUserId, array $days, string $link): void;
}
