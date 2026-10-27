<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/** The teams and their members as the other modules read them (3.31.133). Same SQL as before; raw wpdb values. */
interface TeamStore
{
    /** The active manager of the employee's first active team, with team_id and team_name; or null. */
    public function activeManagerFor(int $employeeId): ?object;

    /** @return list<string> */
    public function activeMemberIds(int $teamId): array;

    /** @return list<string> */
    public function activeTeamIdsOf(int $employeeId): array;

    /** employee_id and team_id of the active memberships of these employees, by team (chunks of 500). @param list<int> $ids @return list<object> */
    public function activeTeamRowsByEmployees(array $ids): array;
}
