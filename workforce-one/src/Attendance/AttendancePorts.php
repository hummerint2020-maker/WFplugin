<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** What the attendance services reach in the plugin (3.31.137): the idempotency keys and the site; the correction requests' ports (3.31.143). Built once, in includes/trait-services.php. */
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
    /** @var CorrectionPorts */
    public $corrections;

    public function __construct(Idempotency $idempotency, AttendanceSite $site, ManualRecordSite $manual, EvidenceSite $evidence, CorrectionPorts $corrections)
    {
        $this->corrections = $corrections;
        $this->evidence = $evidence;
        $this->idempotency = $idempotency;
        $this->site = $site;
        $this->manual = $manual;
    }
}
