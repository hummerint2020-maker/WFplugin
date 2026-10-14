<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\OrgChartSettings as S;

final class OrgChartSettingsTest extends TestCase
{
    public function testOffByDefault(): void
    {
        $this->assertSame(['enabled' => 0, 'audience' => 'admins', 'employee_view' => 'line', 'wide_span' => 8, 'title_required' => 0], S::config(null));
        $this->assertSame(S::config([]), S::config('junk'));
    }

    public function testUnknownValuesFallBack(): void
    {
        $c = S::config(['enabled' => '1', 'audience' => 'robots', 'employee_view' => 'all', 'wide_span' => 'x']);
        $this->assertSame(['enabled' => 1, 'audience' => 'admins', 'employee_view' => 'line', 'wide_span' => 8, 'title_required' => 0], $c);
        $this->assertSame(0, S::config(['wide_span' => -4])['wide_span']);
        $this->assertSame(100, S::config(['wide_span' => 1000])['wide_span']);
    }

    public function testFromPost(): void
    {
        $c = S::fromPost(['org_enabled' => '1', 'org_audience' => 'everyone', 'org_employee_view' => 'company', 'org_wide_span' => '5', 'org_title_required' => '1']);
        $this->assertSame(['enabled' => 1, 'audience' => 'everyone', 'employee_view' => 'company', 'wide_span' => 5, 'title_required' => 1], $c);
        $this->assertSame(0, S::fromPost([])['enabled'], 'an unticked box is off');
    }

    public function testWhoMayOpenMyTeam(): void
    {
        $on = static function (string $audience): array { return S::config(['enabled' => 1, 'audience' => $audience]); };
        $this->assertFalse(S::appAllowed(S::config([]), true, true), 'off: nobody');
        $this->assertFalse(S::appAllowed($on('admins'), true, true), 'administrators only: in wp-admin, not the app');
        $this->assertTrue(S::appAllowed($on('managers'), false, true));
        $this->assertTrue(S::appAllowed($on('managers'), true, false));
        $this->assertFalse(S::appAllowed($on('managers'), false, false));
        $this->assertTrue(S::appAllowed($on('everyone'), false, false));
    }

    public function testTitleIsShortAndPlain(): void
    {
        $this->assertSame('Sales Lead', S::title("  Sales\n <b>Lead</b> "));
        $this->assertSame('', S::title(['x']));
        $this->assertSame(120, mb_strlen(S::title(str_repeat('مدير ', 60))));
    }
}
