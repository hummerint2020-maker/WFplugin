<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** A port: who is signed in now (get_current_user_id(); 0 for the cron job), read at the moment a row records it. */
interface Actor
{
    public function userId(): int;
}
