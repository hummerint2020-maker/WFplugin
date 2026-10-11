<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Locations\LocationRules as L;

final class LocationRulesTest extends TestCase
{
    public function testValid(): void
    {
        $this->assertTrue(L::valid('HQ', '30.0444', '31.2357'));
        $this->assertTrue(L::valid('Pole', '-90', '180'));
        $this->assertSame([false, false, false, false], [L::valid('', '30', '31'), L::valid('HQ', '95', '31'), L::valid('HQ', '30', '-181'), L::valid('HQ', 'x', '31')]);
    }

    public function testRadiusSeatsDefault(): void
    {
        $this->assertSame([10, 200, 5000], [L::radius(3), L::radius(200), L::radius(99999)]);
        $this->assertSame([null, null, 40], [L::seats(0), L::seats(-2), L::seats(40)]);
        $this->assertSame([true, true, false, false], [L::isDefault(true, false, 3), L::isDefault(false, true, 0), L::isDefault(false, true, 2), L::isDefault(false, false, 0)]);
    }
}
