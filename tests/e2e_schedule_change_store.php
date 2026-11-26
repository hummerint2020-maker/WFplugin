<?php
/**
 * The schedule change requests Store on the real database (3.31.167; docs/tasks/schedule-change-request.md §4): temporary copies
 * of the installed tables, so nothing of the site is touched. Run with wp eval-file (CI's MySQL; SQLite works too).
 */
use WorkforceOne\Schedule\Data\ScheduleChangeSchema;
use WorkforceOne\Schedule\Data\WordPressScheduleChangeStore;

global $wpdb;
$check = static function ($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$prefix = $wpdb->prefix . 'scr_contract_';
$like = $wpdb->query("CREATE TEMPORARY TABLE {$prefix}ews_schedule_change_requests LIKE {$wpdb->prefix}ews_schedule_change_requests") !== false
    && $wpdb->query("CREATE TEMPORARY TABLE {$prefix}ews_schedule_change_days LIKE {$wpdb->prefix}ews_schedule_change_days") !== false;
if (!$like) {   // SQLite: the schema class's own CREATE (dbDelta), dropped at the end
    $db0 = clone $wpdb;
    $db0->prefix = $prefix;
    ScheduleChangeSchema::create($db0);
    register_shutdown_function(function () use ($wpdb, $prefix) { $wpdb->query("DROP TABLE IF EXISTS {$prefix}ews_schedule_change_requests"); $wpdb->query("DROP TABLE IF EXISTS {$prefix}ews_schedule_change_days"); });
}
$check($wpdb->get_var("SELECT COUNT(*) FROM {$prefix}ews_schedule_change_days") === '0', 'contract tables ready (' . ($like ? 'copied from the installed schema' : 'the schema class, SQLite') . ')');
$db = clone $wpdb;
$db->prefix = $prefix;
$store = new WordPressScheduleChangeStore($db);

$req = static function ($emp, $status = 'pending', $at = '2026-10-01 09:00:00') use ($store) {
    return $store->insertRequest(['requester_employee_id' => $emp, 'requested_by' => 1, 'source' => 'grid', 'reason' => 'why not', 'status' => $status, 'days' => 2,
        'first_date' => '2026-10-11', 'last_date' => '2026-10-12', 'requested_at' => $at]);
};
$day = static function ($rid, $emp, $date, $key = true) use ($store) {
    return $store->insertDay(['request_id' => $rid, 'employee_id' => $emp, 'work_date' => $date, 'had_row' => 1, 'schedule_id' => 7, 'old_status' => 'Office', 'new_status' => 'WFH',
        'open_key' => $key ? $emp . '|' . $date : null]);
};
$r1 = $req(5);
$check($r1 > 0 && $store->find($r1)->status === 'pending' && $store->find(999999) === null, 'insertRequest returns the id; find() reads it, null when none');
$d2 = $day($r1, 6, '2026-10-12');
$d1 = $day($r1, 5, '2026-10-11');
$check($d1 > 0 && $d2 > 0 && !$store->lastWasDuplicate(), 'two open days');
$r2 = $req(9);
$check($day($r2, 5, '2026-10-11') === 0 && $store->lastWasDuplicate(), 'a second open request for the same person and day is refused by the UNIQUE open_key (duplicate flagged)');
$check($day($r2, 5, '2026-10-11', false) > 0 && $day($r2, 5, '2026-10-11', false) > 0 && !$store->lastWasDuplicate(), '...a closed day (open_key NULL) can be there many times');
$locked = $store->lockDays($r1);
$check(is_array($locked) && array_map(function ($d) { return (int) $d->employee_id; }, $locked) === [5, 6], 'lockDays(): every day of the request, by employee and day (one lock order)');
$check(count($store->days($r1)) === 2 && array_keys($store->daysOf([$r1, $r2, $r1])) === [$r1, $r2], 'days() and daysOf() (ids de-duplicated, grouped by request)');
$open = $store->openOn([5, 6, 7], '2026-10-11', '2026-10-12');
$check(count($open) === 2 && (int) $open[0]->requester_employee_id === 5 && (string) $open[0]->new_status === 'WFH', 'openOn(): only open days in the range, with the requester');
$check($store->openOn([], '2026-10-11', '2026-10-12') === [] && $store->openOn([5], '2026-10-13', '2026-10-20') === [], '...none for no people or another week');
$check($store->openCount() === 2 && count($store->waiting(10)) === 2 && count($store->forRequester(5, 10)) === 1, 'openCount, waiting, forRequester');
$check($store->claimOwn($r1, 6, ['status' => 'withdrawn']) === 0 && $store->find($r1)->status === 'pending', 'claimOwn(): someone else\'s request is not touched (0 rows)');
$check($store->claim($r1, 'pending', ['status' => 'approved', 'decided_by' => 3, 'decided_at' => '2026-10-02 10:00:00', 'decision_note' => '']) === 1, 'claim(): from pending, 1 row');
$check($store->claim($r1, 'pending', ['status' => 'rejected']) === 0 && $store->find($r1)->status === 'approved' && (int) $store->find($r1)->decided_by === 3, '...a second claim changes nothing (0 rows): one decision wins');
$check($store->claim($r1, 'approved', ['bogus' => 1]) === false, '...nothing to set: false, no query');
$check($store->setDayResult($d1, 'applied', null) && $store->setDayResult($d2, 'changed', 'Absent'), 'setDayResult()');
$rows = $store->days($r1);
$check($rows[0]->result === 'applied' && $rows[0]->open_key === null && $rows[1]->now_status === 'Absent' && $rows[1]->open_key === null, '...the result is kept and the day is no longer open');
$check($day($r2, 5, '2026-10-11') > 0, '...so the day can be asked again');
$check($store->releaseDays($r1) === true, 'releaseDays() is true even when no row changes');
$check($store->releaseDays($r2) === true && $store->openOn([5], '2026-10-11', '2026-10-11') === [], '...and frees the open days');
$check($store->finish($r1, 'partial', 1) === 1 && $store->find($r1)->status === 'partial' && (int) $store->find($r1)->applied === 1, 'finish()');
$check($store->claimOwn($r2, 9, ['status' => 'withdrawn', 'decided_at' => '2026-10-03 08:00:00', 'decided_by' => null]) === 1 && $store->find($r2)->decided_by === null, 'claimOwn(): the requester\'s own pending request; a NULL is written as NULL');
$check($store->lockRequest($r2)->status === 'withdrawn' && $store->openCount() === 0, 'lockRequest(), and nothing waits now');
$check((int) $db->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ews_schedule_change_requests WHERE reason='why not'") === 0, 'the store followed the prefix of its connection: the site\'s tables are untouched');
echo "schedule change store: all passed\n";
