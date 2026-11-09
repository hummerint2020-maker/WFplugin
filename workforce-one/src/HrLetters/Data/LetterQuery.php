<?php
namespace WorkforceOne\HrLetters\Data;

if (!defined('ABSPATH')) exit;

/** The HR letters' reads joined with the employees (HL3): the facts a letter is made from, and HR's lists. Writes nothing. */
interface LetterQuery
{
    /** The letter with its employee (name, name_ar, gender, hire_date, created_at, job_title, active, wp_user_id) and department_name. */
    public function facts(int $id): ?object;

    /** @return array<string,int> status => how many */
    public function counts(): array;

    /**
     * HR's list, newest first, with employee_name and employee_active.
     * @param string $status a status, 'waiting_file' (approved, upload or Other, no file), or '' for all
     * @return array{rows:object[],total:int}
     */
    public function adminList(string $status, string $search, int $page, int $perPage): array;

    /** @return object[] the letters waiting for a decision, oldest first, with employee_name (the Requests Hub) */
    public function waiting(): array;
}
