<?php
namespace WorkforceOne\Requests\Data;

if (!defined('ABSPATH')) exit;

/**
 * The Requests Hub's Reports tab (3.31.159): reads only. The filter is HubReport::filter()'s array; $today is the site's date.
 * Rows out of counts()/keys() carry numbers and dates only (type code, id, request time, status code).
 */
interface HubReportQuery
{
    /** @return array<int,array<int,int>> type code => status code => count, the status filter not applied */
    public function counts(array $f, string $today): array;

    /**
     * Newest first. $after = [at, type code, id]: only rows after that one (the export's batches).
     * @return list<array{t:int,id:int,at:string}>
     */
    public function keys(array $f, string $today, int $limit, int $offset = 0, ?array $after = null): array;

    /** Everyone for the employee filter, archived too (id, name, active), active first by name. @return list<object> */
    public function people(): array;

    /** The stored rows of one type by id (leave rows carry type_name). @param list<int> $ids @return array<int,object> */
    public function rows(string $type, array $ids): array;
}
