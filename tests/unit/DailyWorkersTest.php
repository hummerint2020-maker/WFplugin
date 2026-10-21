<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\DailyWorkers\NationalId;
use WorkforceOne\DailyWorkers\PayRules;
use WorkforceOne\DailyWorkers\SiteRules;
use WorkforceOne\Settings\DailyWorkerSettings;

final class DailyWorkersTest extends TestCase
{
    public function testEgyptianNationalId(): void
    {
        $ok = NationalId::checkEgyptian('29001150101234', '2026-10-09');
        $this->assertTrue($ok['ok']);
        $this->assertSame('1990-01-15', $ok['birth']);
        $this->assertSame('01', $ok['governorate']);
        $this->assertTrue($ok['male']);   // 13th digit 3: odd
        $this->assertSame('1990-01-15', NationalId::checkEgyptian('٢٩٠٠١١٥٠١٠١٢٣٤', '2026-10-09')['birth'], 'Arabic-Indic digits');
        $this->assertTrue(NationalId::checkEgyptian('2900 1150 1012 34', '2026-10-09')['ok']);
        $this->assertSame('2005-03-01', NationalId::checkEgyptian('30503012101245', '2026-10-09')['birth']);
        $this->assertSame('birth', NationalId::checkEgyptian('29013450101234', '2026-10-09')['error'], 'month 13');
        $this->assertSame('birth', NationalId::checkEgyptian('29002300101234', '2026-10-09')['error'], '30 February');
        $this->assertSame('birth', NationalId::checkEgyptian('33001010101234', '2026-10-09')['error'], 'born in the future');
        $this->assertSame('century', NationalId::checkEgyptian('19001150101234', '2026-10-09')['error']);
        $this->assertSame('governorate', NationalId::checkEgyptian('29001155001234', '2026-10-09')['error']);
        $this->assertSame('length', NationalId::checkEgyptian('2900115010123', '2026-10-09')['error']);
        $this->assertSame('digits', NationalId::checkEgyptian('2900115010123A', '2026-10-09')['error']);
    }

    public function testMaskHashAndSeal(): void
    {
        $p = NationalId::maskParts('29001150101234');
        $this->assertSame(['first' => '2900', 'last' => '1234', 'length' => 14], $p);
        $this->assertSame('2900••••••1234', NationalId::mask($p['first'], $p['last'], $p['length']));
        $short = NationalId::maskParts('A1234567');
        $this->assertSame('', $short['first']);
        $this->assertSame('67', $short['last']);
        $key = str_repeat('k', 32);
        $this->assertSame(NationalId::hash('29001150101234', $key), NationalId::hash('2900-1150-1012-34', $key));
        $this->assertNotSame(NationalId::hash('29001150101234', $key), NationalId::hash('29001150101234', str_repeat('x', 32)));
        $sealed = NationalId::seal('29001150101234', $key);
        $this->assertStringStartsWith('v1:', $sealed);
        $this->assertStringNotContainsString('29001150101234', $sealed);
        $this->assertNotSame($sealed, NationalId::seal('29001150101234', $key), 'a new nonce each time');
        $this->assertSame('29001150101234', NationalId::open($sealed, $key));
        $this->assertNull(NationalId::open($sealed, str_repeat('x', 32)), 'wrong key');
        $raw = base64_decode(substr($sealed, 3));
        $raw[30] = chr(ord($raw[30]) ^ 1);
        $this->assertNull(NationalId::open('v1:' . base64_encode($raw), $key), 'tampered');
        $this->assertNull(NationalId::open('garbage', $key));
    }

    public function testDayAmounts(): void
    {
        $this->assertSame(450.0, PayRules::dayAmount('in', 0, 450, 60));
        $this->assertSame(225.0, PayRules::dayAmount('half', 0, 450, 60));
        $this->assertSame(570.0, PayRules::dayAmount('in', 2, 450, 60));
        $this->assertSame(255.0, PayRules::dayAmount('half', 0.5, 450, 60));
        $this->assertSame(0.0, PayRules::dayAmount('out', 3, 450, 60), 'absent: no extra hours');
        $this->assertSame(0.0, PayRules::dayAmount('x', 0, 450, 60));
        $this->assertSame(0.5, PayRules::dayCount('half'));
        $this->assertSame(1.5, PayRules::extra('1.5'));
        $this->assertSame(0.0, PayRules::extra(''));
        $this->assertNull(PayRules::extra('13'));
        $this->assertNull(PayRules::extra('-1'));
        $this->assertNull(PayRules::extra('a'));
    }

    public function testPeriods(): void
    {
        // Thursday 8 October 2026; weeks start on Saturday.
        $this->assertSame(['2026-10-03', '2026-10-09'], PayRules::period('2026-10-08', 'weekly', 6));
        $this->assertSame(['2026-10-10', '2026-10-16'], PayRules::period('2026-10-10', 'weekly', 6));
        $this->assertSame(['2026-10-04', '2026-10-10'], PayRules::period('2026-10-08', 'weekly', 0));
        $this->assertSame(['2026-10-08', '2026-10-08'], PayRules::period('2026-10-08', 'daily', 6));
    }

    public function testPayoutWithAdvances(): void
    {
        $days = [
            ['worker_id' => 1, 'mark' => 'in', 'extra_hours' => 2, 'amount' => 570],
            ['worker_id' => 1, 'mark' => 'half', 'extra_hours' => 0, 'amount' => 225],
            ['worker_id' => 2, 'mark' => 'in', 'extra_hours' => 0, 'amount' => 300],
            ['worker_id' => 2, 'mark' => 'out', 'extra_hours' => 1, 'amount' => 0],
        ];
        $p = PayRules::payout($days, [1 => 500.0, 2 => 450.0]);
        $this->assertSame(1.5, $p['lines'][1]['days']);
        $this->assertSame(2.0, $p['lines'][1]['extra_hours']);
        $this->assertSame(795.0, $p['lines'][1]['amount']);
        $this->assertSame(500.0, $p['lines'][1]['advance']);
        $this->assertSame(295.0, $p['lines'][1]['net']);
        $this->assertSame(300.0, $p['lines'][2]['advance'], 'never more than the payout');
        $this->assertSame(0.0, $p['lines'][2]['net']);
        $this->assertSame(0.0, $p['lines'][2]['extra_hours'], 'absent days add no hours');
        $this->assertSame([1095.0, 800.0, 295.0], [$p['amount'], $p['advance'], $p['net']]);
    }

    public function testSiteRules(): void
    {
        $now = 1791500000;
        $ms = $now * 1000;
        $inside = SiteRules::location(30.0300, 31.4700, 12.0, $ms, $now, 30.0301, 31.4701, 150);
        $this->assertSame('', $inside['error']);
        $this->assertLessThan(150, $inside['distance']);
        $this->assertSame('outside', SiteRules::location(30.0400, 31.4700, 12.0, $ms, $now, 30.0301, 31.4701, 150)['error']);
        $this->assertSame('location', SiteRules::location(null, null, null, null, $now, 30.03, 31.47, 150)['error']);
        $this->assertSame('location', SiteRules::location(30.0300, 31.4700, 12.0, $ms - 3600000, $now, 30.0301, 31.4701, 150)['error'], 'stale phone clock');
        $this->assertSame('site', SiteRules::location(30.0300, 31.4700, 12.0, $ms, $now, '', '', 150)['error']);
        $jump = ['latitude' => 31.2, 'longitude' => 29.9, 'location_timestamp' => $ms - 60000];   // Alexandria a minute ago
        $this->assertSame('suspicious', SiteRules::location(30.0300, 31.4700, 12.0, $ms, $now, 30.0301, 31.4701, 150, $jump)['error']);
        $this->assertTrue(SiteRules::allows('foreman', 'foreman'));
        $this->assertFalse(SiteRules::allows('foreman', 'self'));
        $this->assertTrue(SiteRules::allows('both', 'self'));
        $this->assertFalse(SiteRules::allows('self', 'foreman'));
    }

    public function testSettings(): void
    {
        $c = DailyWorkerSettings::config([]);
        $this->assertSame('foreman', $c['mode']);
        $this->assertSame('weekly', $c['period']);
        $this->assertSame(6, $c['week_start']);
        $this->assertContains('Helper', $c['trades']);
        [$s, $e] = DailyWorkerSettings::fromPost(['dw_mode' => 'both', 'dw_period' => 'daily', 'dw_hourly_rate' => '55.5', 'dw_trades' => "Helper\nCarpenter\nHelper\n ", 'dw_subcontractors' => 'Al-Amal, Al-Nour', 'dw_week_start' => '0']);
        $this->assertSame('', $e);
        $this->assertSame(['Helper', 'Carpenter'], $s['trades']);
        $this->assertSame(['Al-Amal', 'Al-Nour'], $s['subcontractors']);
        $this->assertSame(0, $s['photo']);
        $this->assertSame(55.5, $s['hourly_rate']);
        $this->assertSame('mode', DailyWorkerSettings::fromPost(['dw_mode' => 'x'])[1]);
        $this->assertSame('rate', DailyWorkerSettings::fromPost(['dw_mode' => 'self', 'dw_period' => 'weekly', 'dw_hourly_rate' => '-1'])[1]);
        $this->assertSame('trades', DailyWorkerSettings::fromPost(['dw_mode' => 'self', 'dw_period' => 'weekly', 'dw_trades' => ''])[1]);
        $this->assertSame(['mode' => 'self', 'period' => 'weekly'], DailyWorkerSettings::forSite($c, 'self', ''));
        $this->assertSame(['mode' => 'foreman', 'period' => 'daily'], DailyWorkerSettings::forSite($c, '', 'daily'));
    }
}
