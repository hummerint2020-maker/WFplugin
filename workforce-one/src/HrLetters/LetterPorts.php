<?php
namespace WorkforceOne\HrLetters;

if (!defined('ABSPATH')) exit;

/** What the HR letters reach in the plugin (HL3). Built once, in includes/trait-services.php. */
final class LetterPorts
{
    /** @var LetterSite */
    public $site;
    /** @var LetterApprovals */
    public $approvals;
    /** @var LetterNotices */
    public $notices;
    /** @var LetterFiles */
    public $files;
    /** @var LetterSalary */
    public $salary;

    public function __construct(LetterSite $site, LetterApprovals $approvals, LetterNotices $notices, LetterFiles $files, LetterSalary $salary)
    {
        $this->site = $site;
        $this->approvals = $approvals;
        $this->notices = $notices;
        $this->files = $files;
        $this->salary = $salary;
    }
}
