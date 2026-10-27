<?php
namespace WorkforceOne\Platform;

if (!defined('ABSPATH')) exit;

/** What a WordPress user may do, for a Service that must decide it for someone other than "the current user" (3.31.128). */
interface Permissions
{
    /** Whether the user is an administrator of the site (manage_options). */
    public function isAdmin(int $userId): bool;
}
