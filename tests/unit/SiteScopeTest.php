<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Support\SiteScope;

/** What differs between the sites of a network (3.31.147, docs/tasks/saas.md §3); a single site keeps today's values. */
final class SiteScopeTest extends TestCase
{
    public function testPrivateBaseIsUnchangedOnASingleSite(): void
    {
        $this->assertSame('/srv/private', SiteScope::privateBase('/srv/private', false, true, 1));
        $this->assertSame('/srv/private', SiteScope::privateBase('/srv/private', false, false, 7), 'not a network: the other flags do not matter');
    }

    public function testPrivateBaseOfTheMainSiteIsUnchanged(): void
    {
        $this->assertSame('/srv/private', SiteScope::privateBase('/srv/private', true, true, 1));
    }

    public function testPrivateBaseOfAnotherSiteHasItsOwnFolder(): void
    {
        $this->assertSame('/srv/private/sites/7', SiteScope::privateBase('/srv/private', true, false, 7));
        $this->assertNotSame(SiteScope::privateBase('/srv/private', true, false, 7), SiteScope::privateBase('/srv/private', true, false, 8));
    }

    public function testNoBaseMeansUploadsEverywhere(): void
    {
        $this->assertSame('', SiteScope::privateBase('', false, true, 1));
        $this->assertSame('', SiteScope::privateBase('', true, true, 1));
        $this->assertSame('', SiteScope::privateBase('', true, false, 7), 'uploads is already one folder per site');
    }

    public function testUserKey(): void
    {
        $this->assertSame('ews_face_recent', SiteScope::userKey('ews_face_recent', false, 'wp_'));
        $this->assertSame('ews_face_recent', SiteScope::userKey('ews_face_recent', false, 'wp_7_'), 'single site: the prefix is ignored');
        $this->assertSame('wp_ews_face_recent', SiteScope::userKey('ews_face_recent', true, 'wp_'));
        $this->assertSame('wp_7_ews_face_recent', SiteScope::userKey('ews_face_recent', true, 'wp_7_'));
    }

    public function testNginxRuleOfASingleSiteIsTheOldText(): void
    {
        $this->assertSame('location ~* ^/wp-content/uploads/workforce-one-(corrections|tasks|daily-workers|face-photos)/ { deny all; }', SiteScope::nginxRule(false));
    }

    public function testNginxRuleOfANetworkAlsoCoversTheSitesFolders(): void
    {
        $rule = SiteScope::nginxRule(true);
        $this->assertSame('location ~* ^/wp-content/uploads/(sites/\d+/)?workforce-one-(corrections|tasks|daily-workers|face-photos)/ { deny all; }', $rule);
        $this->assertSame(1, preg_match('~^/wp-content/uploads/(sites/\d+/)?workforce-one-(corrections|tasks|daily-workers|face-photos)/~', '/wp-content/uploads/sites/7/workforce-one-face-photos/a.jpg'));
        $this->assertSame(1, preg_match('~^/wp-content/uploads/(sites/\d+/)?workforce-one-(corrections|tasks|daily-workers|face-photos)/~', '/wp-content/uploads/workforce-one-tasks/a.jpg'));
    }
}
