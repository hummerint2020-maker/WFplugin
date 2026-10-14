<?php
namespace WorkforceOne\Employees;

if (!defined('ABSPATH')) exit;

/**
 * Validates adding or updating an employee on the wp-admin Employees page. The caller gathers the
 * facts from the database; this class decides. Pure: no WordPress calls.
 * Error codes are the ?employee_error= values.
 */
final class EmployeeRules
{
    /**
     * @param array{
     *   id?: int, name: string, domain: string, email_valid: bool, department_ok: bool,
     *   domain_taken: bool, user_taken: bool, user_ok?: bool, supervisor_id: int, supervisor_ok?: bool,
     *   title_missing?: bool, supervisors_supervisor?: int, supervisor_reports_to_employee?: bool, teams_in_department?: bool, manages_team_elsewhere?: bool
     * } $f
     */
    public static function check(array $f): ?string
    {
        $id = (int) ($f['id'] ?? 0);
        if (trim($f['name']) === '' || trim($f['domain']) === '' || empty($f['email_valid'])) return 'required';
        if (empty($f['department_ok'])) return 'department';
        if (!empty($f['title_missing'])) return 'title';
        if (!empty($f['domain_taken'])) return 'domain_taken';
        if (array_key_exists('user_ok', $f) && !$f['user_ok']) return 'user';
        if (!empty($f['user_taken'])) return 'user_taken';
        if ($f['supervisor_id']) {
            if ($id && $f['supervisor_id'] === $id) return 'self_supervisor';
            if (array_key_exists('supervisor_ok', $f) && !$f['supervisor_ok']) return 'supervisor';
            // Two people supervising each other, or (3.31.97) the new supervisor reports to this employee at any level.
            if ($id && ((int) ($f['supervisors_supervisor'] ?? 0) === $id || !empty($f['supervisor_reports_to_employee']))) return 'supervisor_loop';
        }
        if (array_key_exists('teams_in_department', $f) && !$f['teams_in_department']) return 'team_department';
        if (!empty($f['manages_team_elsewhere'])) return 'manages_team';
        return null;
    }

    public static function errorMessage(string $code): string
    {
        $messages = [
            'required' => 'Name, Domain Name and a valid Email Address are required.',
            'department' => 'Select an active Department.',
            'title' => 'A job title is required (Feature Configuration → Organization chart).',
            'domain_taken' => 'That Domain Name is already used by another employee.',
            'user' => 'Pick the WordPress user from the list.',
            'user_taken' => 'That WordPress user is already linked to another employee. Each user can be linked to one employee only.',
            'self_supervisor' => 'An employee cannot be their own supervisor.',
            'supervisor' => 'The selected supervisor is not an active employee. Pick the supervisor from the list.',
            'supervisor_loop' => 'Two employees cannot supervise each other, and nobody can be put under someone who reports to them.',
            'team_department' => 'Selected teams must belong to the employee\'s Department.',
            'manages_team' => 'This employee manages an active team in their current Department. Reassign that Team Manager first.',
            'not_found' => 'Employee not found.',
            'save' => 'The employee could not be saved. Please try again.',
            'teams' => 'Employee saved, but team membership could not be updated.',
        ];
        return $messages[$code] ?? 'The employee could not be saved.';
    }

    public static function noticeMessage(string $key): ?string
    {
        $messages = ['added' => 'Employee added.', 'updated' => 'Employee updated.', 'archived' => 'Employee archived.'];
        return $messages[$key] ?? null;
    }
}
