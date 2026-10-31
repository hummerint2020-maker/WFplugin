<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** What the corrections list asks for (3.31.140): the days, the kind of request, a department, a search by name or domain, and (for the page itself) a status. */
final class CorrectionListFilter
{
    /** @var string */
    public $from;
    /** @var string */
    public $to;
    /** @var string '' = any kind */
    public $type;
    /** @var int 0 = any department */
    public $department;
    /** @var string */
    public $search;
    /** @var string '' = any status; set after the counts, as the page does */
    public $status = '';

    public function __construct(string $from, string $to, string $type, int $department, string $search)
    {
        $this->from = $from;
        $this->to = $to;
        $this->type = $type;
        $this->department = $department;
        $this->search = $search;
    }
}
