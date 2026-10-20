<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Where an employee is in today's work day, as facts (AttendanceService::today()): read-only, no WordPress calls.
 * The keys are those AttendanceService::today() sets; a key it did not set reads as null.
 */
final class TodayState
{
    /** @var array<string,mixed> */
    private $facts;

    /** @param array<string,mixed> $facts */
    public function __construct(array $facts)
    {
        $this->facts = $facts;
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->facts[$key] ?? null;
    }

    public function hasEmployee(): bool
    {
        return !empty($this->facts['employee']);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->facts;
    }
}
