<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\FeatureSettings as F;

final class FeatureSettingsTest extends TestCase
{
    public function testFaceSettingsAreClampedAndDefaulted(): void
    {
        $f = F::face(['baseline_samples' => 500, 'face_match_threshold' => '0.1', 'detector_input_size' => '300']);
        $this->assertSame(60, $f['baseline_samples']);
        $this->assertSame(0.30, $f['face_match_threshold']);
        $this->assertSame(320, $f['detector_input_size']);
        $this->assertSame(0.018, $f['head_move_ratio']);
        $this->assertSame(416, F::face(['detector_input_size' => '416'])['detector_input_size']);
        $this->assertSame(array_keys(F::FACE_DEFAULTS), array_keys(F::face(null)));
    }

    public function testSplash(): void
    {
        $s = F::splash(['enabled' => 1, 'title' => '  ', 'subtitle' => "It's", 'background' => '#ABC', 'accent' => 'purple', 'duration_ms' => 9999]);
        $this->assertSame('Workforce One', $s['title']);
        $this->assertSame("It's", $s['subtitle']);
        $this->assertSame('#abc', $s['background']);
        $this->assertSame('#6125c9', $s['accent']);
        $this->assertSame(3000, $s['duration_ms']);
        $this->assertSame(0, F::splash([])['enabled']);
    }

    public function testBreaksAndEarlyLeave(): void
    {
        $this->assertSame(['per_day' => 20, 'duration' => 60, 'escalation' => 61], F::breaks(50, 60, 30));
        $this->assertSame([['max' => 90, 'monthly' => 300], null], F::earlyLeave(90, 300));
        $this->assertSame('early_leave_monthly', F::earlyLeave(120, 60)[1]);
    }

    public function testRecognition(): void
    {
        $this->assertSame(['mode' => 'limited', 'limit' => 1], F::recognition('bogus', 0));
        $this->assertSame(['mode' => 'unlimited', 'limit' => 1000], F::recognition('unlimited', 5000));
    }

    public function testEveryConfirmationCanBeSwitched(): void
    {
        $saved = F::confirmActions(['swap_cancel' => 1]);
        $this->assertSame(array_keys(F::CONFIRM_ACTIONS), array_keys($saved));
        $this->assertSame(0, $saved['early_leave_reject']);
        $this->assertSame(1, $saved['swap_cancel']);
        $this->assertSame(1, F::confirmState([])['early_leave_reject'], 'unset actions default to on');
        $this->assertSame(0, F::confirmState(['early_leave_reject' => 0])['early_leave_reject']);
    }
}
