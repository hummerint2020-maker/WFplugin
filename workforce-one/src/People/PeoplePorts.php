<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** What the people services need from the rest of the plugin, held together so the container takes one argument (3.31.133: the memo; later releases add ports). */
final class PeoplePorts
{
    /** @var PeopleMemo */
    public $memo;

    public function __construct(PeopleMemo $memo)
    {
        $this->memo = $memo;
    }
}
