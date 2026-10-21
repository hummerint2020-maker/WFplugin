<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\SignInEvidence as E;

final class SignInEvidenceTest extends TestCase
{
    public function testOffByDefaultWithTheAgreedValues(): void
    {
        $this->assertSame(['attempts' => 0, 'attempt_days' => 60, 'alert' => 3, 'photo' => 0, 'photo_days' => 30, 'photo_viewers' => 'hr_manager'], E::config(null));
    }

    public function testFormValuesAreKeptInRange(): void
    {
        $c = E::fromPost(['evidence_attempts' => '1', 'evidence_attempt_days' => '1000', 'evidence_alert' => '-2', 'evidence_photo' => '1', 'evidence_photo_days' => 'x', 'evidence_photo_viewers' => 'hr']);
        $this->assertSame(['attempts' => 1, 'attempt_days' => 365, 'alert' => 0, 'photo' => 1, 'photo_days' => 30, 'photo_viewers' => 'hr'], $c);
        $this->assertSame('hr_manager', E::fromPost(['evidence_photo_viewers' => 'anyone'])['photo_viewers']);
    }

    public function testAnOfflineAttemptIsTimedFromTheServerClockNotThePhone(): void
    {
        $open = 1_760_000_000_000;              // the page opened (server time, ms)
        $tap = 4 * 60 * 1000;                     // tapped 4 minutes later
        $received = 1_760_000_000 + 3600;         // reached the server an hour later
        $honest = E::offlineTime($open + $tap, $open, $tap, $received);
        $this->assertSame(['at' => 1_760_000_240, 'phone' => 1_760_000_240, 'skew' => 0, 'sure' => true], $honest);
        $moved = E::offlineTime($open + $tap - 47 * 60 * 1000, $open, $tap, $received);   // clock set back 47 minutes
        $this->assertSame(1_760_000_240, $moved['at'], 'the time shown does not follow the phone');
        $this->assertSame(-47 * 60, $moved['skew'], 'and the difference is reported');
        $long = E::offlineTime($open + 8 * 3600 * 1000, $open, 8 * 3600 * 1000, $received + 9 * 3600);
        $this->assertFalse($long['sure'], 'a page open for hours (sleep) is not trusted');
        $this->assertSame(1_760_000_000, E::offlineTime($open + 10 * 3600 * 1000, $open, 2 * 3600 * 1000, 1_760_000_000)['at'], 'never later than it arrived');
    }

    /** 3.31.118: a note the phone kept is filed only for the account that pressed the button. */
    public function testAnOfflineNoteBelongsOnlyToItsAccount(): void
    {
        $this->assertTrue(E::noteBelongs(5, 5));
        $this->assertTrue(E::noteBelongs('5', 5));
        foreach (['', null, '6', 6, [5], '5 ', 0] as $posted) $this->assertFalse(E::noteBelongs($posted, 5), var_export($posted, true));
        $this->assertFalse(E::noteBelongs(0, 0), 'nobody signed in');
        $this->assertFalse(E::noteBelongs('0', 0));
    }
}
