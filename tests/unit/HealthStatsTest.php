<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Health\Stats as S;
use WorkforceOne\Health\Settings as St;

/** System health: histograms and settings. */
final class HealthStatsTest extends TestCase
{
    public function testBucketEdges(): void
    {
        $this->assertSame(0, S::bucket(0));
        $this->assertSame(0, S::bucket(50));
        $this->assertSame(1, S::bucket(51));
        $this->assertSame(6, S::bucket(1000));
        $this->assertSame(10, S::bucket(5000));
        $this->assertSame(11, S::bucket(5001));
        $this->assertSame(11, S::bucket(7500));
        $this->assertSame(18, S::bucket(300000));
        $this->assertSame(19, S::bucket(300001));
        $this->assertSame(19, S::bucket(900000));
        $this->assertCount(20, S::empty());
        $this->assertSame(count(S::EDGES) + 1, S::BUCKETS);
    }

    public function testPercentiles(): void
    {
        $this->assertSame(0, S::percentile(S::empty(), 0.95));
        $h = S::empty();
        $h[2] = 100;   // 200 ms
        $this->assertSame(200, S::percentile($h, 0.5));
        $this->assertSame(200, S::percentile($h, 0.95));
        $h = S::empty();
        $h[0] = 90;    // ≤ 50 ms
        $h[6] = 10;    // ≤ 1000 ms
        $this->assertSame(50, S::percentile($h, 0.9));
        $this->assertSame(1000, S::percentile($h, 0.95));
        $h = S::empty();
        $h[19] = 5;
        $this->assertSame(900000, S::percentile($h, 0.95, 900000), 'above 5 minutes the worst seen');
        $this->assertSame(300000, S::percentile($h, 0.95, 0));
        // 99 runs of 6 s and one of 120 s: the typical and the 1-in-20 are 6 s (its bucket's upper edge), not the worst
        $h = S::empty();
        $h[S::bucket(6000)] = 99;
        $h[S::bucket(120000)] = 1;
        $this->assertSame(7500, S::percentile($h, 0.5, 120000));
        $this->assertSame(7500, S::percentile($h, 0.95, 120000));
        $this->assertSame(120000, S::percentile($h, 1.0, 120000));
    }

    public function testMergeAndSlower(): void
    {
        $a = S::empty(); $a[1] = 2; $b = S::empty(); $b[1] = 3; $b[4] = 1;
        $m = S::merge($a, $b);
        $this->assertSame(5, $m[1]);
        $this->assertSame(1, $m[4]);
        $this->assertTrue(S::slower(600, 1000, 50, 300));
        $this->assertFalse(S::slower(600, 850, 50, 300), 'less than +300 ms');
        $this->assertFalse(S::slower(2000, 2400, 50, 300), 'less than +50%');
        $this->assertFalse(S::slower(0, 5000, 50, 300), 'no earlier figure');
    }

    public function testSettingsClamp(): void
    {
        $d = St::settings(null);
        $this->assertSame(2000, $d['slow_ms']);
        $this->assertSame(1, $d['notice']);
        $this->assertSame(0, $d['email']);
        $this->assertSame('', $d['email_to']);
        $s = St::settings(['slow_ms' => 5, 'heavy_ms' => 'x', 'memory_pct' => 500, 'email' => 1, 'email_to' => 'a@b.co', 'bogus' => 1]);
        $this->assertSame(200, $s['slow_ms']);
        $this->assertSame(20000, $s['heavy_ms']);
        $this->assertSame(99, $s['memory_pct']);
        $this->assertSame('a@b.co', $s['email_to']);
        $this->assertArrayNotHasKey('bogus', $s);
        $this->assertSame('', St::settings(['email_to' => 'not an address'])['email_to']);
        $p = St::fromPost(['health_slow_ms' => '3000', 'health_email' => '1', 'health_email_to' => 'x@y.zz']);
        $this->assertSame(3000, $p['slow_ms']);
        $this->assertSame(0, $p['notice'], 'an unchecked box');
        $this->assertSame(1, $p['email']);
    }

    public function testRateAndRetention(): void
    {
        $this->assertSame(10, St::rate(null));
        $this->assertSame(10, St::rate('7'));
        $this->assertSame(25, St::rate('25'));
        $this->assertSame(90, St::retention(null));
        $this->assertSame(7, St::retention(1));
        $this->assertSame(365, St::retention(9999));
    }
}
