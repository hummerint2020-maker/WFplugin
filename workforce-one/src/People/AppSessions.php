<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** A port to the installed app: the sessions of a WordPress user on every device end (api_revoke_user_sessions). The result is not looked at. */
interface AppSessions
{
    public function revoke(int $wpUserId, string $reason): void;
}
