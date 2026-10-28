<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** A port to WordPress accounts: is there a user with this id (get_userdata). */
interface WpUsers
{
    public function exists(int $userId): bool;
}
