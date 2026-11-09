<?php
namespace WorkforceOne\HrLetters;

if (!defined('ABSPATH')) exit;

/** What the HR letters read from the site (HL3): the switch, the settings, the letterhead, the company and currency, today. */
interface LetterSite
{
    /** The feature switch. */
    public function enabled(): bool;

    /** Employees can ask: the switch on, with My approvals and My requests. */
    public function requestable(): bool;

    /** @return array<string,mixed> LetterSettings::settings() */
    public function settings(): array;

    /** @return list<array<string,mixed>> LetterSettings::types() */
    public function types(): array;

    /** @return array<string,string> LetterSettings::letterhead() */
    public function letterhead(): array;

    /** The site's name, when the letterhead has no company name. */
    public function companyFallback(): string;

    /** Payroll's currency code (EGP by default). */
    public function currencyCode(): string;

    /** Y-m-d in the site's time zone. */
    public function today(): string;

    /** A letter exists: HR keeps its page and files while the feature is off. */
    public function markUsed(): void;
}
