<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/** The substitutes' switch and settings (3.31.128), read when needed, not before. */
interface SubstitutePolicy
{
    /** Whether the Substitutes feature is on. */
    public function enabled(): bool;

    /** @return array{manager_decides:int,on_return:string,who:string,ask_on_leave:string} */
    public function settings(): array;
}
