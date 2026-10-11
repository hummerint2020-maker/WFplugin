<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

/** Day counts of a report (the summary cards and the Excel summary). Pure. */
final class Summary
{
    /** Result buckets, in display order. Business-trip types (Training Course…) count as Business Trip. */
    public const BUCKETS = ['Present', 'Late', 'Absent', 'Leave', 'Holiday', 'Business Trip', 'Pending', 'Not Scheduled', 'Missing Sign-out'];

    /**
     * @param array<string,int> $summary
     * @param array{bucket:string,missing_sign_out:bool} $day a report_days() row
     * @return array<string,int>
     */
    public static function add(array $summary, array $day): array
    {
        $summary['records'] = ($summary['records'] ?? 0) + 1;
        if (in_array($day['bucket'], self::BUCKETS, true)) $summary[$day['bucket']] = ($summary[$day['bucket']] ?? 0) + 1;
        if ($day['missing_sign_out']) $summary['Missing Sign-out'] = ($summary['Missing Sign-out'] ?? 0) + 1;
        return $summary;
    }
}
