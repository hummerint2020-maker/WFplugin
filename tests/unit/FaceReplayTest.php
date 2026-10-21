<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\FaceMatch;

final class FaceReplayTest extends TestCase
{
    public function testDistance(): void
    {
        $this->assertEqualsWithDelta(5.0, FaceMatch::distance([0, 0], [3, 4]), 1e-9);
        $this->assertNull(FaceMatch::distance([1, 2], [1]));
        $this->assertNull(FaceMatch::distance([], []));
    }

    public function testTheSameDescriptorAgainIsAReplayALiveCaptureIsNot(): void
    {
        $enrolled = array_map(static function ($i) { return 0.01 * $i; }, range(0, 127));
        $live = array_map(static function ($v) { return $v + 0.01; }, $enrolled);   // about 0.11 away
        $this->assertTrue(FaceMatch::matches($live, $enrolled, 0.6));
        $this->assertFalse(FaceMatch::isReplay($live, [$enrolled]), 'a live capture is never this close');
        $this->assertTrue(FaceMatch::isReplay($enrolled, [$enrolled]), 'the enrolled template itself');
        $this->assertTrue(FaceMatch::isReplay($live, [$enrolled, $live]), 'one accepted before');
        $nudged = array_map(static function ($v) { return $v + 0.001; }, $live);  // about 0.011 away
        $this->assertTrue(FaceMatch::isReplay($nudged, [$live]), 'a nearly identical copy');
        $this->assertFalse(FaceMatch::isReplay([0.1, 0.2], [$enrolled]), 'another size is not compared');
    }
}
