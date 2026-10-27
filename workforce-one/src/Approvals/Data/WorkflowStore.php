<?php
namespace WorkforceOne\Approvals\Data;

if (!defined('ABSPATH')) exit;

/** Where the approval workflows (and their levels) are kept (3.31.128). */
interface WorkflowStore
{
    /** A workflow by key, active or not. */
    public function byKey(string $key): ?object;

    /** A workflow by key, only when it is active. */
    public function activeByKey(string $key): ?object;

    /** The key of a workflow ('' when there is none). */
    public function keyById(int $id): string;

    /** The active levels of a workflow, in order. @return array<int,object> */
    public function activeSteps(int $workflowId): array;

    /** The id of a workflow by key, or null (the first-run seed). */
    public function idByKey(string $key): ?string;

    /** @param array<string,mixed> $row @param array<int,string> $formats */
    public function insertWorkflow(array $row, array $formats): void;

    /** Sets a workflow's mode and switches it on. @return int|false rows changed, false on a database error */
    public function setModeActive(int $id, string $mode, string $now);

    /** @return int|false */
    public function deleteSteps(int $workflowId);

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $formats
     * @return int|false
     */
    public function insertStep(array $row, array $formats);

    /** The database's last error message. */
    public function lastError(): string;
}
