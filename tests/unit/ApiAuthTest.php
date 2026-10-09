<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Api\Auth\Bearer;
use WorkforceOne\Api\Auth\LoginInput;
use WorkforceOne\Api\Auth\LoginThrottle as LT;
use WorkforceOne\Api\Auth\Tokens as T;
use WorkforceOne\Api\ErrorMap as E;
use WorkforceOne\Api\Meta;
use WorkforceOne\Api\Routes;

final class ApiAuthTest extends TestCase
{
    public function testTokenFormatAndSite(): void
    {
        $site = T::siteId('https://hr.example.com/');
        $this->assertSame($site, T::siteId('HTTPS://HR.EXAMPLE.COM'), 'the same site whatever the case or trailing slash');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $site);
        $a = T::make(T::ACCESS, $site, random_bytes(32));
        $r = T::make(T::REFRESH, $site, random_bytes(32));
        $this->assertMatchesRegularExpression('/^wfo_at_' . $site . '_[A-Za-z0-9_-]{43}$/', $a);
        $this->assertSame(T::ACCESS, T::kindOf($a, $site));
        $this->assertSame(T::REFRESH, T::kindOf($r, $site, T::REFRESH));
        $this->assertNull(T::kindOf($r, $site, T::ACCESS), 'a refresh token is not an access token');
        $this->assertNull(T::kindOf($a, T::siteId('https://other.example.com')), 'a token of another site');
        foreach (['', 'abc', $a . 'x', substr($a, 0, -1), str_replace('wfo_at_', 'wfo_xx_', $a), "$a\n", 'Bearer ' . $a, 'wfo_at_' . $site . '_' . str_repeat('+', 43)] as $bad) {
            $this->assertNull(T::kindOf($bad, $site), var_export($bad, true));
        }
        $this->assertNotSame(T::make(T::ACCESS, $site, random_bytes(32)), $a, 'random every time');
        $h = T::hash($a, 'secret-1');
        $this->assertSame(64, strlen($h));
        $this->assertNotSame($h, T::hash($a, 'secret-2'), 'keyed by the site secret');
        $this->assertStringNotContainsString(substr($a, 16), $h);
    }

    public function testTokenStates(): void
    {
        $now = 1_800_000_000;
        $this->assertSame(T::OK, T::accessStatus(['expires_at' => $now + 1], $now));
        $this->assertSame(T::EXPIRED, T::accessStatus(['expires_at' => $now], $now), 'expired at the second it ends');
        $this->assertSame(T::REVOKED, T::accessStatus(['expires_at' => $now + 9, 'revoked_at' => '2026-01-01'], $now));
        $this->assertSame(T::DEVICE_REVOKED, T::accessStatus(['expires_at' => $now - 9, 'revoked_at' => 'x', 'device_revoked_at' => 'y'], $now), 'device revocation is reported first');
        $ok = ['expires_at' => $now + 10, 'session_expires_at' => $now + 10];
        $this->assertSame(T::OK, T::refreshStatus($ok, $now));
        $this->assertSame(T::USED, T::refreshStatus(['used_at' => 'x', 'expires_at' => $now - 5] + $ok, $now), 'a used token is a reuse even when expired');
        $this->assertSame(T::REVOKED, T::refreshStatus(['revoked_at' => 'x'] + $ok, $now));
        $this->assertSame(T::EXPIRED, T::refreshStatus(['expires_at' => $now] + $ok, $now));
        $this->assertSame(T::EXPIRED, T::refreshStatus(['session_expires_at' => $now] + $ok, $now), 'the 180-day session limit');
        $this->assertSame($now + 60 * 86400, T::refreshExpiry($now, $now + 180 * 86400));
        $this->assertSame($now + 100, T::refreshExpiry($now, $now + 100), 'never past the session');
        $this->assertSame($now + 900, T::accessExpiry($now, $now + 86400));
    }

    public function testLoginThrottle(): void
    {
        $now = 1_800_000_000;
        $this->assertFalse(LT::blocked(LT::USER, null, $now));
        $this->assertFalse(LT::blocked(LT::USER, ['window_start' => $now - 10, 'count' => 4], $now));
        $this->assertTrue(LT::blocked(LT::USER, ['window_start' => $now - 10, 'count' => 5], $now), '5 failures');
        $this->assertFalse(LT::blocked(LT::IP, ['window_start' => $now - 10, 'count' => 19], $now));
        $this->assertTrue(LT::blocked(LT::IP, ['window_start' => $now - 10, 'count' => 20], $now), '20 from one address');
        $this->assertFalse(LT::blocked(LT::USER, ['window_start' => $now - 900, 'count' => 99], $now), 'the window is 15 minutes');
        $this->assertSame(890, LT::retryAfter(['window_start' => $now - 10, 'count' => 5], $now));
        $this->assertSame(LT::key(LT::USER, ' Emp1 ', 's'), LT::key(LT::USER, 'emp1', 's'), 'user names in any case');
        $this->assertNotSame(LT::key(LT::USER, 'emp1', 's'), LT::key(LT::IP, 'emp1', 's'));
        $this->assertSame(64, strlen(LT::key(LT::IP, '10.0.0.1', 's')));
    }

    public function testBearerHeaders(): void
    {
        $this->assertSame('tok', Bearer::fromHeaders('Bearer tok', null));
        $this->assertSame('tok', Bearer::fromHeaders('bearer   tok ', null));
        $this->assertSame('alt', Bearer::fromHeaders(null, 'Bearer alt'), 'the fallback header');
        $this->assertSame('main', Bearer::fromHeaders('Bearer main', 'Bearer alt'), 'Authorization wins');
        $this->assertSame('alt', Bearer::fromHeaders('Basic dXNlcjpwYXNz', 'Bearer alt'), 'a non-bearer Authorization is not a token');
        $this->assertNull(Bearer::fromHeaders('Basic dXNlcjpwYXNz', null));
        $this->assertNull(Bearer::fromHeaders('Bearer', null));
        $this->assertNull(Bearer::fromHeaders('Bearer a b', null));
        $this->assertNull(Bearer::fromHeaders('Bearer ' . str_repeat('x', 201), null));
        $this->assertNull(Bearer::fromHeaders('', ''));
    }

    public function testLoginInput(): void
    {
        $ok = ['username' => 'emp1', 'password' => 'p', 'device' => ['installation_id' => 'abcd-1234-ef', 'platform' => 'Android', 'model' => 'Pixel 8', 'app_version' => '1.2.0']];
        [$in, $err] = LoginInput::parse($ok);
        $this->assertSame([], $err);
        $this->assertSame('android', $in['device']['platform']);
        foreach (['1', '1.2', '1.2.3', '1.2.3.4', '2.0.0-beta.1', '1.0.0+42'] as $v) $this->assertSame([], LoginInput::parse(['device' => ['app_version' => $v] + $ok['device']] + $ok)[1], $v);
        $this->assertSame(['username' => 'required', 'password' => 'required'], LoginInput::parse(['device' => $ok['device']])[1]);
        $this->assertArrayHasKey('device.platform', LoginInput::parse(['device' => ['platform' => 'windows'] + $ok['device']] + $ok)[1]);
        $this->assertArrayHasKey('device.installation_id', LoginInput::parse(['device' => ['installation_id' => 'short'] + $ok['device']] + $ok)[1]);
        $this->assertArrayHasKey('device.installation_id', LoginInput::parse(['device' => ['installation_id' => 'has spaces in it'] + $ok['device']] + $ok)[1]);
        $this->assertArrayHasKey('device.model', LoginInput::parse(['device' => ['model' => "Pix\x07el"] + $ok['device']] + $ok)[1]);
        $this->assertArrayHasKey('device.app_version', LoginInput::parse(['device' => ['app_version' => 'latest'] + $ok['device']] + $ok)[1]);
        $this->assertArrayHasKey('password', LoginInput::parse(['password' => ['array']] + $ok)[1]);
        $this->assertCount(6, LoginInput::parse('not json')[1]);
    }

    public function testMeta(): void
    {
        $doc = Meta::document(['site_id' => 'abcd1234', 'site_name' => 'Acme', 'site_url' => 'https://hr.acme.test/', 'timezone' => 'Africa/Cairo', 'locale' => 'ar',
            'server_version' => '3.31.47', 'features' => ['breaks' => true, 'secret_feature' => true], 'app_name' => 'Employee Hub', 'theme_color' => '#101828', 'icon' => '']);
        $this->assertSame(['product', 'site', 'api', 'server', 'app', 'auth', 'features', 'branding'], array_keys($doc));
        $this->assertSame(Meta::FEATURES, array_keys($doc['features']), 'only the listed features, unknown ones dropped');
        $this->assertTrue($doc['features']['breaks']);
        $this->assertFalse($doc['features']['tasks']);
        $this->assertSame(['version' => '1', 'versions' => ['1']], $doc['api']);
        $this->assertNull($doc['branding']['icon']);
    }

    public function testAuthErrorCodes(): void
    {
        $expect = [E::INVALID_CREDENTIALS => 401, E::RATE_LIMITED => 429, E::TOKEN_MISSING => 401, E::TOKEN_INVALID => 401, E::TOKEN_EXPIRED => 401,
            E::REFRESH_INVALID => 401, E::REFRESH_REUSED => 401, E::DEVICE_REVOKED => 401, E::ACCOUNT_DISABLED => 403, E::ACCOUNT_NOT_LINKED => 403,
            E::HTTPS_REQUIRED => 403, E::FORBIDDEN => 403, E::NOT_FOUND => 404, E::VALIDATION_FAILED => 400, E::INTERNAL_ERROR => 500];
        foreach ($expect as $code => $status) $this->assertSame($status, E::status($code), $code);
        foreach (array_keys(E::statuses()) as $code) $this->assertMatchesRegularExpression('/^[A-Z_]+$/', $code);
    }

    public function testNativeRoutes(): void
    {
        $native = [];
        foreach (Routes::table() as $r) if ($r['kind'] === 'native') $native[$r['methods'] . ' ' . $r['path']] = $r['access'];
        $this->assertSame([
            'GET /meta' => 'public', 'POST /auth/login' => 'credentials', 'POST /auth/refresh' => 'credentials', 'POST /auth/logout' => 'token',
            'GET /me' => 'token', 'GET /me/devices' => 'token', 'DELETE /me/devices/(?P<id>[a-f0-9]{32})' => 'token',
            // 3.31.74: attendance corrections (docs/tasks/attendance-corrections.md), token only.
            'GET /corrections/days' => 'token', 'GET /corrections' => 'token', 'POST /corrections' => 'token', 'GET /corrections/pending' => 'token',
            'POST /corrections/(?P<id>\\d+)/decision' => 'token',
        ], $native, 'exactly the Phase 0B endpoints and the attendance correction ones, all token-authenticated');
        $web = array_values(array_filter(Routes::table(), function ($r) { return $r['kind'] === 'web'; }));
        $this->assertSame(['logged_in'], array_values(array_unique(array_column($web, 'access'))), 'the Web Face routes keep WordPress login');
    }
}
