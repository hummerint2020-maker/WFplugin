<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Approvals\LevelGuard;

final class LevelGuardTest extends TestCase
{
    public function testPosted(): void
    {
        $this->assertNull(LevelGuard::posted(null), 'the form did not say');
        $this->assertSame(42, LevelGuard::posted('42'));
        $this->assertSame(42, LevelGuard::posted(' 42 '));
        $this->assertSame(0, LevelGuard::posted('0'), 'no open level shown');
        $this->assertSame(7, LevelGuard::posted(7));
        $this->assertSame(0, LevelGuard::posted(-3));
        foreach (['', 'abc', '-1', '4.5', '1e3', '12abc', '9999999999999999999999'] as $bad) $this->assertSame(0, LevelGuard::posted($bad), var_export($bad, true));
        $this->assertSame(0, LevelGuard::posted(['42']), 'an array');
        $this->assertSame(0, LevelGuard::posted(4.0));
    }

    public function testCheck(): void
    {
        $this->assertSame(LevelGuard::OK, LevelGuard::check(null, 15), 'no level posted: the current one, as before');
        $this->assertSame(LevelGuard::OK, LevelGuard::check(null, 0));
        $this->assertSame(LevelGuard::OK, LevelGuard::check(15, 15));
        $this->assertSame(LevelGuard::OK, LevelGuard::check(0, 0), 'none shown, none open');
        $this->assertSame(LevelGuard::MOVED, LevelGuard::check(15, 16), 'level 1 shown, level 2 open');
        $this->assertSame(LevelGuard::MOVED, LevelGuard::check(15, 0), 'shown open, decided since');
        $this->assertSame(LevelGuard::MOVED, LevelGuard::check(0, 16), 'none shown, one open now');
    }
}
