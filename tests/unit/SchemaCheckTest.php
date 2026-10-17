<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Support\SchemaCheck;

final class SchemaCheckTest extends TestCase
{
    public function testNothingMissing(): void
    {
        $this->assertSame([], SchemaCheck::missing(SchemaCheck::TABLES, SchemaCheck::COLUMNS));
    }

    public function testAMissingTableAndAColumnAnAlterDidNotAdd(): void
    {
        $tables = array_values(array_diff(SchemaCheck::TABLES, ['ews_signin_attempts']));
        $cols = SchemaCheck::COLUMNS;
        $cols['ews_time_logs'] = array_values(array_diff($cols['ews_time_logs'], ['photo_key']));
        $this->assertSame(['ews_signin_attempts', 'ews_time_logs.photo_key'], SchemaCheck::missing($tables, $cols));
    }
}
