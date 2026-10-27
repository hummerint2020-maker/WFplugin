<?php
/**
 * The approvals Stores on the real database (3.31.128; docs/tasks/approvals-engine-move.md §3.5): temporary copies of the
 * installed tables, so nothing of the site is touched. Run with wp eval-file (CI's MySQL; SQLite works too).
 * What it proves that the unit tests' memory Stores cannot: the UNIQUE keys refuse a second row, the conditional UPDATEs lose
 * the race (0 rows), the joins return the workflow's key, and a Store builds its tables from the prefix it was given.
 */
use WorkforceOne\Approvals\Data\WordPressApprovalRequestStore;
use WorkforceOne\Approvals\Data\WordPressSubstituteStore;
use WorkforceOne\Approvals\Data\WordPressWorkflowStore;

global $wpdb;
$check = static function ($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$prefix = $wpdb->prefix . 'apr_contract_';
$names = ['approval_workflows', 'approval_workflow_steps', 'approval_requests', 'approval_steps', 'approval_substitutes'];
$like = true;
foreach ($names as $n) $like = $like && $wpdb->query("CREATE TEMPORARY TABLE {$prefix}ews_{$n} LIKE {$wpdb->prefix}ews_{$n}") !== false;
if (!$like) {
    // SQLite (local runs) has no CREATE ... LIKE: the same columns and keys.
    foreach ($names as $n) $wpdb->query("DROP TABLE IF EXISTS {$prefix}ews_{$n}");
    $wpdb->query("CREATE TABLE {$prefix}ews_approval_workflows (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, workflow_key VARCHAR(80) NOT NULL, name VARCHAR(190) NOT NULL, approval_mode VARCHAR(30) NOT NULL DEFAULT 'NONE', active TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY workflow_key(workflow_key))");
    $wpdb->query("CREATE TABLE {$prefix}ews_approval_workflow_steps (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, workflow_id BIGINT UNSIGNED NOT NULL, step_order SMALLINT UNSIGNED NOT NULL, step_type VARCHAR(30) NOT NULL DEFAULT 'APPROVAL', resolver_type VARCHAR(50) NOT NULL, resolver_value VARCHAR(190) NULL, min_amount DECIMAL(12,2) NULL, required TINYINT(1) NOT NULL DEFAULT 1, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY workflow_order(workflow_id,step_order))");
    $wpdb->query("CREATE TABLE {$prefix}ews_approval_requests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, workflow_id BIGINT UNSIGNED NOT NULL, entity_type VARCHAR(80) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL, requester_employee_id BIGINT UNSIGNED NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'PENDING', current_step SMALLINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL, PRIMARY KEY(id), UNIQUE KEY entity_workflow(entity_type,entity_id,workflow_id))");
    $wpdb->query("CREATE TABLE {$prefix}ews_approval_steps (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, approval_request_id BIGINT UNSIGNED NOT NULL, step_order SMALLINT UNSIGNED NOT NULL, approver_employee_id BIGINT UNSIGNED NULL, approver_wp_user_id BIGINT UNSIGNED NULL, resolver_type VARCHAR(50) NOT NULL, resolver_value VARCHAR(190) NULL, status VARCHAR(30) NOT NULL DEFAULT 'WAITING', decision VARCHAR(30) NULL, comment TEXT NULL, acted_at DATETIME NULL, acted_by_wp_user_id BIGINT UNSIGNED NULL, self_decision TINYINT(1) NOT NULL DEFAULT 0, rerouted TINYINT(1) NOT NULL DEFAULT 0, skip_reason VARCHAR(20) NULL, pending_since DATETIME NULL, reminded_at DATETIME NULL, escalations TINYINT UNSIGNED NOT NULL DEFAULT 0, escalated_from_wp_user_id BIGINT UNSIGNED NULL, for_employee_id BIGINT UNSIGNED NULL, substitute_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY request_step(approval_request_id,step_order))");
    $wpdb->query("CREATE TABLE {$prefix}ews_approval_substitutes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, manager_employee_id BIGINT UNSIGNED NOT NULL, substitute_employee_id BIGINT UNSIGNED NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'scheduled', source VARCHAR(20) NOT NULL DEFAULT 'hr', leave_request_id BIGINT UNSIGNED NULL, reason VARCHAR(255) NOT NULL DEFAULT '', decided INT UNSIGNED NOT NULL DEFAULT 0, created_by BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, started_at DATETIME NULL, ended_at DATETIME NULL, PRIMARY KEY (id))");
    register_shutdown_function(function () use ($wpdb, $prefix, $names) { foreach ($names as $n) $wpdb->query("DROP TABLE IF EXISTS {$prefix}ews_{$n}"); });
}
$check($wpdb->get_var("SELECT COUNT(*) FROM {$prefix}ews_approval_requests") === '0', 'contract tables ready (' . ($like ? 'copied from the installed schema' : 'same columns, SQLite') . ')');
$db = clone $wpdb;
$db->prefix = $prefix;
$quiet = $db->suppress_errors(true);

// ---- workflows
$wf = new WordPressWorkflowStore($db);
$wf->insertWorkflow(['workflow_key' => 'vacation', 'name' => 'Vacation', 'approval_mode' => 'NONE', 'active' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'], ['%s', '%s', '%s', '%d', '%s', '%s']);
$wid = (int) $wf->idByKey('vacation');
$check($wid > 0 && $wf->idByKey('nope') === null && $wf->keyById($wid) === 'vacation' && $wf->keyById(999999) === '', 'workflow by key and by id');
$check($wf->byKey('vacation')->approval_mode === 'NONE' && $wf->activeByKey('vacation') === null, 'activeByKey is null while the workflow is switched off');
$check($wf->setModeActive($wid, 'LEVEL_2', '2026-01-02 00:00:00') === 1 && $wf->activeByKey('vacation')->approval_mode === 'LEVEL_2', 'setModeActive switches it on with its mode');
$wf->insertStep(['workflow_id' => $wid, 'step_order' => 2, 'step_type' => 'APPROVAL', 'resolver_type' => 'HR', 'resolver_value' => null, 'required' => 1, 'active' => 1, 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00', 'min_amount' => '3.5'], ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%f']);
$wf->insertStep(['workflow_id' => $wid, 'step_order' => 1, 'step_type' => 'APPROVAL', 'resolver_type' => 'SUPERVISOR', 'resolver_value' => null, 'required' => 1, 'active' => 1, 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00'], ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);
$levels = $wf->activeSteps($wid);
$check(count($levels) === 2 && $levels[0]->resolver_type === 'SUPERVISOR' && $levels[1]->resolver_type === 'HR' && (float) $levels[1]->min_amount === 3.5, 'levels come back in order, with their condition');
$check($wf->insertStep(['workflow_id' => $wid, 'step_order' => 1, 'step_type' => 'APPROVAL', 'resolver_type' => 'HR', 'resolver_value' => null, 'required' => 1, 'active' => 1, 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00'], ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s']) === false && $wf->lastError() !== '', 'the UNIQUE workflow and order refuses a second level 1 (false, with the database message)');
$check($wf->deleteSteps($wid) === 2 && $wf->activeSteps($wid) === [], 'deleteSteps clears the levels');

// ---- requests and levels
$rq = new WordPressApprovalRequestStore($db);
$now = '2026-01-05 09:00:00';
$req = $rq->insertRequest(['workflow_id' => $wid, 'entity_type' => 'leave', 'entity_id' => 77, 'requester_employee_id' => 5, 'status' => 'WAITING_FOR_LEVEL_1', 'current_step' => 1], ['%d', '%s', '%d', '%d', '%s', '%d']);
$check($req > 0 && $rq->existingId('leave', 77, $wid) === (string) $req && $rq->existingId('leave', 78, $wid) === null, 'insertRequest returns the database id; existingId finds it');
$check($rq->insertRequest(['workflow_id' => $wid, 'entity_type' => 'leave', 'entity_id' => 77, 'requester_employee_id' => 5, 'status' => 'WAITING_FOR_LEVEL_1', 'current_step' => 1], ['%d', '%s', '%d', '%d', '%s', '%d']) === false && $rq->lastError() !== '', 'the UNIQUE entity and workflow refuses a second request (false, with the database message)');
$rq->clearError();
$check($rq->lastError() === '', 'clearError empties the last error');
$level = function ($order, $status, $extra = []) use ($rq, $req, $now) {
    return $rq->insertStep($extra + ['approval_request_id' => $req, 'step_order' => $order, 'resolver_type' => 'SUPERVISOR', 'resolver_value' => null, 'status' => $status, 'rerouted' => 0, 'approver_employee_id' => 2, 'approver_wp_user_id' => 20, 'pending_since' => $status === 'PENDING' ? $now : null], ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s']);
};
$check($level(1, 'PENDING') && $level(2, 'WAITING', ['approver_employee_id' => 3, 'approver_wp_user_id' => 30]) && !$level(2, 'WAITING'), 'insertStep: two levels, and the UNIQUE request and order refuses a repeat');
$check($rq->requestRow($req) == $rq->requestForCheck($req) && $rq->requestRow(999999) === null, 'requestRow and requestForCheck read the same row');
$check((int) $rq->currentStep($req)->step_order === 1 && (int) $rq->pendingStepAt($req, 1)->step_order === 1 && $rq->pendingStepAt($req, 2) === null && (int) $rq->nextWaiting($req)->step_order === 2, 'current, pending-at and next-waiting levels');
$found = $rq->findByKey('vacation', 'leave', 77);
$check($found && $found->workflow_key === 'vacation' && $found->approval_mode === 'LEVEL_2' && (int) $found->id === $req && $rq->findByKey('vacation', 'leave', 78) === null, 'findByKey joins the workflow key and mode');
$many = $rq->findByKeyGroups(["vacation\0leave" => [77 => true, 78 => true]]);
$check(count($many) === 1 && $many[0]->workflow_key === 'vacation', 'findByKeyGroups: the groups in one query');
$check(array_keys($rq->currentSteps([$req, 999999])) === [$req] && count($rq->stepsOf([$req])[$req]) === 2 && (int) $rq->stepsOf([$req])[$req][0]->step_order === 1, 'currentSteps by request; stepsOf in order');

// The conditional UPDATEs: the second of two decisions on the same level loses.
$set = ['status' => 'APPROVED', 'decision' => 'approve', 'comment' => 'ok', 'acted_at' => $now, 'acted_by_wp_user_id' => 20, 'self_decision' => 0];
$step1 = $rq->currentStep($req);
$check($rq->decideStep((int) $step1->id, $set) === 1 && $rq->decideStep((int) $step1->id, $set) === 0, 'decideStep: the first wins (1 row), a second on the same level changes nothing (0)');
$step2 = $rq->nextWaiting($req);
$check($rq->openNext((int) $step2->id, ['status' => 'PENDING', 'pending_since' => $now]) === 1 && $rq->openNext((int) $step2->id, ['status' => 'PENDING', 'pending_since' => $now]) === 0, 'openNext: only while the next level is still WAITING');
$check($rq->updateRequest($req, ['status' => 'WAITING_FOR_LEVEL_2', 'current_step' => 2], ['%s', '%d']) === 1 && (int) $rq->requestRow($req)->current_step === 2, 'updateRequest by id');
$check($rq->stillWithAdministrators($req, 2) === false, 'a level with an approver does not wait for administrators');
$db->update($prefix . 'ews_approval_steps', ['approver_wp_user_id' => null, 'rerouted' => 1], ['id' => (int) $step2->id]);
$check($rq->stillWithAdministrators($req, 2) === true && $rq->stillWithAdministrators($req, 1) === false, 'stillWithAdministrators: rerouted, no approver, still PENDING');
$db->update($prefix . 'ews_approval_steps', ['approver_wp_user_id' => 30, 'rerouted' => 0], ['id' => (int) $step2->id]);

// Inbox and the small counts.
$rows = $rq->inboxRows(30, 0, 0);
$check(count($rows) === 1 && $rows[0]->workflow_key === 'vacation' && (int) $rows[0]->step_id === (int) $step2->id && $rq->inboxRows(31, 0, 0) === [] && $rq->inboxRows(31, 1, 0) === [], 'inboxRows: the levels waiting for a user (none for another; none for administrators while nothing is rerouted)');
$check($rq->hasAnyStep(30) && $rq->hasAnyStep(20) && !$rq->hasAnyStep(31) && $rq->decidedSince(20, '2026-01-01 00:00:00') === 1 && $rq->decidedSince(20, '2026-02-01 00:00:00') === 0, 'hasAnyStep and decidedSince');

// Organisation moves.
$check($rq->waitingOnSupervisors() === [5 => [3 => 1]] && $rq->supervisorStepIds(5, 3) === [(int) $step2->id] && $rq->supervisorStepIds(5, 9) === [], 'waitingOnSupervisors and supervisorStepIds');
$check($rq->reassignSteps([(int) $step2->id], 8, 80) === 1 && $rq->countPending([(int) $step2->id]) === 1 && (int) $rq->currentStep($req)->approver_wp_user_id === 80, 'reassignSteps moves the level; countPending counts it');

// Deadlines.
$db->update($prefix . 'ews_approval_steps', ['pending_since' => null], ['id' => (int) $step2->id]);
$rq->backfillPendingSince('2026-01-06 09:00:00');
$cand = $rq->overdueCandidates('vacation');
$check(count($cand) === 1 && $cand[0]->pending_since === '2026-01-06 09:00:00' && (int) $cand[0]->requester_employee_id === 5 && $cand[0]->entity_type === 'leave' && $rq->overdueCandidates('overtime') === [], 'backfillPendingSince, then overdueCandidates (with the request\'s requester and entity)');
$check($rq->markReminded((int) $step2->id, '2026-01-07 09:00:00') === 1, 'markReminded');
$check($rq->escalate((int) $step2->id, [4, 40], 99, '2026-01-08 09:00:00') === 0, 'escalate: another approver than the one read changes nothing (0)');
$check($rq->escalate((int) $step2->id, [4, 40], 80, '2026-01-08 09:00:00') === 1, 'escalate: to the new approver while it still waits for the old one');
$moved = $rq->currentStep($req);
$check((int) $moved->approver_wp_user_id === 40 && (int) $moved->escalations === 1 && (int) $moved->escalated_from_wp_user_id === 80 && $moved->reminded_at === null && $moved->pending_since === '2026-01-08 09:00:00', 'the level kept its history');
$check($rq->escalate((int) $step2->id, null, 40, '2026-01-09 09:00:00') === 1 && (int) $rq->currentStep($req)->rerouted === 1 && $rq->currentStep($req)->approver_wp_user_id === null, 'escalate to the administrators: rerouted, no approver');

// Substitutes' share of the levels.
$db->update($prefix . 'ews_approval_steps', ['approver_employee_id' => 3, 'approver_wp_user_id' => 30, 'rerouted' => 0], ['id' => (int) $step2->id]);
$check($rq->assignWaitingToSubstitute(6, 60, 3, 9) === 1 && (int) $rq->currentStep($req)->for_employee_id === 3 && $rq->countPendingForSubstitute(9) === 1, 'assignWaitingToSubstitute: the level goes to the substitute "for" the manager');
$check($rq->assignWaitingToSubstitute(6, 60, 3, 9) === 0, '...and only once (it is already "for" someone)');
$check($rq->returnPendingToManager(3, 30, 9) === 1 && $rq->countPendingForSubstitute(9) === 0 && (int) $rq->currentStep($req)->approver_wp_user_id === 30, 'returnPendingToManager');

// Closing.
$check($rq->cancelRequestIf($req, 'APPROVED', $now) === 0 && $rq->cancelRequestIf($req, 'WAITING_FOR_LEVEL_2', $now) === 1, 'cancelRequestIf: only while the request has the status that was read');
$check($rq->cancelOpenSteps($req, true) && $rq->currentStep($req) === null && $db->get_var("SELECT skip_reason FROM {$prefix}ews_approval_steps WHERE id=" . (int) $step2->id) === 'closed', 'cancelOpenSteps($closed): the levels say "closed"');

// ---- substitutes
$sb = new WordPressSubstituteStore($db);
$sid = $sb->insert(['manager_employee_id' => 3, 'substitute_employee_id' => 6, 'starts_at' => '2026-01-10 00:00:00', 'ends_at' => '2026-01-12 00:00:00', 'status' => 'scheduled', 'source' => 'hr', 'reason' => '', 'created_by' => 1, 'created_at' => $now]);
$check($sid > 0 && $sb->find($sid)->status === 'scheduled' && $sb->find(999999) === null, 'substitute insert and find');
$check($sb->activeFor(3, '2026-01-11 00:00:00')->id == $sid && $sb->activeFor(3, '2026-01-13 00:00:00') === null && $sb->activeFor(4, '2026-01-11 00:00:00') === null, 'activeFor: scheduled or active, inside its days');
$check($sb->transition($sid, 'pending', ['status' => 'active']) === 0 && $sb->transition($sid, 'scheduled', ['status' => 'active', 'started_at' => $now]) === 1 && count($sb->active()) === 1 && count($sb->current()) === 1, 'transition: only from the status that was read');
$sb->incrementDecided($sid);
$check($sb->decided($sid) === 1 && count($sb->forSubstitute(6)) === 1 && $sb->forSubstitute(7) === [], 'incrementDecided, decided, forSubstitute');
$check($sb->overlapForManager(3, '2026-01-11 00:00:00', '2026-01-10 12:00:00', 0) && !$sb->overlapForManager(3, '2026-01-11 00:00:00', '2026-01-10 12:00:00', $sid) && !$sb->overlapForManager(3, '2026-01-15 00:00:00', '2026-01-14 00:00:00', 0) && $sb->overlapForCandidate(3, '2026-01-11 00:00:00', '2026-01-10 12:00:00'), 'overlap checks');
$check(count($sb->ownOpen(3, '2026-01-11 00:00:00')) === 1 && $sb->ownOpen(3, '2026-02-01 00:00:00') === [] && count($sb->adminList()) === 1 && $sb->linkedToLeave() === [], 'ownOpen, adminList, linkedToLeave');
$db->suppress_errors($quiet);

// One container per site: another prefix gets its own Stores; back on the first, the same ones.
$o = new EWS_Manager_V31_1();
$svc = new ReflectionMethod($o, 'services');
$svc->setAccessible(true);
$a = $svc->invoke($o);
$keep = $wpdb->prefix;
$wpdb->prefix = $prefix;
$b = $svc->invoke($o);
$wpdb->prefix = $keep;
$check($a !== $b && $svc->invoke($o) === $a && $a->approvalRequestStore() === $a->approvalRequestStore() && $a->approvalRequestStore() !== $b->approvalRequestStore(), 'one container per table prefix (multisite), reused when switching back');
