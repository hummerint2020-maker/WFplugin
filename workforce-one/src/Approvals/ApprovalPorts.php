<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * What the approvals engine needs from the rest of the plugin, held together (3.31.128) so the container takes one argument
 * for them. Each is a port: the plugin's adapters (includes/trait-services.php) implement them.
 */
final class ApprovalPorts
{
    /** @var ApprovalPolicy */
    public $policy;
    /** @var ApprovalNotifier */
    public $notifier;
    /** @var AfterRequest */
    public $after;
    /** @var ApprovalDirectory */
    public $directory;
    /** @var DeadlineNotifier */
    public $deadlines;

    public function __construct(ApprovalPolicy $policy, ApprovalNotifier $notifier, AfterRequest $after, ApprovalDirectory $directory, DeadlineNotifier $deadlines)
    {
        $this->policy = $policy;
        $this->notifier = $notifier;
        $this->after = $after;
        $this->directory = $directory;
        $this->deadlines = $deadlines;
    }
}
