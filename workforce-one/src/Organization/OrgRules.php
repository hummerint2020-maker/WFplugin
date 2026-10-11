<?php
namespace WorkforceOne\Organization;

if (!defined('ABSPATH')) exit;

/**
 * Departments and teams (wp-admin → Departments, Teams): the checks on a save or an archive and their
 * messages. The caller looks the facts up; these decide. Pure: no WordPress calls.
 */
final class OrgRules
{
    public const MESSAGES = [
        'department' => [
            'required' => 'Name and code are required.',
            'duplicate' => 'Department name or code already exists.',
            'duplicate_archived' => 'Department name or code already exists in an archived department; archived names and codes stay reserved.',
            'manager_outside' => 'Manager must belong to this department.',
            'manager_new' => 'Assign the manager after the department is created.',
            'has_employees' => 'Department still has active employees.',
            'has_teams' => 'Department still has active teams.',
            'save' => 'Could not save the department.',
        ],
        'team' => [
            'required' => 'Team name, department and manager are required.',
            'department' => 'Choose an active department.',
            'manager_outside' => 'The team manager must belong to the team\'s department.',
            'members_outside' => 'Every member must belong to the team\'s department.',
            'duplicate' => 'A team with this name already exists.',
            'duplicate_archived' => 'An archived team already uses this name; archived team names stay reserved.',
            'save' => 'Could not save the team.',
        ],
    ];

    /**
     * @param array{name:string,code:string,is_new:bool,manager:int,manager_in_department:bool,duplicate:?string} $f
     *        duplicate: null, 'active' or 'archived' (another department with this name or code)
     * @return string an error code of MESSAGES['department'], '' when it may be saved
     */
    public static function departmentError(array $f): string
    {
        if ($f['name'] === '' || $f['code'] === '') return 'required';
        if ($f['duplicate'] !== null) return $f['duplicate'] === 'archived' ? 'duplicate_archived' : 'duplicate';
        if ($f['manager'] > 0 && !$f['manager_in_department']) return $f['is_new'] ? 'manager_new' : 'manager_outside';
        return '';
    }

    /** A department may be archived only when nothing active is left in it. */
    public static function departmentArchiveError(int $activeEmployees, int $activeTeams): string
    {
        if ($activeEmployees > 0) return 'has_employees';
        return $activeTeams > 0 ? 'has_teams' : '';
    }

    /**
     * @param array{name:string,department:int,manager:int,department_active:bool,manager_in_department:bool,members_in_department:bool,duplicate:?string} $f
     * @return string an error code of MESSAGES['team'], '' when it may be saved
     */
    public static function teamError(array $f): string
    {
        if ($f['name'] === '' || $f['department'] <= 0 || $f['manager'] <= 0) return 'required';
        if (!$f['department_active']) return 'department';
        if (!$f['manager_in_department']) return 'manager_outside';
        if (!$f['members_in_department']) return 'members_outside';
        if ($f['duplicate'] !== null) return $f['duplicate'] === 'archived' ? 'duplicate_archived' : 'duplicate';
        return '';
    }

    /** The message for a code in a page link ('' for an unknown code, never the link's own text). */
    public static function message(string $kind, string $code): string
    {
        if ($code === '1') $code = 'save'; // older links carried team_error=1
        return self::MESSAGES[$kind][$code] ?? '';
    }

    /** null, 'active' or 'archived': the best match among other rows with the same name / code. @param array<int,int|string> $activeFlags */
    public static function duplicate(array $activeFlags): ?string
    {
        if (!$activeFlags) return null;
        foreach ($activeFlags as $a) if ((int) $a === 1) return 'active';
        return 'archived';
    }
}
