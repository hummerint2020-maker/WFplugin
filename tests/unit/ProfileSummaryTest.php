<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Employees\ProfileSummary as P;

final class ProfileSummaryTest extends TestCase
{
    public function testInitials(): void
    {
        $this->assertSame('أم', P::initials('أحمد منير'));
        $this->assertSame('SO', P::initials("sara o'neil"));
        $this->assertSame('M', P::initials('  Mona '));
        $this->assertSame('', P::initials(' '));
    }

    public function testTodayResult(): void
    {
        $this->assertSame('Present', P::todayResult('Office', 'On Time', false));
        $this->assertSame('Late', P::todayResult('Office', 'Late Arrival', false));
        $this->assertSame('Late', P::todayResult('Office', 'On Time', true), 'historical late_sign_in');
        $this->assertSame('Pending', P::todayResult('Office', null, false));
        $this->assertSame('Leave', P::todayResult('Vacation', null, false));
        $this->assertSame('Business Trip', P::todayResult('Business Trip', 'On Time', false));
    }

    public function testAttendanceStats(): void
    {
        $s = P::attendance([['result' => 'Present'], ['result' => 'Late'], ['result' => 'Absent'], ['result' => 'Leave']]);
        $this->assertSame(['counted' => 3, 'attended' => 2, 'late' => 1, 'absent' => 1, 'rate' => 67], $s);
        $this->assertSame(0, P::attendance([])['rate']);
    }
}
