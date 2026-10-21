<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Approvals\Substitutes as S;

/** A substitute while a manager is away (3.31.107): the settings, the checks, the states over time. */
final class SubstitutesTest extends TestCase
{
    private function f(array $over = []): array
    {
        return array_merge(['manager' => 3, 'substitute' => 7, 'starts' => '2026-10-20 00:00:00', 'ends' => '2026-10-25 00:00:00',
            'now' => '2026-10-17 10:00:00', 'eligible' => true, 'away' => false, 'overlap' => false], $over);
    }

    public function testSettingsDefaultsAndBadValues(): void
    {
        $this->assertSame(['manager_decides' => 1, 'on_return' => 'back', 'who' => 'any', 'ask_on_leave' => 'optional'], S::settings(null));
        $this->assertSame(['manager_decides' => 0, 'on_return' => 'stay', 'who' => 'department', 'ask_on_leave' => 'required'],
            S::settings(['manager_decides' => 0, 'on_return' => 'stay', 'who' => 'department', 'ask_on_leave' => 'required']));
        $this->assertSame(S::DEFAULTS, S::settings(['on_return' => 'x', 'who' => 'boss', 'ask_on_leave' => 'maybe']));
    }

    public function testDateTime(): void
    {
        $this->assertSame('2026-10-20 14:30:00', S::dateTime('2026-10-20T14:30'));
        $this->assertSame('2026-10-20 14:30:05', S::dateTime('2026-10-20 14:30:05'));
        $this->assertSame('', S::dateTime('2026-02-30T10:00'));
        $this->assertSame('', S::dateTime('2026-10-20T25:00'));
        $this->assertSame('', S::dateTime('tomorrow'));
    }

    public function testLeavePeriodCoversTheLastDay(): void
    {
        $this->assertSame(['2026-10-20 00:00:00', '2026-10-25 00:00:00'], S::leavePeriod('2026-10-20', '2026-10-24'));
        $this->assertSame(['2026-12-31 00:00:00', '2027-01-01 00:00:00'], S::leavePeriod('2026-12-31', '2026-12-31'));
    }

    public function testCheck(): void
    {
        $this->assertNull(S::check($this->f()));
        $this->assertNull(S::check($this->f(['starts' => '2026-10-17 09:00:00'])), 'started an hour ago: it starts now');
        $this->assertSame('same', S::check($this->f(['substitute' => 3])));
        $this->assertSame('dates', S::check($this->f(['ends' => '2026-10-20 00:00:00'])));
        $this->assertSame('dates', S::check($this->f(['starts' => ''])));
        $this->assertSame('past', S::check($this->f(['starts' => '2026-10-10 00:00:00', 'ends' => '2026-10-17 10:00:00'])));
        $this->assertSame('who', S::check($this->f(['eligible' => false])));
        $this->assertSame('away', S::check($this->f(['away' => true])));
        $this->assertSame('overlap', S::check($this->f(['overlap' => true])));
        $this->assertSame('manager', S::check($this->f(['manager' => 0])));
        $this->assertSame('substitute', S::check($this->f(['substitute' => 0])));
    }

    public function testOverlapsEndExcluded(): void
    {
        $this->assertTrue(S::overlaps('2026-10-20 00:00:00', '2026-10-25 00:00:00', '2026-10-24 12:00:00', '2026-10-26 00:00:00'));
        $this->assertFalse(S::overlaps('2026-10-20 00:00:00', '2026-10-25 00:00:00', '2026-10-25 00:00:00', '2026-10-26 00:00:00'), 'back-to-back');
    }

    public function testTransition(): void
    {
        $a = '2026-10-20 09:00:00'; $b = '2026-10-20 17:00:00';
        $this->assertSame('', S::transition('scheduled', $a, $b, '2026-10-20 08:59:59'));
        $this->assertSame('start', S::transition('scheduled', $a, $b, '2026-10-20 09:00:00'));
        $this->assertSame('', S::transition('active', $a, $b, '2026-10-20 16:59:59'));
        $this->assertSame('end', S::transition('active', $a, $b, '2026-10-20 17:00:00'));
        $this->assertSame('expire', S::transition('scheduled', $a, $b, '2026-10-21 00:00:00'), 'never started (the site was off)');
        $this->assertSame('', S::transition('pending', $a, $b, '2026-10-20 10:00:00'), 'waits for its leave');
        $this->assertSame('', S::transition('cancelled', $a, $b, '2026-10-20 10:00:00'));
    }

    public function testFollowsItsLeave(): void
    {
        $this->assertSame('schedule', S::forLeave('pending', 'Approved'));
        $this->assertSame('', S::forLeave('pending', 'Pending'));
        $this->assertSame('cancel', S::forLeave('pending', 'Rejected'));
        $this->assertSame('cancel', S::forLeave('active', 'Cancelled'), 'a leave cancelled after approval ends it');
        $this->assertSame('', S::forLeave('scheduled', 'Approved'));
        $this->assertSame('', S::forLeave('ended', 'Cancelled'));
    }

    public function testEligible(): void
    {
        $c = ['active' => true, 'has_reports' => false, 'same_department' => false];
        $this->assertTrue(S::eligible('any', $c));
        $this->assertFalse(S::eligible('managers', $c));
        $this->assertTrue(S::eligible('managers', ['has_reports' => true] + $c));
        $this->assertFalse(S::eligible('department', $c));
        $this->assertTrue(S::eligible('department', ['same_department' => true] + $c));
        $this->assertFalse(S::eligible('any', ['active' => false] + $c));
    }
}
