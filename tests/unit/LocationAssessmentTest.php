<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\LocationAssessment as L;
use WorkforceOne\Support\Geo;

final class LocationAssessmentTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const CAIRO = [30.0444, 31.2357];
    private const ALEX = [31.2001, 29.9187];

    public function testDistance(): void
    {
        $this->assertEqualsWithDelta(179_000, Geo::distanceMeters(...self::CAIRO, ...self::ALEX), 2_000);
        $this->assertNull(Geo::distanceMeters('', 1, 2, 3));
        $this->assertNull(Geo::distanceMeters(null, 1, 2, 3));
        $this->assertSame(0.0, Geo::distanceMeters(1, 1, 1, 1));
    }

    public function testIntegrityVerified(): void
    {
        $this->assertSame(['verified', ''], L::integrity(30.0, 31.0, 20.0, self::NOW * 1000, self::NOW, null));
    }

    public function testIntegrityFlags(): void
    {
        $this->assertSame(['unreliable', 'location_not_available'], L::integrity(null, 31.0, 20.0, self::NOW * 1000, self::NOW, null));
        $this->assertSame(['unreliable', 'missing_accuracy'], L::integrity(30.0, 31.0, null, self::NOW * 1000, self::NOW, null));
        $this->assertSame(['unreliable', 'low_accuracy'], L::integrity(30.0, 31.0, 101.0, self::NOW * 1000, self::NOW, null));
        $this->assertSame(['verified', ''], L::integrity(30.0, 31.0, 100.0, self::NOW * 1000, self::NOW, null));
        $this->assertSame(['unreliable', 'missing_timestamp'], L::integrity(30.0, 31.0, 20.0, null, self::NOW, null));
        // The accuracy reason is kept when the timestamp is also missing...
        $this->assertSame(['unreliable', 'low_accuracy'], L::integrity(30.0, 31.0, 500.0, null, self::NOW, null));
        // ...but a stale timestamp overrides it.
        $this->assertSame(['unreliable', 'stale_timestamp'], L::integrity(30.0, 31.0, 500.0, (self::NOW - 301) * 1000, self::NOW, null));
        $this->assertSame(['verified', ''], L::integrity(30.0, 31.0, 20.0, (self::NOW - 300) * 1000, self::NOW, null));
    }

    public function testImpossibleMovement(): void
    {
        $prev = ['latitude' => self::CAIRO[0], 'longitude' => self::CAIRO[1], 'location_timestamp' => (self::NOW - 60) * 1000];
        // ~180 km in one minute.
        $this->assertSame(['suspicious', 'impossible_movement'], L::integrity(...[...self::ALEX, 20.0, self::NOW * 1000, self::NOW, $prev]));
        // Same place a minute later is fine.
        $this->assertSame(['verified', ''], L::integrity(...[...self::CAIRO, 20.0, self::NOW * 1000, self::NOW, $prev]));
        // More than two hours apart: not compared.
        $old = ['location_timestamp' => (self::NOW - 7201) * 1000] + $prev;
        $this->assertSame(['verified', ''], L::integrity(...[...self::ALEX, 20.0, self::NOW * 1000, self::NOW, $old]));
        // Previous event without a device timestamp: not compared.
        $this->assertSame(['verified', ''], L::integrity(...[...self::ALEX, 20.0, self::NOW * 1000, self::NOW, ['location_timestamp' => null] + $prev]));
    }

    public function testGeofence(): void
    {
        [$s, $d] = L::geofence(30.0445, 31.2358, '30.0444', '31.2357', 200.0);
        $this->assertSame('inside', $s);
        $this->assertLessThan(50, $d);
        $this->assertSame('outside', L::geofence(...[...self::ALEX, '30.0444', '31.2357', 200.0])[0]);
        $this->assertSame(['recorded', null], L::geofence(30.0, 31.0, '', '', 200.0));
        $this->assertSame(['not_available', null], L::geofence(null, null, '30.0', '31.0', 200.0));
    }

    public function testQrCheck(): void
    {
        $this->assertSame(L::QR_KIOSK_NO_COORDS, L::qrCheck(30.0, 31.0, '', '', 200.0, 'verified', '')[0]);
        $this->assertSame(L::QR_LOCATION_REQUIRED, L::qrCheck(null, null, 30.0, 31.0, 200.0, 'unreliable', 'location_not_available')[0]);
        $this->assertSame(L::QR_LOCATION_REQUIRED, L::qrCheck(30.0, 31.0, 30.0, 31.0, 200.0, 'unreliable', 'stale_timestamp')[0]);
        $this->assertSame(L::QR_OUTSIDE, L::qrCheck(...[...self::ALEX, ...self::CAIRO, 200.0, 'verified', ''])[0]);
        $this->assertSame(L::QR_OUTSIDE, L::qrCheck(...[...self::CAIRO, ...self::CAIRO, 200.0, 'suspicious', 'impossible_movement'])[0]);
        $this->assertNull(L::qrCheck(30.0445, 31.2358, 30.0444, 31.2357, 200.0, 'unreliable', 'low_accuracy')[0]);
    }
}
