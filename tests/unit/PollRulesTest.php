<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Polls\PollRules as P;

final class PollRulesTest extends TestCase
{
    public function testState(): void
    {
        $now = '2026-10-03 12:00:00';
        $this->assertSame(['open', 'scheduled', 'closed', 'hidden', 'archived', 'open'], [
            P::state('active', null, null, $now), P::state('active', '2026-10-04 00:00:00', null, $now), P::state('active', null, '2026-10-03 11:59:00', $now),
            P::state('inactive', null, null, $now), P::state('archived', null, null, $now), P::state('active', '2026-10-01 00:00:00', '2026-10-05 00:00:00', $now),
        ]);
    }

    public function testVoting(): void
    {
        $this->assertTrue(P::inAudience(null, 3));
        $this->assertTrue(P::inAudience(3, 3));
        $this->assertFalse(P::inAudience(3, 4));
        $this->assertFalse(P::inAudience(3, null));
        $this->assertSame(['', 'closed', 'not_for_you', 'already', ''], [P::voteError('open', true, false, false), P::voteError('closed', true, false, true), P::voteError('open', false, false, false), P::voteError('open', true, true, false), P::voteError('open', true, true, true)]);
    }

    public function testResultsVisible(): void
    {
        $this->assertSame([false, true, true], [P::resultsVisible('after_vote', 'open', false), P::resultsVisible('after_vote', 'open', true), P::resultsVisible('after_vote', 'closed', false)]);
        $this->assertSame([false, true, true], [P::resultsVisible('after_close', 'open', true), P::resultsVisible('after_close', 'closed', false), P::resultsVisible('after_close', 'archived', true)]);
        $this->assertFalse(P::resultsVisible('admins', 'closed', true));
    }

    public function testSaveChecks(): void
    {
        $this->assertSame(['question', 'question', 'dates', 'choices_locked', '', ''], [
            P::saveError('', ['a', 'b'], null, null, null), P::saveError('Q', ['a'], null, null, null), P::saveError('Q', ['a', 'b'], '2026-10-05 00:00:00', '2026-10-04 00:00:00', null),
            P::saveError('Q', ['a', 'c'], null, null, ['a', 'b']), P::saveError('Q', ['a', 'b'], null, null, ['a', 'b']), P::saveError('Q', ['a', 'b'], null, null, null),
        ]);
    }

    public function testNumbers(): void
    {
        $this->assertSame([1 => 34, 2 => 33, 3 => 33], P::percentages([1 => 1, 2 => 1, 3 => 1]));
        $this->assertSame(['a' => 0, 'b' => 0], P::percentages(['a' => 0, 'b' => 0]));
        $this->assertSame(['a' => 67, 'b' => 33], P::percentages(['a' => 2, 'b' => 1]));
        $this->assertSame([30, null, 100], [P::participation(12, 40), P::participation(3, 0), P::participation(5, 4)]);
        $now = '2026-10-03 12:00:00';
        $this->assertSame(['Closes in 3 days', 'Closes tomorrow', 'Closes in 5 hours', 'Closes soon', '', ''], [
            P::closesLabel('2026-10-06 13:00:00', $now), P::closesLabel('2026-10-04 13:00:00', $now), P::closesLabel('2026-10-03 17:30:00', $now), P::closesLabel('2026-10-03 12:30:00', $now), P::closesLabel(null, $now), P::closesLabel('2026-10-03 11:00:00', $now),
        ]);
    }
}
