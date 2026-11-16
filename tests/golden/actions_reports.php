<?php
/**
 * The golden harness's Report Center export trace (tests/golden/run.py; docs/tasks/performance-2.md §6, 3.31.149): the CSV of the
 * attendance, summary, timesheet and workforce reports, and each part of their Excel file (the minute it was generated taken out),
 * for the administrator over the week before today (not today: "now" would decide today's rows), with the exact SQL. The same calls on the base and on the head: the files must be equal.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_reports.php   (prints one JSON line)
 */
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/evidence_lib.php';
wp_set_current_user((int) get_user_by('login', 'admin')->ID);
$s = gmdate('Y-m-d', strtotime($D0 . ' -7 days'));
$e = $DM1;
foreach (['attendance' => ['status' => 'all'], 'attendance_late' => ['status' => 'Late'], 'summary' => [], 'timesheet' => [], 'workforce' => []] as $label => $get) {
    $type = $label === 'attendance_late' ? 'attendance' : $label;
    $_GET = $get + ['report_type' => $type, 'start' => $s, 'end' => $e, 'team' => 'all', 'employee_status' => 'all'];
    $run($label . '_csv', function () use ($call, $s, $e, $type) {
        $csv = $call('report_detailed_csv', $s, $e, $type);
        return is_resource($csv) ? stream_get_contents($csv) : $csv;   // a string before 3.31.149, a stream after: the same text
    });
    $run($label . '_xlsx', function () use ($call, $s, $e, $type, $fix) {
        $data = $call('report_xlsx_build', $type, $s, $e);
        if (!is_string($data)) return $data;
        $tmp = wp_tempnam('golden-report.xlsx');
        file_put_contents($tmp, $data);
        $zip = new ZipArchive();
        $out = [];
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $out[$name] = $fix(preg_replace('/Generated: \d{4}-\d{2}-\d{2} \d{2}:\d{2}/', 'Generated: T', (string) $zip->getFromIndex($i)));
            }
            $zip->close();
        }
        @unlink($tmp);
        return $out;
    });
}
$_GET = [];
echo "\n" . wp_json_encode($trace);
