<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/** What the substitutes need from the rest of the plugin, held together (3.31.128) so the container takes one argument for them. */
final class SubstitutePorts
{
    /** @var SubstitutePolicy */
    public $policy;
    /** @var LeaveFacts */
    public $leave;
    /** @var SubstituteNotifier */
    public $notifier;

    public function __construct(SubstitutePolicy $policy, LeaveFacts $leave, SubstituteNotifier $notifier)
    {
        $this->policy = $policy;
        $this->leave = $leave;
        $this->notifier = $notifier;
    }
}
