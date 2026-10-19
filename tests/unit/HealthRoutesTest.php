<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Health\Routes as R;

/** System health: request names carry no personal data. */
final class HealthRoutesTest extends TestCase
{
    private const VIEWS = ['schedule', 'time', 'vacation'];

    private function c(array $f): string
    {
        return R::classify($f + ['views' => self::VIEWS]);
    }

    public function testEachArea(): void
    {
        $this->assertSame('cron:ews_tasks_tick', $this->c(['doing_cron' => true, 'cron_hook' => 'ews_tasks_tick']));
        $this->assertSame('cron:other', $this->c(['doing_cron' => true, 'cron_hook' => 'some_other_plugin_hook']));
        $this->assertSame('post:ews_payroll_close', $this->c(['script' => 'admin-post.php', 'action' => 'ews_payroll_close', 'action_known' => true]));
        $this->assertSame('admin:ews31-payroll', $this->c(['is_admin' => true, 'page' => 'ews31-payroll', 'page_known' => true]));
        $this->assertSame('app:schedule', $this->c(['app_page' => true, 'view' => 'schedule']));
        $this->assertSame('app:default', $this->c(['app_page' => true]));
        $this->assertSame('other:front', $this->c([]));
        $this->assertSame('other:admin', $this->c(['is_admin' => true, 'page' => 'plugins']));
    }

    public function testNothingOutsideTheLists(): void
    {
        $this->assertSame('post:other', $this->c(['script' => 'admin-post.php', 'action' => 'woocommerce_x', 'action_known' => true]));
        $this->assertSame('post:other', $this->c(['script' => 'admin-post.php', 'action' => 'ews_x', 'action_known' => false]), 'no handler registered');
        $this->assertSame('post:other', $this->c(['script' => 'admin-post.php', 'action' => 'ews_ahmed@example.com', 'action_known' => true]));
        $this->assertSame('other:admin', $this->c(['is_admin' => true, 'page' => 'ews31-' . str_repeat('a', 80), 'page_known' => true]), 'over-long');
        $this->assertSame('app:other', $this->c(['app_page' => true, 'view' => 'ahmed']), 'a view that is not on the list');
        $this->assertSame('app:other', $this->c(['app_page' => true, 'view' => 'sched ule']));
    }

    public function testRestPatterns(): void
    {
        $this->assertSame('/corrections/{id}/decision', R::restPattern('/workforce-one/v1/corrections/(?P<id>\d+)/decision'));
        $this->assertSame('/meta', R::restPattern('/workforce-one/v1/meta'));
        $this->assertSame('/a/{x}/b/{y}', R::restPattern('/workforce-one/v1/a/(?P<x>[\w-]+)/b/(?P<y>(?:\d+))'));
        $this->assertNull(R::restPattern('/wp/v2/posts'), 'another namespace');
        $this->assertNull(R::restPattern('/workforce-one/v1/x?id=5&email=a@b.c'), 'a query string is refused');
        $this->assertSame('rest:POST /corrections/{id}/decision', $this->c(['rest_route' => '/workforce-one/v1/corrections/(?P<id>\d+)/decision', 'rest_method' => 'post']));
        $this->assertSame('rest:GET /meta', $this->c(['rest_route' => '/workforce-one/v1/meta', 'rest_method' => 'TRACE']));
        $this->assertSame('rest:other', $this->c(['rest_route' => '/other/v1/x']));
    }

    public function testNoRouteCarriesAValueFromTheRequest(): void
    {
        foreach (['ews_ahmed', 'ews_1234', 'ews_a=b'] as $a) {
            $r = $this->c(['script' => 'admin-post.php', 'action' => $a, 'action_known' => true]);
            $this->assertMatchesRegularExpression('/^[a-z]+:[A-Za-z0-9_\-\/{} :]+$/', $r);
        }
        $this->assertSame('Actions', R::area('post:ews_x'));
        $this->assertSame('Jobs', R::area('cron:ews_tasks_tick'));
        $this->assertSame('Other', R::area('other:front'));
    }

    public function testSamplingAndHeavy(): void
    {
        $this->assertTrue(R::alwaysSampled('post:ews_payroll_close'));
        $this->assertTrue(R::alwaysSampled('cron:ews_tasks_tick'));
        $this->assertFalse(R::alwaysSampled('app:schedule'));
        $this->assertTrue(R::heavy('post:ews31_report_xlsx'));
        $this->assertFalse(R::heavy('admin:ews31-payroll'));
    }
}
