<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** The stored results of requests that carried an idempotency key (the app's ews_api_idempotency table; docs/tasks/attendance-store.md). */
interface Idempotency
{
    /** @return array{request:string,response:array<string,mixed>|null}|null */
    public function find(int $userId, string $scope, string $keyHash): ?array;

    /** Records the key as in progress; false when it already exists. */
    public function claim(int $userId, string $scope, string $keyHash, string $requestHash): bool;

    /** @param array<string,mixed> $response */
    public function save(int $userId, string $scope, string $keyHash, array $response): void;

    public function release(int $userId, string $scope, string $keyHash): void;
}
