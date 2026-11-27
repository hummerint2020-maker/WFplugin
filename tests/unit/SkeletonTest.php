<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Ui\Skeleton;

final class SkeletonTest extends TestCase
{
    public function testTheThreeScreensHaveTheirOwnOutline(): void
    {
        $this->assertSame('list', Skeleton::shape('requests'));
        $this->assertSame('approvals', Skeleton::shape('approvals'));
        $this->assertSame('table', Skeleton::shape('schedule'));
    }

    public function testAnyOtherViewIsGeneric(): void
    {
        foreach (['dashboard', 'time', 'profile', 'pay', '', 'not-a-view'] as $view) {
            $this->assertSame('generic', Skeleton::shape($view), $view);
        }
    }

    public function testEveryOutlineIsOneTheScriptDraws(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/workforce-one/assets/js/skeleton.js');
        foreach (['requests', 'approvals', 'schedule', 'vacation', 'dashboard', 'attendance'] as $view) {
            $shape = Skeleton::shape($view);
            $this->assertContains($shape, Skeleton::SHAPES);
            $this->assertStringContainsString($shape . ':', $js, $shape);
        }
    }
}
