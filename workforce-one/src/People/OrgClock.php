<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** A port: the site's local clock as the org moves read it (current_time with 'mysql', 'Y-m-d' and 'timestamp'), each when it is needed, as before. */
interface OrgClock
{
    /** 'Y-m-d H:i:s' */
    public function now(): string;

    /** 'Y-m-d' */
    public function today(): string;

    /** The site's local time as a number (current_time('timestamp')). */
    public function timestamp(): int;
}
