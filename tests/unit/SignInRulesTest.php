<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\SignInRules as R;

final class SignInRulesTest extends TestCase
{
    private function facts(array $over = []): array
    {
        return array_merge([
            'employee' => true, 'face_required' => false, 'face_ok' => false, 'general_leave' => false,
            'working_day' => true, 'window' => R::WINDOW_OPEN, 'signed_in' => false, 'signed_out' => false, 'on_break' => false,
        ], $over);
    }

    public static function cases(): array
    {
        return [
            'normal sign in'                     => ['sign_in', [], null],
            'normal sign out'                    => ['sign_out', ['signed_in' => true], null],
            'no employee'                        => ['sign_in', ['employee' => false], R::NO_EMPLOYEE],
            'general leave'                      => ['sign_in', ['general_leave' => true], R::GENERAL_LEAVE],
            'not a working day'                  => ['sign_in', ['working_day' => false], R::NOT_WORKING_DAY],
            'before working hours'               => ['sign_in', ['window' => R::WINDOW_NOT_YET], R::TOO_EARLY],
            'after cutoff'                       => ['sign_in', ['window' => R::WINDOW_CLOSED], R::TOO_LATE],
            'window ignored for sign out'        => ['sign_out', ['window' => R::WINDOW_CLOSED, 'signed_in' => true], null],
            'already signed in'                  => ['sign_in', ['signed_in' => true], R::ALREADY_SIGNED_IN],
            'sign out before sign in'            => ['sign_out', [], R::NOT_SIGNED_IN],
            'sign out while on break'            => ['sign_out', ['signed_in' => true, 'on_break' => true], R::ON_BREAK],
            'already signed out'                 => ['sign_out', ['signed_in' => true, 'signed_out' => true], R::ALREADY_SIGNED_OUT],
            'face required, no token'            => ['sign_in', ['face_required' => true], R::FACE_REQUIRED],
            'face required, token ok'            => ['sign_in', ['face_required' => true, 'face_ok' => true], null],
            'face never required for sign out'   => ['sign_out', ['face_required' => true, 'signed_in' => true], null],
            // Precedence: the later checks override the face message (existing behaviour).
            'already signed in beats face'       => ['sign_in', ['face_required' => true, 'signed_in' => true], R::ALREADY_SIGNED_IN],
            'no employee beats everything'       => ['sign_in', ['employee' => false, 'general_leave' => true, 'face_required' => true], R::NO_EMPLOYEE],
            'general leave beats not working'    => ['sign_in', ['general_leave' => true, 'working_day' => false], R::GENERAL_LEAVE],
            'window beats already signed in'     => ['sign_in', ['window' => R::WINDOW_CLOSED, 'signed_in' => true], R::TOO_LATE],
            'break beats not signed in'          => ['sign_out', ['on_break' => true], R::ON_BREAK],
        ];
    }

    #[DataProvider('cases')]
    public function testRules(string $type, array $over, ?string $expected): void
    {
        $this->assertSame($expected, R::check($type, $this->facts($over)));
    }
}
