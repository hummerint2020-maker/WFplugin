<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Support\Picker;

final class PickerTest extends TestCase
{
    public function testLabel(): void
    {
        $this->assertSame('Sara Ali (sara) · #12', Picker::label(' Sara Ali (sara) ', 12));
        $this->assertSame('', Picker::label('Nobody', 0));
    }

    public function testParse(): void
    {
        $this->assertSame(0, Picker::parse(''));
        $this->assertSame(0, Picker::parse('   '));
        $this->assertSame(12, Picker::parse('Sara Ali (sara) · #12'));
        $this->assertSame(12, Picker::parse('Sara #3 Ali · #12 '));
        $this->assertSame(7, Picker::parse('7'));
        $this->assertSame(-1, Picker::parse('Sara Ali'));
        $this->assertSame(-1, Picker::parse('#'));
        $this->assertSame(12, Picker::parse(Picker::label('Name with #99 inside', 12)));
    }
}
