<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\HrLetters\Data\LetterSchema;
use WorkforceOne\HrLetters\LetterSettings;

/**
 * HR letters (HL3, docs/tasks/hr-letter-hl3.md; owner decisions in docs/tasks/hr-letter.md; mockup docs/mockups/hr-letter/). Off by default
 * (wp-admin → Features). An employee asks HR for a letter from My requests; it goes through the "HR Letter" approval workflow (HR at one
 * level by default); each type is delivered as a PDF made from its template, a file HR uploads, or the request only (ready to collect).
 * Controllers only: the rules are in src/HrLetters/, the SQL in its stores. Behaviour: tests/e2e_hr_letters.py.
 */
trait EWS_Hr_Letters_Trait {

    /** The switch only (a primed option): every HR letter path checks it first. */
    public function hr_letters_enabled(){ return (bool)$this->option('ews_feature_hr_letters'); }

    /** Employees can ask: letters on, with My approvals and My requests (their only entry point). */
    public function hr_letters_requestable(){
        return $this->hr_letters_enabled() && $this->my_approvals_enabled();
    }

    /** @return array<string,mixed> */
    public function hr_letter_settings(){ return LetterSettings::settings($this->option('ews_hr_letters')); }

    /** @return list<array<string,mixed>> */
    public function hr_letter_types(){ return LetterSettings::types($this->option('ews_hr_letter_types')); }

    /** @return array<string,string> */
    public function hr_letter_letterhead(){ return LetterSettings::letterhead($this->option('ews_hr_letter_letterhead')); }

    /** The letters table (an upgrade step; created while the feature is off too, so switching it on needs nothing more). */
    public function ensure_hr_letters_schema(){
        if($this->ews_schema_is_current())return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta(LetterSchema::sql($wpdb->prefix.'ews_hr_letters',$wpdb->get_charset_collate()));
    }
}
