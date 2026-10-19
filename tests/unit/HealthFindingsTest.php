<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Health\Findings as F;
use WorkforceOne\Health\Alerts as A;
use WorkforceOne\Health\Settings as St;
use WorkforceOne\Health\Stats;

/** System health: "Look at this first" and the alerts. */
final class HealthFindingsTest extends TestCase
{
    private function s(array $o = []): array { return $o + St::settings(null); }

    /** A route with 100 runs, all in one bucket. */
    private function route(int $bucket, array $o = []): array
    {
        $h = Stats::empty(); $h[$bucket] = 100;
        return $o + ['n' => 100, 'failed' => 0, 'fatal' => 0, 'hist' => $h, 'ms_max' => 0, 'pq_sum' => 0, 'pq_n' => 0, 'mem_max' => 0, 'max_repeat' => 0];
    }

    private function rules(array $in, array $s = null): array
    {
        return array_column(F::evaluate($in, $s ?? $this->s()), 'rule', 'key');
    }

    public function testSlowAtItsThreshold(): void
    {
        $this->assertSame([], $this->rules(['routes' => ['admin:ews31-x' => $this->route(8)]]), '2000 ms is not above 2000');
        $this->assertSame(['slow:admin:ews31-x' => 'slow'], $this->rules(['routes' => ['admin:ews31-x' => $this->route(9)]]), '3000 ms');
        $this->assertSame([], $this->rules(['routes' => ['post:ews_payroll_close' => $this->route(10)]]), 'a heavy operation has the larger limit');
        $this->assertSame([], $this->rules(['routes' => ['admin:ews31-x' => $this->route(9, ['n' => 19])]]), 'under min_runs');
    }

    public function testFailureAndFatal(): void
    {
        $this->assertSame([], $this->rules(['routes' => ['a' => $this->route(0, ['failed' => 1])]]), '1 in 100 is under 2%');
        $this->assertSame(['fail_rate:a' => 'fail_rate'], $this->rules(['routes' => ['a' => $this->route(0, ['failed' => 2])]]));
        $this->assertSame(['fatal:a' => 'fatal'], $this->rules(['routes' => ['a' => $this->route(0, ['fatal' => 1, 'n' => 3])]]), 'a fatal needs no min_runs');
    }

    public function testRegression(): void
    {
        $r = $this->route(0, ['recent_n' => 40, 'before_n' => 40, 'before_p50' => 600, 'recent_p50' => 1000, 'version' => '3.31.108']);
        $f = F::evaluate(['routes' => ['app:schedule' => $r]], $this->s());
        $this->assertSame('regression', $f[0]['rule']);
        $this->assertSame('3.31.108', $f[0]['args']['version']);
        $this->assertSame([], $this->rules(['routes' => ['x' => ['before_n' => 5] + $r]]), 'not enough earlier runs');
        $this->assertSame([], $this->rules(['routes' => ['x' => ['recent_p50' => 850] + $r]]), 'under +300 ms');
    }

    public function testQueriesMemoryAndOthers(): void
    {
        $this->assertSame(['repeat:a' => 'repeat'], $this->rules(['routes' => ['a' => $this->route(0, ['max_repeat' => 25])]]));
        $this->assertSame([], $this->rules(['routes' => ['a' => $this->route(0, ['max_repeat' => 24])]]));
        $this->assertSame(['many_queries:a' => 'many_queries'], $this->rules(['routes' => ['a' => $this->route(0, ['pq_sum' => 3000, 'pq_n' => 20])]]), '150 each');
        $this->assertSame([], $this->rules(['routes' => ['a' => $this->route(0, ['pq_sum' => 2900, 'pq_n' => 20])]]));
        $this->assertSame(['other_cost:other:front' => 'other_cost'], $this->rules(['routes' => ['other:front' => $this->route(0, ['pq_sum' => 500, 'pq_n' => 20])]]));
        $this->assertSame(['memory:a' => 'memory'], $this->rules(['memory_limit_kb' => 262144, 'routes' => ['a' => $this->route(0, ['mem_max' => 214748])]]), '82%');
        $this->assertSame([], $this->rules(['memory_limit_kb' => 262144, 'routes' => ['a' => $this->route(0, ['mem_max' => 200000])]]));
        $this->assertSame(['slow_query:a:people_name' => 'slow_query'], $this->rules(['queries' => [['route' => 'a', 'caller' => 'people_name', 'ms_max' => 1000], ['route' => 'a', 'caller' => 'x', 'ms_max' => 999]]]));
    }

    public function testCronAndErrors(): void
    {
        $in = ['cron' => ['ews_tasks_tick' => ['overdue_min' => 180], 'ews_x' => ['overdue_min' => 30], 'ews_y' => ['failed' => true]],
            'errors' => [['file' => 'includes/trait-leave.php', 'line' => 88, 'message' => 'Undefined array key', 'n' => 12]]];
        $this->assertSame(['cron_overdue:cron:ews_tasks_tick' => 'cron_overdue', 'cron_failed:cron:ews_y' => 'cron_failed', 'error:includes/trait-leave.php:88' => 'new_error'], $this->rules($in));
    }

    public function testRankingSeverityThenImpact(): void
    {
        $in = ['routes' => [
            'a' => $this->route(9),                                   // slow: 100 × 1000
            'b' => $this->route(10),                                  // slow: 100 × 3000
            'c' => $this->route(0, ['fatal' => 1]),                   // critical
        ], 'errors' => [['file' => 'f.php', 'line' => 1, 'message' => 'x']]];
        $this->assertSame(['fatal:c', 'slow:b', 'slow:a', 'error:f.php:1'], array_column(F::evaluate($in, $this->s()), 'key'));
    }

    public function testAlertsOpenRepeatAndClose(): void
    {
        $f = [['key' => 'slow:a', 'severity' => 'warning', 'rule' => 'slow', 'route' => 'a', 'args' => []], ['key' => 'n:b', 'severity' => 'note', 'rule' => 'new_error', 'route' => '', 'args' => []]];
        $t = 1000000;
        $u = A::update([], $f, $t);
        $this->assertSame(['slow:a', 'n:b'], $u['opened']);
        $this->assertSame(['slow:a'], A::due($u['open'], $t, 0), 'a note is never emailed');
        $o = A::markEmailed($u['open'], ['slow:a'], $t);
        $this->assertSame([], A::due($o, $t + 3600, 0), 'not again within 24 h');
        $this->assertSame(['slow:a'], A::due(A::update($o, $f, $t + 90000)['open'], $t + 90000, 0), 'again after 24 h while still open');
        $this->assertSame([], A::due($u['open'], $t, 5), 'the daily cap');
        $again = A::update($u['open'], [], $t + 3600);
        $this->assertArrayHasKey('slow:a', $again['open'], 'stays open for 24 h without a match');
        $this->assertSame([], A::update($u['open'], [], $t + 90000)['open'], 'closes after 24 h');
        $this->assertSame([], A::update($o, $f, $t + 10)['opened'], 'an open alert is not new');
    }

    public function testHidingAndCounts(): void
    {
        $t = 5000000;
        $open = A::update([], [['key' => 'k1', 'severity' => 'critical', 'rule' => 'r', 'route' => '', 'args' => []], ['key' => 'k2', 'severity' => 'warning', 'rule' => 'r', 'route' => '', 'args' => []]], $t)['open'];
        $hidden = A::hide([], ['k1'], $t);
        $v = A::visible($open, $hidden, $t + 10);
        $this->assertSame(['k2'], array_column($v, 'key'));
        $this->assertSame(['k1', 'k2'], array_column(A::visible($open, $hidden, $t + 8 * 86400), 'key'), 'the hide lasts a week');
        $this->assertSame(['critical' => 1, 'warning' => 1, 'note' => 0], A::counts(A::visible($open, [], $t)));
        $this->assertArrayNotHasKey('old', A::hide(['old' => $t - 1], [], $t), 'ended hides are dropped');
    }
}
