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

    public function testLeaveTypeOnlyOnAnApprovedLeaveDay(): void
    {
        $this->assertSame('Sick Leave', GridRules::leaveType('Leave', false, 'Vacation', '  Sick Leave '));
        $this->assertNull(GridRules::leaveType('Leave', true, 'Vacation', 'Sick Leave'), 'a company holiday wins');
        $this->assertNull(GridRules::leaveType('Leave', false, 'Sick Leave', 'Sick Leave'), 'a day changed by hand shows Leave');
        $this->assertNull(GridRules::leaveType('Present', false, 'Vacation', 'Sick Leave'));
        $this->assertNull(GridRules::leaveType('Leave', false, 'Vacation', '   '));
        $this->assertNull(GridRules::leaveType('Leave', false, 'Vacation', null));
    }

    public function testATypedBadgeAndTheUntypedOneAsBefore(): void
    {
        $this->assertSame(['class' => 'leave', 'label' => '▣ Casual Leave', 'detail' => '', 'type' => 'Casual Leave'], GridRules::badge('Leave', '', 'Casual Leave'));
        $this->assertSame(GridRules::badge('Leave', 'National Day'), GridRules::badge('Leave', 'National Day', null));
        $this->assertSame(['class' => 'leave', 'label' => '▣ Leave', 'detail' => ''], GridRules::badge('Leave', '', ''));
        $this->assertSame(GridRules::badge('Present', '08:00'), GridRules::badge('Present', '08:00', 'Sick Leave'), 'a type only on a Leave day');
    }

    public function testLeaveSummaryMostDaysFirstAndOtherLast(): void
    {
        $s = GridRules::leaveSummary(['Sick Leave' => 2, 'Annual Leave' => 5, 'Casual Leave' => 2, 'Unpaid' => 0], 1);
        $this->assertSame(10, $s['total']);
        $this->assertSame([['Annual', 5], ['Casual', 2], ['Sick', 2]], $s['split']);
        $this->assertSame([['Annual', 5], ['Sick', 1]], GridRules::leaveSummary(['Annual' => 3, 'Sick Leave' => 1, 'Annual Leave' => 2], 0)['split'], 'the same short name is one entry');
        $this->assertSame(1, $s['other']);
        $this->assertSame(['total' => 0, 'split' => [], 'other' => 0], GridRules::leaveSummary([], 0));
    }

    public function testShortTypeNeverEmpty(): void
    {
        $this->assertSame('Annual', GridRules::shortType('Annual Leave'));
        $this->assertSame('Sick', GridRules::shortType('Sick leave'));
        $this->assertSame('مرضية', GridRules::shortType('إجازة مرضية'));
        $this->assertSame('Leave', GridRules::shortType('Leave'));
        $this->assertSame('إجازة', GridRules::shortType('إجازة'));
        $this->assertSame('Maternity', GridRules::shortType('Maternity'));
    }
}
