<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\GridRules;

final class AttendanceGridTest extends TestCase
{
    public function testBadges(): void
    {
        $this->assertSame(['class' => 'present', 'label' => '✓ Present', 'detail' => '08:05'], GridRules::badge('Present', '08:05'));
        $this->assertSame('late', GridRules::badge('Late', '09:30')['class']);
        $this->assertSame(['class' => 'leave', 'label' => '▣ Leave', 'detail' => 'National Day'], GridRules::badge('Leave', 'National Day'));
        $this->assertSame('muted', GridRules::badge('Not Scheduled')['class']);
        $this->assertSame('pending', GridRules::badge('Pending')['class']);
    }

    public function testABusinessTripTypeKeepsItsName(): void
    {
        $this->assertSame(['class' => 'trip', 'label' => '✈ Training Course', 'detail' => ''], GridRules::badge('Training Course'));
    }

    public function testSaveMessageReportsInvalidChanges(): void
    {
        $this->assertNull(GridRules::message([]));
        $this->assertSame(['text' => 'Saved 2 schedule record(s). Skipped empty cells: 1.', 'error' => false], GridRules::message(['grid_saved' => '2', 'grid_skipped' => '1']));
        $m = GridRules::message(['grid_saved' => '0', 'grid_conflict' => '1', 'grid_invalid' => '2']);
        $this->assertTrue($m['error']);
        $this->assertStringContainsString('2 change(s) could not be saved', $m['text']);
        // Conflicts have their own pop-up in the app layout.
        $this->assertStringNotContainsString('someone else', $m['text']);
        $this->assertSame('Imported 3 row(s); rejected 0.', GridRules::message(['imported' => '3'])['text']);
    }
}
