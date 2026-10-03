<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

/** What each report figure means; exported as the "Definitions" sheet. Pure. */
final class Definitions
{
    /** @return array<string,string> */
    public static function all(): array
    {
        return [
            'Expected day' => 'A configured working day on which the employee is scheduled with a type that requires Sign In, and that is not a company holiday.',
            'Present' => 'First Sign In no later than the shift start plus the grace period.',
            'Late' => 'First Sign In after the shift start plus the grace period.',
            'Absent' => 'An expected day without a Sign In (today: once the no-show cutoff has passed).',
            'Holiday' => 'A company holiday (General Leave). Not counted as leave and expects no hours.',
            'Leave' => 'A day scheduled with a leave type (e.g. Vacation).',
            'Business Trip' => 'A day scheduled with a business-trip type (e.g. Business Trip, Training Course).',
            'Attendance rate' => '(Present + Late) / (Present + Late + Absent).',
            'Punctuality' => 'Present / (Present + Late).',
            'Late (min)' => 'Minutes from the shift start to the first Sign In, on Late days.',
            'Early Leave (min)' => 'Minutes from the last Sign Out to the shift end, on expected days.',
            'Break (min)' => 'Minutes of closed breaks.',
            'Net Hours' => 'Last Sign Out minus first Sign In, minus closed breaks.',
            'Expected Hours' => 'Shift length minus the allowed break, on expected days.',
            'Balance' => 'Net hours minus expected hours.',
            'Missing Sign-out' => 'A day with a Sign In and no Sign Out, once the day (or shift) is over.',
            'Overtime Approved' => 'Length of the approved overtime windows.',
            'Overtime Worked' => 'Approved overtime covered by attendance: before the shift by the first Sign In, after it by the last Sign Out.',
            'Unapproved Extra' => 'Time after the shift end, up to the last Sign Out, not covered by approved overtime. On a day off: all presence outside approved overtime.',
            'Off Day' => 'A day that is not a configured working day, on which the employee signed in or had approved overtime. Counted in the Timesheet and the Overtime report only; it expects no hours.',
            'Overtime on a day off' => 'On days off and company holidays there is no shift, so the whole approved window is overtime and counts as worked where Sign In and Sign Out cover it.',
            'Utilisation' => 'Overtime worked / overtime approved.',
            'Leave requests' => 'Leave requests whose dates overlap the period (approved or pending), with their requested days.',
            'Remaining balance' => "The year's entitlement minus used days minus pending requests (negative when overdrawn).",
            'Hours (decimal)' => 'Hours as a decimal number for payroll, e.g. 7:10 = 7.17.',
        ];
    }
}
