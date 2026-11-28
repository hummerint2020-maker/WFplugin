<?php
namespace WorkforceOne\Leave\Data;

if (!defined('ABSPATH')) exit;

/** The report "Changed leave days" (Leave return): the past days of approved leaves whose schedule is no longer Vacation. */
interface ChangedDaysQuery
{
    /**
     * Days from $from to $to, before $today, newest first; $employee 0 = everyone.
     * @return array{rows:object[],total:int}
     */
    public function page(string $from, string $to, string $today, int $employee, int $limit, int $offset): array;
}
