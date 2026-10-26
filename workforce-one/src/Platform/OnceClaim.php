<?php
namespace WorkforceOne\Platform;

if (!defined('ABSPATH')) exit;

/** A port: a value kept in an option, claimed once even when two runs overlap (one conditional UPDATE). */
interface OnceClaim
{
    public function current(string $name): string;

    /** True for exactly one caller that moves $name to $value. */
    public function claim(string $name, string $value): bool;
}
