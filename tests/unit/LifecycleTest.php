<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Support\Lifecycle;

/** 3.31.119: every scheduled event and role the plugin creates is cleared on deactivate / uninstall (src/Support/Lifecycle.php). */
final class LifecycleTest extends TestCase
{
    private function sources(): array
    {
        $root = dirname(__DIR__, 2) . '/workforce-one';
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php' && strpos($f->getPathname(), '/vendor/') === false) $files[] = $f->getPathname();
        }
        return $files;
    }

    public function testEveryScheduledHookIsListed(): void
    {
        $found = [];
        foreach ($this->sources() as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all("/wp_schedule_(?:single_)?event\\s*\\([^;]*?'(ews_[a-z0-9_]+)'\\s*(?:,|\\))/", $src, $m)) foreach ($m[1] as $hook) $found[$hook] = true;
        }
        unset($found['ews_auto_five_minutes']);   // a schedule interval, not a hook
        $this->assertNotEmpty($found);
        foreach (array_keys($found) as $hook) $this->assertContains($hook, Lifecycle::CRON_HOOKS, $hook . ' is scheduled but not cleared on deactivate / uninstall');
    }

    public function testEveryRoleIsListed(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/workforce-one/includes/trait-permissions.php');
        $this->assertSame(1, preg_match('/function role_defs\\(\\)\\{.*?return \\[(.*?)\\];/s', $src, $m));
        preg_match_all("/'(ews_[a-z_]+)'=>\\['name'/", $m[1], $roles);
        sort($roles[1]);
        $want = Lifecycle::ROLES;
        sort($want);
        $this->assertSame($want, $roles[1]);
    }

    public function testDeactivateAndUninstallUseTheList(): void
    {
        $root = dirname(__DIR__, 2) . '/workforce-one';
        $main = (string) file_get_contents($root . '/employee-schedule-manager.php');
        $this->assertSame(1, preg_match('/static function deactivate\\(.*?\\n    \\}/s', $main, $m));
        $this->assertStringContainsString('Lifecycle::clearCron()', $m[0]);
        $this->assertStringNotContainsString("wp_clear_scheduled_hook('ews_", $m[0]);
        $un = (string) file_get_contents($root . '/uninstall.php');
        $this->assertStringContainsString('Lifecycle::clearCron()', $un);
        $this->assertStringContainsString('Lifecycle::removeRoles()', $un);
        $this->assertStringNotContainsString("remove_role(\$role)", $un);
    }
}
