<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** What the attendance services reach in the plugin (3.31.137): the idempotency keys and the site. Built once, in includes/trait-services.php. */
final class AttendancePorts
{
    /** @var Idempotency */
    public $idempotency;
    /** @var AttendanceSite */
    public $site;
    /** @var ManualRecordSite */
    public $manual;
    /** @var EvidenceSite */
    public $evidence;

    public function __construct(Idempotency $idempotency, AttendanceSite $site, ManualRecordSite $manual, EvidenceSite $evidence)
    {
        $this->evidence = $evidence;
        $this->idempotency = $idempotency;
        $this->site = $site;
        $this->manual = $manual;
    }
}
