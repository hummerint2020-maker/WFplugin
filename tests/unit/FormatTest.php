<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Support\Format;

final class FormatTest extends TestCase
{
    public function testNumber(): void
    {
        $this->assertSame('1.5', Format::number(1.5));
        $this->assertSame('2', Format::number(2.0));
        $this->assertSame('0.25', Format::number(0.25));
        $this->assertSame('0', Format::number(-0.001));
    }

    public function testHours(): void
    {
        $this->assertSame('1.67', Format::hours(100));
        $this->assertSame('2', Format::hours(120));
        $this->assertSame('0', Format::hours(0));
    }

    public function testSplit(): void
    {
        $this->assertSame([1, 30], Format::split(90));
        $this->assertSame([0, 45], Format::split(45));
        $this->assertSame([2, 0], Format::split(120));
    }
}
