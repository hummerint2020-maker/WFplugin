<?php
namespace WorkforceOne\Platform;

use WorkforceOne\Schedule\Data\ScheduleDayStore;
use WorkforceOne\Schedule\Data\WordPressScheduleDayStore;
use WorkforceOne\Schedule\ScheduleDayService;
use WorkforceOne\Schedule\ScheduleNotifier;
use WorkforceOne\Schedule\WeekLockService;

if (!defined('ABSPATH')) exit;

/**
 * The layered modules' services for one site (docs/tasks/layered-modules.md, 3.31.127): one explicit method per service,
 * built when first asked for. The plugin object is never passed in: what the services need from the rest of the plugin
 * comes as ports. One container per table prefix (the plugin keeps them), as the notifications inbox.
 */
final class Services
{
    /** @var \wpdb */
    private $db;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;
    /** @var People */
    private $people;
    /** @var OnceClaim */
    private $claim;
    /** @var ScheduleNotifier */
    private $scheduleNotifier;
    /** @var array<string,object> */
    private $made = [];

    public function __construct(\wpdb $db, AuditLog $audit, Clock $clock, People $people, OnceClaim $claim, ScheduleNotifier $scheduleNotifier)
    {
        $this->db = $db;
        $this->audit = $audit;
        $this->clock = $clock;
        $this->people = $people;
        $this->claim = $claim;
        $this->scheduleNotifier = $scheduleNotifier;
    }

    public function scheduleDayStore(): ScheduleDayStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressScheduleDayStore($this->db);
        return $this->made[__FUNCTION__];
    }

    public function scheduleDays(): ScheduleDayService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new ScheduleDayService($this->scheduleDayStore(), $this->people, $this->scheduleNotifier, $this->audit, $this->clock);
        return $this->made[__FUNCTION__];
    }

    public function weekLock(): WeekLockService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WeekLockService($this->scheduleDayStore(), $this->scheduleNotifier, $this->audit, $this->claim);
        return $this->made[__FUNCTION__];
    }
}
