<?php
namespace WorkforceOne\Messages;

if (!defined('ABSPATH')) exit;

/**
 * The kind of a message where the catalog's guess (tools/build_message_catalog.php: notification texts are the ones passed
 * to notify(), errors the ones in wp_die() and the error functions) is not right, or for a text that must stand out as a
 * banner. Keys as in the catalog: the English text, or context . "\x04" . text for a text written with _x().
 * tests/check_message_catalog.py fails when a key here is not in the catalog (the text was changed in the code).
 *
 * A feature that adds a banner (for example the closed week of the schedule change request: _x('…', 'closed week banner',
 * 'workforce-one')) lists its key here with kind 'banner'.
 */
final class ImportantMessages
{
    /** @var array<string,string> key => notification | banner | error | ui */
    public const KINDS = [
        // Notices shown after an action (not errors).
        'The shift swap was accepted and both schedules were updated.' => 'banner',
        'The shift swap request was rejected.' => 'banner',
        'The shift swap request was cancelled.' => 'banner',
        'You accepted the swap. It is applied once it is approved.' => 'banner',
        'The swap was approved and both schedules were updated.' => 'banner',
        'Approved. The swap now waits for the next approver.' => 'banner',
        'The shift swap has been updated.' => 'banner',
        'The leave request has been updated.' => 'banner',
        'The overtime request has been updated.' => 'banner',
        'The vacation request has been updated.' => 'banner',
        'The attendance schedule has been updated.' => 'banner',
        // Banners on the schedule of a locked week.
        'Locked since %s. Approved leave and swaps are still applied. To change a day, ask HR.' => 'banner',
        'Locked since %s. Every change you make is recorded in the Audit Log under your name.' => 'banner',
    ];

    public static function kind(string $key, string $guessed): string
    {
        return self::KINDS[$key] ?? $guessed;
    }
}
