<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/**
 * The org-moves history (ews_org_moves, 3.31.134): every move of a person under a manager, done, scheduled, failed or cancelled. Same SQL as before;
 * raw wpdb values (false on a database error, counts of changed rows). A scheduled move is claimed with conditional UPDATEs (status and claim time).
 */
interface OrgMoveStore
{
    /** Is the table there (SHOW TABLES LIKE)? */
    public function tableExists(): bool;

    /** The id of a scheduled or applying move of this employee other than $ignoreId, or null. */
    public function openIdFor(int $employeeId, int $ignoreId): ?string;

    /** Adds a row. @param array<string,mixed> $row @return int|false */
    public function insert(array $row);

    /** The id of the row the last insert made. */
    public function insertId(): int;

    /** Writes the done row over a claimed scheduled move, only while the claim is still $claimAt. @param array<string,mixed> $row @return int|false rows changed */
    public function finishClaimed(int $id, string $claimAt, array $row);

    /** The scheduled moves whose day has come, oldest first (at most 200). @return list<object> */
    public function dueRows(string $today): array;

    /** scheduled → applying with this claim time. @return int|false 1 when this run took it */
    public function claim(int $id, string $claimAt);

    /** applying → done ("already in place"), only while the claim is still $claimAt. @return int|false */
    public function markAlreadyDone(int $id, string $claimAt, string $now);

    /** applying → scheduled again (nothing was changed), only while the claim is still $claimAt. @return int|false */
    public function release(int $id, string $claimAt);

    /** applying → failed with the reason; $claimAt null: any claim. @return int|false 1 when it was changed */
    public function fail(int $id, ?string $claimAt, string $code, string $now);

    /** The moves left applying, oldest first (at most 200). @return list<object> */
    public function applyingRows(): array;

    /** applying → scheduled ("resumed"); $appliedAt null: a move claimed with no claim time. @return int|false */
    public function resume(int $id, ?string $appliedAt);

    /** applying → failed ("interrupted"); $appliedAt null: a move claimed with no claim time. @return int|false */
    public function failInterrupted(int $id, ?string $appliedAt, string $now);

    public function byId(int $id): ?object;

    /** scheduled or failed → cancelled, only while it still has $status. @return int|false */
    public function cancel(int $id, string $status, string $now);

    /** The newest done moves (at most 2000). @return list<object> */
    public function doneRows(): array;

    /** The scheduled and applying moves by day (at most 500). @return list<object> */
    public function openRows(): array;

    /** The newest failed moves (at most 100). @return list<object> */
    public function failedRows(): array;
}
