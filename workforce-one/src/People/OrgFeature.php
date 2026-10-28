<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** A port to the organization chart's settings: the chart is on, and a job title is required. Read at the moment they are needed. */
interface OrgFeature
{
    public function enabled(): bool;

    public function titleRequired(): bool;
}
