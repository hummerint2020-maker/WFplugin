<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\OfflineScreen as O;

final class OfflineScreenTest extends TestCase
{
    public function testOffByDefaultWithTheApprovedEnglishTexts(): void
    {
        $c = O::config(null);
        $this->assertSame([0, 20, 1, 1], [$c['enabled'], $c['timeout'], $c['schedule'], $c['keep_note']]);
        $this->assertSame('No internet connection', $c['texts']['off_title']);
        $this->assertSame('Workforce One is not responding', $c['texts']['down_title']);
        $this->assertSame([], $c['saved']);
        $this->assertSame(array_keys(O::TEXTS), array_keys($c['texts']));
    }

    public function testEveryTextIsInOneSectionOfTheForm(): void
    {
        $inForm = array_merge(...array_values(O::SECTIONS));
        sort($inForm);
        $all = array_keys(O::TEXTS);
        sort($all);
        $this->assertSame($all, $inForm);
    }

    public function testFormTextsAreCleanedAndAnEmptyOrDefaultOneIsNotKept(): void
    {
        $s = O::fromPost(['offline_enabled' => '1', 'offline_timeout' => '200', 'offline_schedule' => '', 'offline_texts' => [
            'off_title' => '  <b>No   network</b> ', 'retry' => 'Try again', 'down_msg' => "Line one\r\n\r\n\r\n\r\nLine  two", 'toast0' => '', 'unknown' => 'x',
            'ns_title' => str_repeat('a', 500),
        ]]);
        $this->assertSame([1, 60, 0, 0], [$s['enabled'], $s['timeout'], $s['schedule'], $s['keep_note']]);
        $this->assertSame('No network', $s['texts']['off_title']);
        $this->assertSame("Line one\n\nLine two", $s['texts']['down_msg']);
        $this->assertArrayNotHasKey('retry', $s['texts'], 'the same as the default: not kept');
        $this->assertArrayNotHasKey('toast0', $s['texts']);
        $this->assertArrayNotHasKey('unknown', $s['texts']);
        $this->assertSame(80, strlen($s['texts']['ns_title']));
        $c = O::config($s);
        $this->assertSame('No network', $c['texts']['off_title']);
        $this->assertSame('Try again', $c['texts']['retry']);
        $this->assertSame(5, O::config(['timeout' => 1])['timeout']);
    }

    public function testTheRevisionFollowsWhatTheScreenShows(): void
    {
        $a = O::config(['enabled' => 1]);
        $b = O::config(['enabled' => 1, 'texts' => ['off_title' => 'Other']]);
        $this->assertSame(O::revision($a), O::revision(O::config(['enabled' => 1])));
        $this->assertNotSame(O::revision($a), O::revision($b));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', O::revision($a));
    }
}
