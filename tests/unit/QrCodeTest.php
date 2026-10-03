<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Presence\QrCode as Q;

final class QrCodeTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $p = Q::payload(7, 1000, 'key');
        $this->assertStringStartsWith('wfo1|7|1000|', $p);
        $parts = Q::parse($p);
        $this->assertSame([7, 1000], [$parts['kiosk_id'], $parts['slot']]);
        $this->assertTrue(Q::signatureValid($parts, 'key'));
        $this->assertFalse(Q::signatureValid($parts, 'other key'));
        $this->assertFalse(Q::signatureValid(['kiosk_id' => 8] + $parts, 'key'), 'another kiosk id breaks the signature');
    }

    public function testParseRejects(): void
    {
        foreach (['', 'wfo1|7|1000', 'wfo2|7|1000|' . str_repeat('a', 64), 'wfo1|0|1000|' . str_repeat('a', 64), 'wfo1|x|1000|' . str_repeat('a', 64),
            'wfo1|7|10x|' . str_repeat('a', 64), 'wfo1|7|1000|' . str_repeat('A', 64), 'wfo1|7|1000|abc'] as $bad) {
            $this->assertNull(Q::parse($bad), $bad);
        }
    }

    public function testSlots(): void
    {
        $this->assertSame([0, 0, 1], [Q::slot(0), Q::slot(14), Q::slot(15)]);
        $this->assertSame([true, true, true, false, false], [Q::fresh(10, 10), Q::fresh(9, 10), Q::fresh(11, 10), Q::fresh(8, 10), Q::fresh(12, 10)]);
    }
}
