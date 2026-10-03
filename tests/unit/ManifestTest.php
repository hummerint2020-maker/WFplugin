<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Pwa\Manifest as M;

final class ManifestTest extends TestCase
{
    public function testManifest(): void
    {
        $m = M::data('https://hr.example.com/app/', '/', 'https://hr.example.com/wp-content/plugins/workforce-one/');
        $this->assertSame(['name', 'short_name', 'description', 'start_url', 'scope', 'display', 'orientation', 'background_color', 'theme_color', 'icons'], array_keys($m), 'key order is part of the output');
        $this->assertSame(['Employee Hub', 'https://hr.example.com/app/?ews_view=time', '/', 'standalone'], [$m['name'], $m['start_url'], $m['scope'], $m['display']]);
        $this->assertSame('https://hr.example.com/?page_id=5&ews_view=time', M::data('https://hr.example.com/?page_id=5', '/', '/')['start_url']);
        $this->assertSame(['192x192', '512x512'], array_column($m['icons'], 'sizes'));
    }

    public function testIdKeepsInstalledAppsWhenTheStartUrlMoves(): void
    {
        $m = M::data('https://hr.example.com/app/', '/', '/', 'https://hr.example.com/');
        $this->assertSame(['id', 'name'], array_slice(array_keys($m), 0, 2));
        $this->assertSame(['https://hr.example.com/?ews_view=time', 'https://hr.example.com/app/?ews_view=time'], [$m['id'], $m['start_url']]);
        $front = M::data('https://hr.example.com/', '/', '/', 'https://hr.example.com/');
        $this->assertSame($front['id'], $front['start_url'], 'an app on the front page is unchanged');
    }

    public function testServiceWorker(): void
    {
        $sw = M::serviceWorker('https://x.test/wp-content/plugins/workforce-one/', '/wp-content/plugins/workforce-one/', '3.31.38');
        $this->assertSame('employee-hub-v3.31.38', $sw['cache']);
        $this->assertCount(5, $sw['static']);
        $this->assertSame('https://x.test/wp-content/plugins/workforce-one/assets/css/workforce-one.css?ver=3.31.38', $sw['static'][0]);
        $this->assertSame('/wp-content/plugins/workforce-one/assets/', $sw['assets_path']);
    }
}
