<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Schedule\SwapInbox;

final class SwapInboxTest extends TestCase
{
    private const TODAY = '2026-10-24';

    public function testState(): void
    {
        $this->assertSame('waiting', SwapInbox::state('Pending', '2026-10-25', self::TODAY));
        $this->assertSame('waiting', SwapInbox::state('Pending', self::TODAY, self::TODAY), 'today is not past');
        $this->assertSame('expired', SwapInbox::state('Pending', '2026-10-23', self::TODAY), 'its day passed before the colleague answered');
        $this->assertSame('waiting', SwapInbox::state('Awaiting', '2026-10-23', self::TODAY), 'with the approvers');
        $this->assertSame('approved', SwapInbox::state('Accepted', '2026-10-25', self::TODAY));
        $this->assertSame('approved', SwapInbox::state('Approved', '2026-10-25', self::TODAY));
        $this->assertSame('rejected', SwapInbox::state('Rejected', '2026-10-25', self::TODAY));
        $this->assertSame('rejected', SwapInbox::state('Declined', '2026-10-25', self::TODAY));
        $this->assertSame('withdrawn', SwapInbox::state('Cancelled', '2026-10-25', self::TODAY));
        $this->assertSame('expired', SwapInbox::state('Expired', '2026-10-25', self::TODAY));
        $this->assertSame('cancelled', SwapInbox::state('Something else', '2026-10-25', self::TODAY));
    }

    public function testWaitsForPeerAndWithdraw(): void
    {
        $this->assertTrue(SwapInbox::waitsForPeer('Pending', '2026-10-25', self::TODAY));
        $this->assertTrue(SwapInbox::waitsForPeer('Pending', self::TODAY, self::TODAY));
        $this->assertFalse(SwapInbox::waitsForPeer('Pending', '2026-10-20', self::TODAY));
        $this->assertFalse(SwapInbox::waitsForPeer('Awaiting', '2026-10-25', self::TODAY), 'the colleague already answered');
        $this->assertTrue(SwapInbox::canWithdraw('Pending', '2026-10-25', self::TODAY));
        $this->assertFalse(SwapInbox::canWithdraw('Awaiting', '2026-10-25', self::TODAY), 'not once it waits for the approvers (owner decision)');
        $this->assertFalse(SwapInbox::canWithdraw('Pending', '2026-10-20', self::TODAY));
        $this->assertFalse(SwapInbox::canWithdraw('Accepted', '2026-10-25', self::TODAY));
    }
}
