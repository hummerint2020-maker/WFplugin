<?php
namespace WorkforceOne\Messages\Data;

if (!defined('ABSPATH')) exit;

/** Where the edited texts are kept (3.31.166): lang ('ar' | 'en') => key => text. */
interface MessageOverrideStore
{
    /** @return array<string,array<string,string>> a fresh read (never a copy cached before a write of another request) */
    public function all(): array;

    /** @return array<string,array<string,string>> as read for this request (what the texts shown use) */
    public function cached(): array;

    /** @param array<string,array<string,string>> $all */
    public function put(array $all): void;
}
