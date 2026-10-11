<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Achievements\ManualGrant as G;

final class ManualGrantTest extends TestCase
{
    private function f(array $over = []): array
    {
        return array_merge(['name' => 'Team Player', 'description' => '', 'icon' => '🤝', 'style' => 'star', 'employee_found' => true, 'enabled' => true], $over);
    }

    public function testValidGrant(): void
    {
        [$v, $e] = G::check($this->f());
        $this->assertNull($e);
        $this->assertSame(['name' => 'Team Player', 'description' => G::DEFAULT_DESCRIPTION, 'icon' => '🤝', 'style' => 'star'], $v);
    }

    public function testFallbacksAndErrors(): void
    {
        [$v] = G::check($this->f(['icon' => '<script>', 'style' => 'hexagon']));
        $this->assertSame('🏅', $v['icon']);
        $this->assertSame('circle', $v['style']);
        $this->assertSame('disabled', G::check($this->f(['enabled' => false]))[1]);
        $this->assertSame('required', G::check($this->f(['name' => '  ']))[1]);
        $this->assertSame('employee', G::check($this->f(['employee_found' => false]))[1]);
    }

    public function testProgress(): void
    {
        $this->assertSame(['pct' => 80, 'near' => true], G::progress(8, 10));
        $this->assertSame(['pct' => 50, 'near' => false], G::progress(5, 10));
        $this->assertSame(['pct' => 100, 'near' => false], G::progress(10, 10));
        $this->assertSame(['pct' => 100, 'near' => false], G::progress(0, 0));
    }
}
