<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/**
 * What the Sign In / Out report asks for (3.31.139): the period, the search, which kind of rows, whether the attempts table is in use and the department scope
 * (null = everyone, 0 = nobody, n = one department). Plain values: the read model builds its SQL from them.
 */
final class TimeReportFilter
{
    /** @var string */
    public $start;
    /** @var string */
    public $end;
    /** @var string */
    public $search;
    /** @var string recorded | refused | both */
    public $show;
    /** @var bool */
    public $attempts;
    /** @var int|null */
    public $scope;

    public function __construct(string $start, string $end, string $search, string $show, bool $attempts, ?int $scope)
    {
        $this->start = $start;
        $this->end = $end;
        $this->search = $search;
        $this->show = $show;
        $this->attempts = $attempts;
        $this->scope = $scope;
    }
}
