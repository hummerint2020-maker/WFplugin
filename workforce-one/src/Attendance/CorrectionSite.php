<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * What the correction requests reach in the plugin (3.31.143, docs/tasks/attendance-corrections.md §11): the feature switch, the settings, the
 * schema, the facts of a request (they stay in the plugin until L5e3), the words of a change, the people who manage time, the site's clock as
 * the old code read it, the reminder's claim and today's working hours. Built once, in includes/trait-services.php.
 */
interface CorrectionSite
{
    /** Feature Configuration → Attendance Corrections. */
    public function enabled(): bool;

    /** The ews_correction_settings, with the built-in values for anything missing. @return array<string,mixed> */
    public function settings(): array;

    /** Creates or updates the corrections table (dbDelta, every time it is called). */
    public function ensureSchema(): void;

    /** The same, once per request unless the schema is current. */
    public function ensureSchemaOnce(): void;

    /**
     * The facts CorrectionRules checks a request against.
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function facts(object $employee, array $in, string $source, bool $hasPhoto): array;

    /** The change a request makes, in words ('en': the Audit Log's English). */
    public function changeText(?object $request, string $lang = ''): string;

    /** @return list<int> the users who may Manage Time (told of a request when no workflow is set) */
    public function timeManagerIds(): array;

    /** The site's date today (Y-m-d). */
    public function today(): string;

    /** The site's time now (H:i). */
    public function hm(): string;

    /** The day the end-of-day reminder last went ('' when never). */
    public function remindedOn(): string;

    /** Notes that the reminder went today. */
    public function markReminded(string $day): void;

    /** The employee's working hours today (start, end, overnight, ...). @return array<string,mixed> */
    public function workingHoursToday(int $employeeId): array;
}
