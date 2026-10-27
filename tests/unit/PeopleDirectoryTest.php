<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\People\PeopleDirectory;
use WorkforceOne\People\PeopleMemo;
use WorkforceOne\Tests\Fakes\MemoryPeopleStore;

require_once __DIR__ . '/Fakes/MemoryPeopleStore.php';

/** PeopleDirectory (3.31.133): the lookups, the shift memo and the active list, on an in-memory Store. */
final class PeopleDirectoryTest extends TestCase
{
    /** @var MemoryPeopleStore */
    private $store;
    /** @var PeopleMemo */
    private $memo;
    /** @var PeopleDirectory */
    private $dir;

    protected function setUp(): void
    {
        $this->store = new MemoryPeopleStore();
        $this->store->add(1, 'Mona', 11, true, ['department_id' => '5']);
        $this->store->add(2, 'Omar', 12, true, ['department_id' => '5', 'default_shift_id' => '201']);
        $this->store->add(3, 'Old', 13, false);
        $this->store->add(4, 'Twin', 11, true);   // a second active employee on Mona's user
        $this->memo = new PeopleMemo();
        $this->dir = new PeopleDirectory($this->store, $this->memo);
    }

    public function testAnIdThatIsNotPositiveReadsNothing(): void
    {
        $this->assertNull($this->dir->employee(0));
        $this->assertNull($this->dir->employee(-3, true));
        $this->assertNull($this->dir->employeeByUser(0));
        $this->assertNull($this->dir->activeEmployee(0));
        $this->assertSame([], $this->store->calls);
    }

    public function testEmployeeAndTheActiveOnlyFlag(): void
    {
        $this->assertSame('Old', $this->dir->employee(3)->name);
        $this->assertNull($this->dir->employee(3, true));
        $this->assertSame('Omar', $this->dir->activeEmployee(2)->name);
        $this->assertSame('Mona', $this->dir->activeBrief(1)->name);
        $this->assertNull($this->dir->activeBrief(3), 'an archived employee has no brief');
    }

    public function testTheLowestIdWinsForAUserLinkedTwice(): void
    {
        $this->assertSame('Mona', $this->dir->employeeByUser(11)->name);
        $this->assertSame([11 => 'Mona', 12 => 'Omar'], $this->dir->namesByUser([11, 12, 0, 11, 99, 13]));
    }

    public function testRawStringValuesStayStrings(): void
    {
        $this->assertSame('1', $this->dir->activeId(1));
        $this->assertNull($this->dir->activeId(3));
        $this->assertSame('11', $this->dir->userId(1));
        $this->assertNull($this->dir->userId(3, true));
        $this->assertSame('13', $this->dir->userId(3));
        $this->assertSame('Omar', $this->dir->name(2));
        $this->assertNull($this->dir->name(99));
    }

    public function testManyAtOnce(): void
    {
        $this->assertSame([1, 2], array_keys($this->dir->byIds([1, 2, 2, 0, 99])));
        $this->assertSame([1, 2], array_keys($this->dir->byIds([1, 2, 3], true)));
        $this->assertSame([3 => 'Old', 1 => 'Mona'], $this->dir->namesByIds([3, 1, 77]), 'archived too; unknown ids left out; the order of the Store');
        $this->assertSame('13', $this->dir->briefsByIds([3])[3]->wp_user_id);
    }

    public function testDepartmentAndCounts(): void
    {
        $this->assertSame([1, 2], $this->dir->departmentMemberIds(5));
        $this->assertTrue($this->dir->inDepartment(1, 5));
        $this->assertFalse($this->dir->inDepartment(1, 6));
        $this->assertSame(3, $this->dir->activeCount());
        $this->assertSame(2, $this->dir->activeCount(5, true));
        $this->assertSame(['11', '12', '11'], $this->dir->activeUserIds());
        $this->assertSame(['Mona', 'Omar'], array_map(function ($r) { return $r->name; }, $this->dir->activeList(2)));
    }

    public function testTheShiftIsReadOnceAndRemembered(): void
    {
        $this->assertSame(201, $this->dir->shiftId(2));
        $this->assertSame(201, $this->dir->shiftId(2));
        $this->assertSame(0, $this->dir->shiftId(1), 'no shift: 0 (company hours)');
        $this->assertSame(0, $this->dir->shiftId(99), 'an unknown employee: 0, remembered');
        $this->dir->shiftId(99);
        $this->assertSame(['shiftIdOf 2', 'shiftIdOf 1', 'shiftIdOf 99'], $this->store->calls);
    }

    public function testShiftIdsReadOnlyWhatIsMissing(): void
    {
        $rows = [(object) ['id' => 2, 'default_shift_id' => '300'], 1, 0, 99, (object) ['id' => 1]];
        $out = $this->dir->shiftIds($rows);
        $this->assertSame([2 => 300, 1 => 0, 99 => 0], $out, 'in the order given; a loaded row is not read again; unknown ids are 0; ids <= 0 are skipped');
        $this->assertSame(['shiftIdsOf 1,99'], $this->store->calls, 'one read for the two missing ids (the object without default_shift_id counts as missing: 1 is read once)');
        $this->assertSame(300, $this->dir->shiftId(2), 'the loaded row is now remembered');
        $this->dir->shiftIds([1, 2, 99]);
        $this->assertSame(['shiftIdsOf 1,99'], $this->store->calls, 'everything is remembered: nothing more is read');
    }

    public function testSetShiftForgetsBeforeTheWriteSoAFailedWriteLeavesNothingStale(): void
    {
        $this->assertSame(201, $this->dir->shiftId(2));
        $this->store->failWrite = true;
        $this->assertFalse($this->dir->setShiftId(2, 300));
        $this->assertArrayNotHasKey(2, $this->memo->shift, 'forgotten although the write failed');
        $this->store->failWrite = false;
        $this->assertTrue($this->dir->setShiftId(2, 300));
        $this->assertSame(300, $this->dir->shiftId(2), 'read again after the write');
        $this->assertTrue($this->dir->setShiftId(2, 0));
        $this->assertSame(0, $this->dir->shiftId(2));
    }

    public function testStoredShiftIdsAndNowSkipTheMemo(): void
    {
        $this->memo->shift[2] = 999;
        $this->assertSame([2 => 201], $this->dir->storedShiftIds([2, 99]));
        $this->assertSame(201, $this->dir->shiftIdNow(2));
        $this->assertSame(0, $this->dir->shiftIdNow(99));
        $this->assertSame(999, $this->memo->shift[2], 'the memo is untouched');
        $this->assertSame([1 => 0, 2 => 201, 3 => 0, 4 => 0], $this->dir->allShiftIds());
    }

    public function testTheActiveListIsReadOnceAndAFailedReadIsNotRemembered(): void
    {
        $this->assertFalse($this->dir->activeAllLoaded());
        $this->assertCount(3, $this->dir->activeAll());
        $this->assertTrue($this->dir->activeAllLoaded());
        $this->dir->activeAll();
        $this->assertSame(['activeAll'], $this->store->calls);
        $this->dir->forget(2);
        $this->assertFalse($this->dir->activeAllLoaded());
        $this->store->failActiveAll = true;
        $this->assertNull($this->dir->activeAll());
        $this->assertFalse($this->dir->activeAllLoaded(), 'a null read is not remembered');
    }

    public function testForgetClearsThatEmployeesShiftAndTheListButForgetShiftKeepsTheList(): void
    {
        $this->dir->shiftId(2);
        $this->dir->shiftId(1);
        $this->dir->activeAll();
        $this->memo->forgetShift(2);
        $this->assertArrayNotHasKey(2, $this->memo->shift);
        $this->assertTrue($this->dir->activeAllLoaded(), 'forgetShift keeps the active list');
        $this->dir->forget(1);
        $this->assertArrayNotHasKey(1, $this->memo->shift);
        $this->assertFalse($this->dir->activeAllLoaded());
        $this->dir->shiftId(1);
        $this->dir->forget(0);
        $this->assertArrayHasKey(1, $this->memo->shift, 'forget(0) clears only the list');
    }

    public function testAttendanceEnabledTreatsNullAsYes(): void
    {
        $this->store->add(5, 'No', 15, true, ['attendance_enabled' => '0']);
        $this->store->add(6, 'Null', 16, true, ['attendance_enabled' => null]);
        $this->assertTrue($this->dir->attendanceEnabled(1));
        $this->assertFalse($this->dir->attendanceEnabled(5));
        $this->assertTrue($this->dir->attendanceEnabled(6));
        $this->assertTrue($this->dir->attendanceEnabled(99), 'an unknown employee: the row is missing, the value null, so tracked');
        $this->assertFalse($this->dir->attendanceEnabled(0));
    }
}
