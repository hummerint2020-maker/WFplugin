<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/** The messages the substitutes send (3.31.128). The words, the links and the inbox stay in the plugin. */
interface SubstituteNotifier
{
    /** The substitute is told they were picked (the substitute is scheduled). */
    public function picked(object $row): void;

    /** The substitute is told the cover started; $moved levels went to them now. */
    public function started(object $row, int $moved, string $managerName): void;

    /** The manager is told the cover ended: how many the substitute decided, how many went back, how many still wait for them. */
    public function ended(int $managerUserId, object $row, int $decided, int $back, int $still, string $substituteName): void;
}
