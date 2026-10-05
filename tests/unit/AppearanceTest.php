<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\Appearance as A;
use WorkforceOne\Ui\Icons;
use WorkforceOne\Ui\MobileBar;

final class AppearanceTest extends TestCase
{
    public function testDefaultsAndInvalidValuesFallBack(): void
    {
        $this->assertSame(A::DEFAULTS, A::config(null));
        $this->assertSame(A::DEFAULTS, A::config('junk'));
        $c = A::config(['preset' => 'nope', 'primary' => 'red', 'font' => 'comic', 'corners' => 'x', 'header_style' => 'x', 'logo_url' => 'javascript:alert(1)']);
        $this->assertSame('indigo', $c['preset']);
        $this->assertSame(A::DEFAULTS['primary'], $c['primary'], 'a colour that is not hex is ignored');
        $this->assertSame('alexandria', $c['font']);
        $this->assertSame('round', $c['corners']);
        $this->assertSame('gradient', $c['header_style']);
        $this->assertSame('', $c['logo_url'], 'only http(s) logo URLs');
        $this->assertSame('https://x.test/logo.svg', A::config(['logo_url' => 'https://x.test/logo.svg'])['logo_url']);
        $this->assertSame('', A::config(['company_name' => ''])['company_name'], 'an empty name is allowed (hidden)');
        $this->assertSame(60, mb_strlen(A::config(['app_name' => str_repeat('ش', 80)])['app_name']));
    }

    public function testHexAndContrast(): void
    {
        $this->assertSame('#AABBCC', A::hex('abc'));
        $this->assertSame('#13235B', A::hex(' #13235b '));
        $this->assertSame('', A::hex('#12345'));
        $this->assertSame('', A::hex('url(x)'));
        $this->assertEqualsWithDelta(21.0, A::contrast('#000000', '#FFFFFF'), 0.01);
        $this->assertEqualsWithDelta(1.0, A::contrast('#777777', '#777777'), 0.01);
        $this->assertSame('#13235B', A::onColor('#FFC83D', '#13235B'), 'dark text on a light highlight');
        $this->assertSame('#FFFFFF', A::onColor('#13235B', '#000000'));
        $this->assertSame('#808080', A::mix('#000000', '#FFFFFF', 0.5));
    }

    public function testEveryPresetIsReadable(): void
    {
        foreach (A::PRESETS as $key => $p) {
            $this->assertGreaterThanOrEqual(A::MIN_CONTRAST, A::contrast($p['header_start'], '#FFFFFF'), "$key header start");
            $this->assertGreaterThanOrEqual(A::MIN_CONTRAST, A::contrast($p['header_end'], '#FFFFFF'), "$key header end");
            $this->assertGreaterThanOrEqual(A::MIN_CONTRAST, A::contrast($p['primary'], '#FFFFFF'), "$key primary");
            $this->assertGreaterThanOrEqual(A::MIN_CONTRAST, A::contrast($p['highlight'], A::onColor($p['highlight'], $p['header_start'])), "$key highlight");
            $this->assertArrayHasKey($p['font'], A::FONTS);
            $r = A::fromPost(['preset' => $key, 'preset_applied' => '1'], null);
            $this->assertSame([], $r['errors'], $key);
            $this->assertSame($key, $r['config']['preset']);
            $this->assertSame($p['font'], $r['config']['font']);
        }
    }

    public function testSaveRefusesUnreadableColours(): void
    {
        $prev = A::config([]);
        $r = A::fromPost(['header_start' => '#FFF8E1', 'header_end' => '#13235B', 'highlight' => '#FFC83D', 'primary' => '#5B3FD9'], $prev);
        $this->assertSame($prev['header_start'], $r['config']['header_start'], 'too light for white text: kept the previous colour');
        $this->assertCount(1, $r['errors']);
        $this->assertStringContainsString('too light for white text', $r['errors'][0]);

        $r = A::fromPost(['header_start' => '#13235B', 'header_end' => '#13235B', 'highlight' => '#FFC83D', 'primary' => '#9CA3AF'], $prev);
        $this->assertSame($prev['primary'], $r['config']['primary']);
        $this->assertStringContainsString('too light to read on white', implode(' ', $r['errors']));

        $r = A::fromPost(['header_start' => '#13235B', 'header_end' => '#FFFFFF', 'header_style' => 'solid', 'highlight' => '#FFC83D', 'primary' => '#5B3FD9'], $prev);
        $this->assertSame([], $r['errors'], 'with a solid header the end colour is not used, so not checked');

        $r = A::fromPost(['header_start' => 'blue', 'header_end' => '', 'highlight' => '#FFC83D', 'primary' => '#5B3FD9'], $prev);
        $this->assertSame($prev['header_start'], $r['config']['header_start']);
        $this->assertStringContainsString('is not a colour', $r['errors'][0]);
        $this->assertSame($prev['header_end'], $r['config']['header_end'], 'an empty field keeps the previous colour');
    }

    public function testCustomColoursAreRecognised(): void
    {
        $r = A::fromPost(['preset' => 'indigo', 'header_start' => '#0B3B3A', 'header_end' => '#11796C', 'highlight' => '#F2C14E', 'primary' => '#0F7C70'], null);
        $this->assertSame('nile', $r['config']['preset'], 'the colours of a preset are that preset');
        $r = A::fromPost(['preset' => 'indigo', 'header_start' => '#102030', 'header_end' => '#13235B', 'highlight' => '#FFC83D', 'primary' => '#5B3FD9'], null);
        $this->assertSame('custom', $r['config']['preset']);
    }

    public function testCssVariables(): void
    {
        $css = A::cssVars(A::config(['corners' => 'sharp', 'font' => 'system']), '.x');
        $this->assertStringStartsWith('.x{', $css);
        $this->assertStringContainsString('--wfo-hero:linear-gradient(135deg,#13235B 0%,', $css);
        $this->assertStringContainsString('--wfo-pri:#5B3FD9;', $css);
        $this->assertStringContainsString('--purple:#5B3FD9;', $css, 'older pages follow the theme');
        $this->assertStringContainsString('--wfo-r-card:6px;', $css);
        $this->assertStringContainsString('--wfo-hl-ink:#13235B;', $css);
        $this->assertStringNotContainsString("'Alexandria'", $css);
        $solid = A::cssVars(A::config(['header_style' => 'solid']), '.x');
        $this->assertStringContainsString('--wfo-hero:#13235B;', $solid);
        $this->assertStringNotContainsString('<', $css . $solid);
    }

    public function testFontFaces(): void
    {
        $url = function ($f) { return 'https://s.test/f/' . $f; };
        $this->assertSame('', A::fontFaces('system', $url));
        $this->assertSame('', A::fontFaces('nope', $url));
        $alex = A::fontFaces('alexandria', $url);
        $this->assertSame(2, substr_count($alex, '@font-face'), 'one variable file per subset');
        $this->assertStringContainsString("font-weight:400 700;", $alex);
        $this->assertStringContainsString('https://s.test/f/alexandria-arabic.woff2', $alex);
        $plex = A::fontFaces('plex', $url);
        $this->assertSame(8, substr_count($plex, '@font-face'));
        foreach (array_keys(A::FONTS) as $key) {
            if ($key === 'system') continue;
            preg_match_all('#https://s\.test/f/([a-z0-9-]+\.woff2)#', A::fontFaces($key, $url), $m);
            foreach ($m[1] as $file) $this->assertFileExists(dirname(__DIR__, 2) . '/workforce-one/assets/fonts/' . $key . '/' . $file);
        }
    }

    public function testMobileBar(): void
    {
        $items = array_map(function ($k) { return ['key' => $k]; }, ['dashboard', 'schedule', 'time', 'vacation', 'attendance', 'people', 'reports']);
        $bar = MobileBar::split($items);
        $this->assertSame('time', $bar['center']['key']);
        $this->assertSame(['dashboard', 'schedule'], array_column($bar['start'], 'key'));
        $this->assertSame(['vacation'], array_column($bar['end'], 'key'));
        $this->assertSame(['attendance', 'people', 'reports'], array_column($bar['more'], 'key'));

        $bar = MobileBar::split(array_map(function ($k) { return ['key' => $k]; }, ['dashboard', 'schedule', 'vacation', 'tasks', 'polls']));
        $this->assertNull($bar['center'], 'Sign In / Out hidden on mobile: no middle button');
        $this->assertSame(['dashboard', 'schedule'], array_column($bar['start'], 'key'));
        $this->assertSame(['vacation', 'tasks'], array_column($bar['end'], 'key'));
        $this->assertSame(['polls'], array_column($bar['more'], 'key'));

        $bar = MobileBar::split([['key' => 'time']]);
        $this->assertSame([[], [], []], [$bar['start'], $bar['end'], $bar['more']]);

        $this->assertSame('', MobileBar::clockState([], false));
        $this->assertSame('out', MobileBar::clockState([], true));
        $this->assertSame('in', MobileBar::clockState(['late_sign_in' => 1], true));
        $this->assertSame('done', MobileBar::clockState(['sign_in' => 1, 'sign_out' => 1], true));
    }

    public function testIcons(): void
    {
        $svg = Icons::forView('time');
        $this->assertStringStartsWith('<svg class="wfo-icon"', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringContainsString('stroke="currentColor"', $svg);
        foreach (\WorkforceOne\Settings\Navigation::DEFAULTS as $key => $_) {
            $this->assertNotSame(Icons::svg('more'), Icons::forView($key), "menu item $key has its own icon");
        }
    }

    public function testStatusIcons(): void
    {
        $this->assertSame(['office', 'office'], Icons::forStatus('Office'));
        $this->assertSame(['wfh', 'wfh'], Icons::forStatus('WFH'));
        $this->assertSame(['leave', 'leave'], Icons::forStatus('Vacation'));
        $this->assertSame(['leave', 'leave'], Icons::forStatus('General Leave'));
        $this->assertSame(['alert', 'absent'], Icons::forStatus('Absent'));
        $this->assertSame(['briefcase', 'away'], Icons::forStatus('Training Course'), 'a custom type is shown as away, with an icon');
        $this->assertSame(['calendar', 'none'], Icons::forStatus('Not Set'));
        $this->assertTrue(Icons::has('briefcase'));
        foreach (['swap', 'download', 'share', 'mail'] as $name) $this->assertTrue(Icons::has($name), $name);
    }

    public function testClockFace(): void
    {
        $this->assertSame('out', \WorkforceOne\Ui\ClockFace::state([], false));
        $this->assertSame('in', \WorkforceOne\Ui\ClockFace::state(['late_sign_in' => 1], false));
        $this->assertSame('break', \WorkforceOne\Ui\ClockFace::state(['sign_in' => 1], true));
        $this->assertSame('done', \WorkforceOne\Ui\ClockFace::state(['sign_in' => 1, 'sign_out' => 1], true));
        $b = ['start' => 1000, 'cutoff' => 2000, 'end' => 5000];
        $this->assertEqualsWithDelta(0.5, \WorkforceOne\Ui\ClockFace::progress('out', 1500, $b, null), 0.001, 'half the Sign In window has passed');
        $this->assertSame(0.0, \WorkforceOne\Ui\ClockFace::progress('out', 500, $b, null), 'before the window');
        $this->assertSame(1.0, \WorkforceOne\Ui\ClockFace::progress('out', 9000, $b, null), 'clamped');
        $this->assertEqualsWithDelta(0.25, \WorkforceOne\Ui\ClockFace::progress('in', 2100, $b, 1100), 0.001, 'a quarter of the shift worked');
        $this->assertSame(0.0, \WorkforceOne\Ui\ClockFace::progress('in', 2100, ['start' => 0, 'cutoff' => 0, 'end' => 0], 1100), 'no working hours: empty ring');
        $this->assertSame(1.0, \WorkforceOne\Ui\ClockFace::progress('done', 0, $b, null));
    }
}
