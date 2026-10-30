<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** The Auto Attendance rules (ews_auto_attendance_rules; docs/tasks/attendance-auto-manual.md). No method computes a time: the caller gives it. */
interface AutoRuleStore
{
    /** @return object[] the enabled rules that can be due: weekly ones and one-time ones of today or yesterday, lowest id first */
    public function dueRules(string $today, string $yesterday): array;

    /** Switches a rule off (the result is not looked at). */
    public function disable(int $id, string $now): void;

    /** A rule's `enabled` flag (null when there is no such rule). */
    public function enabledOf(int $id): ?object;

    public function setEnabled(int $id, int $enabled, string $now): void;

    /** @param array<string,mixed> $row inserted as given (no formats) @return int the new id, 0 on failure */
    public function insert(array $row): int;

    /** Replaces the given columns of a rule (as given, no formats). False only on a database error (0 rows changed is not an error). @param array<string,mixed> $row */
    public function update(int $id, array $row): bool;

    /** Deletes a rule; the number of rows (0 when there was none). */
    public function remove(int $id): int;

    public function lastError(): string;
}
