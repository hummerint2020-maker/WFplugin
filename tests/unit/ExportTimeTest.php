<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Support\ExportTime;

/** 3.31.149: an export asks for more time between its parts, never less, and never calls a function the host turned off. */
final class ExportTimeTest extends TestCase
{
    private $was;

    protected function setUp(): void
    {
        $this->was = ini_get('max_execution_time');
    }

    protected function tearDown(): void
    {
        set_time_limit((int) $this->was);
    }

    public function testNoLimitStaysNoLimit(): void
    {
        set_time_limit(0);
        ExportTime::extend(120);
        $this->assertSame('0', ini_get('max_execution_time'));
    }

    public function testAShortLimitIsRaised(): void
    {
        set_time_limit(30);
        ExportTime::extend(120);
        $this->assertSame('120', ini_get('max_execution_time'));
    }

    public function testALongerLimitIsKept(): void
    {
        set_time_limit(300);
        ExportTime::extend(120);
        $this->assertSame('300', ini_get('max_execution_time'));
    }
}
