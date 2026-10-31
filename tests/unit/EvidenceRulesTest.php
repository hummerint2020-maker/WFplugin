<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\EvidenceRules;

/** The words and decisions of the Sign In evidence (3.31.139), as they were in includes/trait-signin-evidence.php. */
final class EvidenceRulesTest extends TestCase
{
    public function testReasonsInWordsWithTheDistanceWhereItBelongs(): void
    {
        $this->assertSame('Outside the work location (850 m away)', EvidenceRules::reasonLabel('outside_location', ' (850 m away)'));
        $this->assertSame('Outside the kiosk\'s work location', EvidenceRules::reasonLabel('outside_kiosk', ''));
        $this->assertSame('Too early', EvidenceRules::reasonLabel('too_early', ' (ignored)'));
        $this->assertSame('Did not reach the server (no network)', EvidenceRules::reasonLabel('no_network', ''));
        $this->assertSame('Face matched', EvidenceRules::reasonLabel('face_ok', ''));
    }

    public function testAnUnknownReasonIsMadeReadable(): void
    {
        $this->assertSame('Brand new reason', EvidenceRules::reasonLabel('brand_new_reason', ''));
        $this->assertSame('', EvidenceRules::reasonLabel('', ''));
    }

    public function testEventNames(): void
    {
        $this->assertSame('Face check', EvidenceRules::eventLabel('face'));
        $this->assertSame('Sign Out', EvidenceRules::eventLabel('sign_out'));
        $this->assertSame('Sign In', EvidenceRules::eventLabel('sign_in'));
        $this->assertSame('Sign In', EvidenceRules::eventLabel('anything_else'));
    }

    public function testAuditActions(): void
    {
        $this->assertSame('signin_not_reached', EvidenceRules::auditAction('not_reached', 'sign_in'));
        $this->assertSame('signin_not_reached', EvidenceRules::auditAction('not_reached', 'face'));
        $this->assertSame('face_check_failed', EvidenceRules::auditAction('face_failed', 'face'));
        $this->assertSame('signin_refused', EvidenceRules::auditAction('refused', 'sign_out'));
    }

    public function testAuditTextOfARefusal(): void
    {
        $row = ['result' => 'refused', 'attempted_at' => '2026-10-26 08:01:00', 'device' => 'Pixel · Chrome', 'ip_address' => '10.0.0.7'];
        $this->assertSame('Omar / Sign In / Too early / Pixel · Chrome 10.0.0.7', EvidenceRules::auditText('Omar', 'Sign In', 'Too early', $row));
        $this->assertSame('Omar / Sign In / Too early', EvidenceRules::auditText('Omar', 'Sign In', 'Too early', ['result' => 'refused', 'attempted_at' => 'x', 'device' => '', 'ip_address' => '']), 'an empty device and address add nothing');
    }

    public function testAuditTextOfAnUnsentAttempt(): void
    {
        $row = ['result' => 'not_reached', 'attempted_at' => '2026-10-26 08:01:00', 'phone_time' => '2026-10-26 08:10:00', 'clock_skew' => 540, 'time_sure' => 0, 'device' => 'Pixel', 'ip_address' => '10.0.0.7'];
        $this->assertSame('Omar / Sign In / Reason / pressed at 08:01 (not certain) / phone said 08:10 / phone clock off by 9 min / Pixel 10.0.0.7', EvidenceRules::auditText('Omar', 'Sign In', 'Reason', $row));
        $sure = ['time_sure' => 1, 'clock_skew' => 120, 'phone_time' => null] + $row;
        $this->assertSame('Omar / Sign In / Reason / pressed at 08:01 / Pixel 10.0.0.7', EvidenceRules::auditText('Omar', 'Sign In', 'Reason', $sure), 'a gap of two minutes is not reported; no phone time is not shown');
        $this->assertStringContainsString('off by 3 min', EvidenceRules::auditText('Omar', 'Sign In', 'Reason', ['clock_skew' => -181] + $sure));
    }

    public function testTheManagersAreToldOnlyAtExactlyN(): void
    {
        $this->assertTrue(EvidenceRules::alertDue(3, 3));
        $this->assertFalse(EvidenceRules::alertDue(2, 3));
        $this->assertFalse(EvidenceRules::alertDue(4, 3), 'told once, at the third; not again at the fourth');
        $this->assertFalse(EvidenceRules::alertDue(0, 0), 'never when the setting is 0');
        $this->assertFalse(EvidenceRules::alertDue(1, 0));
    }
}
