<?php
namespace WorkforceOne\Platform;

if (!defined('ABSPATH')) exit;

/** A port: the site's local time now, 'Y-m-d H:i:s' (current_time('mysql')). Read when it is needed, as before. */
interface Clock
{
    public function now(): string;
}
