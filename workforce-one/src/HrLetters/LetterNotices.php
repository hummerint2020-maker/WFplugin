<?php
namespace WorkforceOne\HrLetters;

if (!defined('ABSPATH')) exit;

/** The HR letters' notifications (HL3; the texts of docs/mockups/hr-letter/notices). Codes only: the words are made in the adapter. No salary amount in any. */
interface LetterNotices
{
    /** The approver of the open level: a new request waits for them. */
    public function decider(object $letter): void;

    /** The employee: ready, preparing, collect, rejected, cancelled or updated. */
    public function employee(string $event, object $letter, string $reason = ''): void;

    /** HR: waiting_file, request_only or issue_failed. */
    public function hr(string $event, object $letter): void;
}
