<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** What the correction requests reach in the plugin (3.31.143). Built once, in includes/trait-services.php. */
final class CorrectionPorts
{
    /** @var CorrectionSite */
    public $site;
    /** @var CorrectionNotices */
    public $notices;
    /** @var CorrectionFiles */
    public $files;
    /** @var CorrectionApprovals */
    public $approvals;
    /** @var CorrectionDecider */
    public $decider;

    public function __construct(CorrectionSite $site, CorrectionNotices $notices, CorrectionFiles $files, CorrectionApprovals $approvals, CorrectionDecider $decider)
    {
        $this->site = $site;
        $this->notices = $notices;
        $this->files = $files;
        $this->approvals = $approvals;
        $this->decider = $decider;
    }
}
