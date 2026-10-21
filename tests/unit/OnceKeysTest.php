<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Ui\OnceKeys;

final class OnceKeysTest extends TestCase
{
    public function testOneTimeResultsAreDroppedFromLinks(): void
    {
        foreach (['dw_paid', 'cx_error', 'overtime_done', 'leave_sent', 'dw_added', 'saved'] as $k) $this->assertTrue(OnceKeys::isOnce($k), $k);
    }

    public function testARealFilterThatEndsInDoneIsKept(): void
    {
        $this->assertFalse(OnceKeys::isOnce('task_done'), 'Tasks "Show all" survives a reload');
        foreach (['site', 'start', 'dw_day', 'month', 'ews_view'] as $k) $this->assertFalse(OnceKeys::isOnce($k), $k);
    }

    public function testTheScriptKeepsTheSameKey(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/workforce-one/assets/js/workforce-one.js');
        foreach (OnceKeys::KEEP as $k) $this->assertStringContainsString('k!=="' . $k . '"', $js);
        $this->assertStringContainsString(substr(OnceKeys::PATTERN, 2, -2), $js, 'the script uses the same pattern');
    }
}
