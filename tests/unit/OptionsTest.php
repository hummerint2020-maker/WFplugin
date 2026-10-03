<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\Options as O;

final class OptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $this->assertSame(10, O::defaultOf('ews_grace_period'));
        $this->assertSame([0, 1, 2, 3, 4], O::defaultOf('ews_working_days'));
        $this->assertSame(90, O::defaultOf('ews_notification_retention_days'));
        $this->assertSame('', O::defaultOf('ews_presence_kiosk_key_12'));
        $this->assertTrue(O::has('ews_presence_kiosk_key_12'));
        $this->assertFalse(O::has('ews_presence_kiosk_key_x'));
        $this->assertFalse(O::has('ews_made_up'));
        $this->expectException(\InvalidArgumentException::class);
        O::defaultOf('ews_made_up');
    }

    public function testEveryEntryIsComplete(): void
    {
        foreach (O::all() as $name => $d) {
            $this->assertArrayHasKey($d['group'], O::GROUPS, $name);
            $this->assertContains($d['kind'], ['bool', 'int', 'number', 'text', 'days', 'grouped', 'secret', 'data'], $name);
            $this->assertNotSame('', $d['label'], $name);
            if ($d['kind'] === 'grouped' && !$d['internal']) $this->assertNotNull($d['builtin'], $name . ' needs a description of its built-in defaults');
        }
    }

    public function testChanged(): void
    {
        $this->assertFalse(O::isChanged('ews_grace_period', '10'));
        $this->assertTrue(O::isChanged('ews_grace_period', '15'));
        $this->assertFalse(O::isChanged('ews_feature_breaks', ''), 'false is stored as an empty string');
        $this->assertTrue(O::isChanged('ews_feature_breaks', '1'));
        $this->assertFalse(O::isChanged('ews_working_days', ['4', '3', '2', '1', '0']), 'order and strings do not matter');
        $this->assertTrue(O::isChanged('ews_working_days', [0, 1, 2, 3, 4, 6]));
        $this->assertTrue(O::isChanged('ews_vapid_subject', 'mailto:a@b.co', 'mailto:c@d.co'), 'a default known only at runtime');
        $this->assertFalse(O::isChanged('ews_schema_version', '3.31.13'), 'internal values are never "changed"');
        $this->assertFalse(O::isChanged('ews_vapid_private_key', 'x'));
        $this->assertFalse(O::isChanged('ews_pwa_splash_settings', ['title' => 'x']), 'grouped settings are compared by the plugin');
    }

    public function testDisplay(): void
    {
        $this->assertSame('10 min', O::display('ews_grace_period', '10'));
        $this->assertSame('Forever', O::display('ews_notification_retention_days', 0));
        $this->assertSame('Sun, Mon, Sat', O::display('ews_working_days', ['6', 0, 1]));
        $this->assertSame('On', O::display('ews_feature_tasks', '1'));
        $this->assertSame('Set', O::display('ews_vapid_private_key', 'pem'));
        $this->assertSame('Not set', O::display('ews_vapid_private_key', ''));
        $this->assertSame('2 items', O::display('ews_employee_moments', [1 => [], 2 => []]));
        $this->assertSame('(empty)', O::display('ews_attendance_emails', ''));
        $this->assertSame('1.35 × hourly pay', O::display('ews_payroll_overtime_rate', '1.35'));
        $this->assertSame('No limit', O::display('ews_payroll_max_deduction_days', '0'));
        $this->assertFalse(O::isChanged('ews_payroll_overtime_rate', '1.35'));
        $this->assertTrue(O::isChanged('ews_payroll_overtime_rate', '1.5'));
    }

    public function testExportMasksSecretsAndData(): void
    {
        $this->assertSame('(set)', O::exportValue('ews_vapid_private_key', 'pem'));
        $this->assertSame('(set)', O::exportValue('ews_presence_kiosk_key_4', 'abc'));
        $this->assertSame(['count' => 1], O::exportValue('ews_employee_moments', [7 => ['birthday' => '1990-05-17']]));
        $this->assertSame(15, O::exportValue('ews_grace_period', '15'));
        $this->assertSame(false, O::exportValue('ews_feature_tasks', ''));
        $this->assertSame([0, 6], O::exportValue('ews_working_days', ['6', '0']));
    }

    public function testDiffersFrom(): void
    {
        $d = ['enabled' => 1, 'title' => 'A', 'ms' => 650, 'items' => ['x' => 1]];
        $this->assertFalse(O::differsFrom(null, $d), 'nothing stored');
        $this->assertFalse(O::differsFrom(['enabled' => '1', 'ms' => '650', 'other' => 'ignored'], $d));
        $this->assertFalse(O::differsFrom(['enabled' => true], $d));
        $this->assertTrue(O::differsFrom(['enabled' => false], $d));
        $this->assertTrue(O::differsFrom(['title' => 'B'], $d));
        $this->assertTrue(O::differsFrom(['items' => ['x' => 0]], $d));
    }

    /**
     * Every option the plugin reads or writes is registered, and every get_option() that passes a
     * default passes the registered one (null means "is it saved?" and is allowed).
     */
    public function testCodeUsesOnlyRegisteredOptionsWithTheirDefaults(): void
    {
        $root = dirname(__DIR__, 2) . '/workforce-one';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $seen = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'Options.php') continue;
            $code = (string) file_get_contents($file->getPathname());
            $rel = substr($file->getPathname(), strlen($root) + 1);
            preg_match_all("/(?:get|update|add|delete)_option\\(\\s*'(ews_[a-z0-9_]+)'(\\s*\\.)?/", $code, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $seen++;
                $name = $hit[1] . (!empty($hit[2]) ? '1' : '');
                $this->assertTrue(O::has($name), "$rel uses the unregistered option {$hit[1]}");
            }
            preg_match_all("/->option\\('([a-z0-9_]+)'\\)/", $code, $m);
            foreach ($m[1] as $name) {
                $seen++;
                $this->assertTrue(O::has($name), "$rel reads the unregistered option $name");
            }
            preg_match_all("/get_option\\('(ews_[a-z0-9_]+)'\\s*,\\s*((?:[^()]|\\((?:[^()]|\\([^()]*\\))*\\))+)\\)/", $code, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $literal = trim($hit[2]);
                if ($literal === 'null' || $hit[1] === 'ews_vapid_subject') continue;
                $value = eval('return ' . str_replace('NotificationSettings::', '\\WorkforceOne\\Settings\\NotificationSettings::', $literal) . ';');
                $this->assertEquals(O::defaultOf($hit[1]), $value, "$rel reads {$hit[1]} with default $literal; the registered default is " . var_export(O::defaultOf($hit[1]), true));
            }
        }
        $this->assertGreaterThan(150, $seen, 'the scan found the option calls');
    }
}
