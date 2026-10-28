<?php
namespace WorkforceOne\People;

use WorkforceOne\Organization\OrgRules;
use WorkforceOne\People\Data\DepartmentStore;
use WorkforceOne\People\Data\TeamStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;

if (!defined('ABSPATH')) exit;

/** The departments page's writes and reads (3.31.134, moved from trait-departments.php). No transaction (as before). */
final class DepartmentService
{
    /** @var DepartmentStore */
    private $store;
    /** @var TeamStore */
    private $teams;
    /** @var PeopleDirectory */
    private $people;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(DepartmentStore $store, TeamStore $teams, PeopleDirectory $people, AuditLog $audit, Clock $clock)
    {
        $this->store = $store;
        $this->teams = $teams;
        $this->people = $people;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /** The department being edited, or null. */
    public function byId(int $id): ?object
    {
        return $this->store->byId($id);
    }

    /** @return list<object> */
    public function adminList(): array
    {
        return $this->store->adminList();
    }

    /**
     * Creates or edits a department. The caller has cleaned the values.
     *
     * @return string '' when saved, else the error code (OrgRules::message('department', …))
     */
    public function save(int $id, string $name, string $code, string $description, int $manager): string
    {
        $error = OrgRules::departmentError([
            'name' => $name, 'code' => $code, 'is_new' => !$id, 'manager' => $manager,
            'manager_in_department' => $manager && $id && $this->people->inDepartment($manager, $id),
            'duplicate' => OrgRules::duplicate($this->store->duplicateFlags($name, $code, $id)),
        ]);
        if ($error !== '') return $error;
        $now = $this->clock->now();
        $isNew = !$id;
        if ($id) {
            $ok = $this->store->update($id, $name, $code, $description, $manager, $now);
        } else {
            $ok = $this->store->insert($name, $code, $description, $manager, $now);
            $id = $this->store->insertId();
        }
        if ($ok === false) return 'save';
        $this->audit->record($isNew ? 'department_create' : 'department_update', 'department', $id, $name . ' / ' . $code);
        return '';
    }

    /**
     * Archives an empty department.
     *
     * @return string '' when archived, 'db' when the database refused, else the error code (OrgRules::message('department', …))
     */
    public function archive(int $id): string
    {
        $teams = $this->teams->tableExists() ? $this->teams->activeCountInDepartment($id) : 0;
        $error = OrgRules::departmentArchiveError($this->store->activeEmployeeCount($id), $teams);
        if ($error !== '') return $error;
        if ($this->store->archive($id, $this->clock->now()) === false) return 'db';
        $this->audit->record('department_archive', 'department', $id, '');
        return '';
    }
}
