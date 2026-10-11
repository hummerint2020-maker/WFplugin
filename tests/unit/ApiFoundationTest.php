<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Api\ErrorMap as E;
use WorkforceOne\Api\Response;
use WorkforceOne\Api\Routes;
use WorkforceOne\Attendance\AttendanceResult;
use WorkforceOne\Attendance\BreakRules;
use WorkforceOne\Attendance\FaceMatch;
use WorkforceOne\Attendance\LocationAssessment;
use WorkforceOne\Attendance\SignInRules;

final class ApiFoundationTest extends TestCase
{
    /** @return string[] */
    private static function constants(string $class, string $prefix = ''): array
    {
        $out = [];
        foreach ((new \ReflectionClass($class))->getConstants() as $name => $v) {
            if (is_string($v) && ($prefix === '' || strpos($name, $prefix) === 0)) $out[$name] = $v;
        }
        return $out;
    }

    public function testEveryDomainCodeHasOneApiCode(): void
    {
        $domain = array_merge(
            array_diff_key(self::constants(SignInRules::class), array_flip(['WINDOW_OPEN', 'WINDOW_NOT_YET', 'WINDOW_CLOSED'])),
            self::constants(BreakRules::class),
            self::constants(AttendanceResult::class),
            self::constants(LocationAssessment::class, 'QR_')
        );
        foreach ($domain as $name => $code) {
            $this->assertArrayHasKey($code, E::all(), "$name ($code) has no API code");
            $api = E::code($code);
            $this->assertMatchesRegularExpression('/^[A-Z_]+$/', $api);
            $this->assertContains(E::status($api), [403, 409, 422, 500], "$name → $api");
        }
        $this->assertSame(E::INTERNAL_ERROR, E::code('something_new'), 'an unknown internal code never leaks');
        $this->assertSame(E::INTERNAL_ERROR, E::code(AttendanceResult::SAVE_FAILED));
        $this->assertSame([E::OUTSIDE_LOCATION, 422], [E::code(LocationAssessment::QR_OUTSIDE), E::status(E::OUTSIDE_LOCATION)]);
        $this->assertSame([E::ALREADY_SIGNED_IN, 409], [E::code(SignInRules::ALREADY_SIGNED_IN), E::status(E::ALREADY_SIGNED_IN)]);
        $this->assertSame([E::RATE_LIMITED, 429], [E::code('rate_limited'), E::status(E::RATE_LIMITED)]);
    }

    public function testTheEnvelope(): void
    {
        $ok = Response::success(['event' => ['id' => 5]], 'RID', '2026-10-05T08:00:00+03:00', ['unread' => 2]);
        $this->assertSame(200, $ok['status']);
        $this->assertSame(['success' => true, 'data' => ['event' => ['id' => 5]], 'meta' => ['request_id' => 'RID', 'server_time' => '2026-10-05T08:00:00+03:00', 'api_version' => '1.0', 'unread' => 2]], $ok['body']);
        $err = Response::failure(SignInRules::TOO_EARLY, 'Not yet', 'RID', 'T', ['opens_at' => '09:00']);
        $this->assertSame(409, $err['status']);
        $this->assertSame(['code' => 'SIGN_IN_TOO_EARLY', 'message' => 'Not yet', 'details' => ['opens_at' => '09:00']], $err['body']['error']);
        $this->assertFalse($err['body']['success']);
        $boom = Response::internalError('RID', 'T');
        $this->assertSame([500, 'INTERNAL_ERROR'], [$boom['status'], $boom['body']['error']['code']]);
        $this->assertArrayNotHasKey('details', $boom['body']['error']);
        $id = Response::requestId(1790000000000, random_bytes(10));
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id);
        $this->assertLessThan(Response::requestId(1790000000001, "\0\0\0\0\0\0\0\0\0\0"), Response::requestId(1790000000000, "\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff"), 'ids sort by time');
    }

    public function testRoutes(): void
    {
        $this->assertSame('workforce-one/v1', Routes::NAMESPACE);
        $web = array_values(array_filter(Routes::table(), function ($r) { return $r['kind'] === 'web'; }));
        $this->assertSame(['/face/enroll', '/face/verify', '/face/reset-request', '/face/delete'], array_column($web, 'path'), 'the four Face routes, unchanged');
        foreach ($web as $r) {
            $this->assertSame(['POST', 'logged_in'], [$r['methods'], $r['access']]);
            $this->assertIsCallable(Routes::access($r['access']));
        }
        $this->expectException(\InvalidArgumentException::class);
        Routes::access('anyone');
    }

    public function testBreakRulesOrder(): void
    {
        $all = ['enabled' => true, 'employee' => true, 'attendance_enabled' => true, 'working_day' => true, 'signed_in' => true, 'signed_out' => false, 'on_break' => false, 'remaining' => 1];
        $this->assertNull(BreakRules::checkStart($all));
        $this->assertSame(BreakRules::BREAKS_DISABLED, BreakRules::checkStart(['enabled' => false, 'employee' => false]));
        $this->assertSame(BreakRules::ATTENDANCE_DISABLED, BreakRules::checkStart(['attendance_enabled' => false, 'working_day' => false] + $all), 'attendance off is checked before the day');
        $this->assertSame(BreakRules::NO_EMPLOYEE, BreakRules::checkStart(['employee' => false] + $all));
        $this->assertSame(BreakRules::NOT_WORKING_DAY, BreakRules::checkStart(['working_day' => false, 'signed_in' => false] + $all));
        $this->assertSame(BreakRules::NOT_SIGNED_IN, BreakRules::checkStart(['signed_in' => false, 'signed_out' => true] + $all));
        $this->assertSame(BreakRules::ALREADY_SIGNED_OUT, BreakRules::checkStart(['signed_out' => true, 'on_break' => true] + $all));
        $this->assertSame(BreakRules::ALREADY_ON_BREAK, BreakRules::checkStart(['on_break' => true, 'remaining' => 0] + $all));
        $this->assertSame(BreakRules::NO_BREAKS_LEFT, BreakRules::checkStart(['remaining' => 0] + $all));
        $this->assertSame(BreakRules::NO_OPEN_BREAK, BreakRules::checkResume(['enabled' => true, 'employee' => true, 'attendance_enabled' => true, 'open_break' => false]));
        $this->assertSame(BreakRules::ATTENDANCE_DISABLED, BreakRules::checkResume(['enabled' => true, 'employee' => true, 'attendance_enabled' => false]));
        $this->assertSame([0, 0, 1, 2], [BreakRules::minutes(100, 50), BreakRules::minutes(0, 59), BreakRules::minutes(0, 60), BreakRules::minutes(0, 179)]);
    }

    public function testFaceMatchAndTheAttemptLimit(): void
    {
        $a = array_fill(0, 128, 0.1);
        $b = $a;
        $b[0] = 0.69;
        $this->assertTrue(FaceMatch::matches($a, $a, 0.6));
        $this->assertTrue(FaceMatch::matches($b, $a, 0.6), 'distance 0.59 < 0.6');
        $b[0] = 0.7;
        $this->assertFalse(FaceMatch::matches($b, $a, 0.6), 'distance 0.6 is not below 0.6');
        $this->assertNull(FaceMatch::matches([0.1], $a, 0.6), 'different lengths');
        $s = null;
        for ($i = 0; $i < FaceMatch::MAX_FAILURES - 1; $i++) $s = FaceMatch::afterFailure($s, 1000 + $i);
        $this->assertFalse(FaceMatch::lockedOut($s, 1010));
        $s = FaceMatch::afterFailure($s, 1010);
        $this->assertTrue(FaceMatch::lockedOut($s, 1011), 'the fifth failure locks');
        $this->assertSame(589, FaceMatch::retryAfter($s, 1011), 'until 10 minutes after the first failure');
        $this->assertFalse(FaceMatch::lockedOut($s, 1600), 'and then it is open again');
        $this->assertSame(['since' => 1600, 'failures' => 1], FaceMatch::afterFailure($s, 1600), 'a new window starts');
        $this->assertSame(0, FaceMatch::retryAfter(null, 5));
    }
}
