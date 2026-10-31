<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** What the Sign In evidence reaches in the plugin (3.31.139): its settings, the schema check, the words for a distance, the message to the managers and the photo files. */
interface EvidenceSite
{
    /** The ews_signin_evidence settings: attempts, attempt_days, alert, photo, photo_days, photo_viewers. @return array<string,mixed> */
    public function settings(): array;

    /** Creates the attempts table (and the photo column) the first time this request needs it, unless the schema is current. */
    public function ensureSchemaOnce(): void;

    /** A distance in words: "850 m" or "1.20 km". */
    public function distance(float $meters): string;

    /** Tells the employee's department managers (the administrators when there is none) that the day's refusals reached N. */
    public function alert(object $employee, string $day, int $n): void;

    /** Removes a stored photo wherever it is. */
    public function deletePhoto(string $key): void;

    /** Removes the photo files nothing points to that are older than the Unix time. */
    public function sweepPhotos(int $olderThan): void;
}
