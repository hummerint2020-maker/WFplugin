<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Health\QueryShape as Q;
use WorkforceOne\Health\Messages as M;

/** System health: what is kept of a query or an error message cannot tell who it was about. */
final class HealthQueryShapeTest extends TestCase
{
    public function testLiteralsAreRemoved(): void
    {
        $a = Q::normalize("SELECT * FROM wp_ews_employees WHERE email = 'ahmed@example.com' AND id = 42 AND name LIKE '%Ahmed Monier%'", 'wp_');
        $this->assertSame('SELECT * FROM {p}ews_employees WHERE email = ? AND id = ? AND name LIKE ?', $a);
        $this->assertStringNotContainsStringIgnoringCase('ahmed', $a);
        $this->assertStringNotContainsString('42', $a);
    }

    public function testTheSameShapeForDifferentValues(): void
    {
        $this->assertSame(Q::normalize("SELECT a FROM t WHERE x = 1 AND y = 'a'"), Q::normalize("SELECT a FROM t WHERE x = 99 AND y = 'it''s'"));
        $this->assertSame(Q::normalize('SELECT a FROM t WHERE id IN (1,2,3)'), Q::normalize('SELECT a FROM t WHERE id IN (7)'));
        $this->assertSame(Q::normalize("INSERT INTO t (a,b) VALUES (1,'x'),(2,'y'),(3,'z')"), Q::normalize("INSERT INTO t (a,b) VALUES (9,'q')"));
    }

    public function testTrickyLiterals(): void
    {
        $this->assertStringNotContainsString('secret', Q::normalize("SELECT 1 FROM t WHERE a = 'x\\'secret' AND b = \"secret2\""));
        $this->assertStringNotContainsString('secret', Q::normalize("SELECT 1 /* secret */ FROM t -- secret\n WHERE a = 0xDEADBEEF"));
        $this->assertSame('SELECT ? FROM t WHERE c = ?', Q::normalize("SELECT -5 FROM t WHERE c = 3.14"));
        $this->assertSame('SELECT b0, ews_table2 FROM t', Q::normalize('SELECT b0, ews_table2 FROM t'), 'digits inside names stay');
        $this->assertSame("SELECT a FROM t WHERE a = ?\n", Q::normalize("SELECT a\nFROM t\n\tWHERE a = 'x'") . "\n", 'whitespace collapsed');
    }

    public function testShapeIsBounded(): void
    {
        $this->assertLessThanOrEqual(Q::MAX, strlen(Q::normalize('SELECT ' . str_repeat('a_long_column, ', 200) . 'z FROM t')));
    }

    public function testCaller(): void
    {
        $s = "require('wp-blog-header.php'), do_action('admin_menu'), EWS_Manager_V31_1->admin_payroll, EWS_Manager_V31_1->payroll_month, wpdb->get_results, wpdb->query";
        $this->assertSame('payroll_month', Q::caller($s), 'the deepest plugin frame');
        $this->assertSame('Payroll\\PayCalculator::calculate', Q::caller('a(), WorkforceOne\\Payroll\\PayCalculator::calculate, wpdb->query'));
        $this->assertSame('', Q::caller('require(x), get_option, wpdb->get_results'), 'no plugin frame');
        $this->assertSame(16, strlen(Q::fingerprint('SELECT ?', 'x')));
        $this->assertNotSame(Q::fingerprint('SELECT ?', 'a'), Q::fingerprint('SELECT ?', 'b'));
    }

    public function testMessages(): void
    {
        $m = M::normalize("Undefined array key \"Ahmed\" in 'ahmed@example.com' on line 88");
        $this->assertSame('Undefined array key "?" in \'?\' on line N', $m);
        $this->assertSame('Cannot send to ? with key ?', M::normalize('Cannot send to ahmed.monier+x@example.co.uk with key ' . str_repeat('a1', 16)), 'an address and a token outside quotes');
        $this->assertLessThanOrEqual(M::MAX, strlen(M::normalize(str_repeat('x ', 400))));
        $this->assertSame('includes/trait-leave.php', M::relative('/var/www/wp-content/plugins/workforce-one/includes/trait-leave.php', '/var/www/wp-content/plugins/workforce-one'));
        $this->assertSame('', M::relative('/var/www/wp-content/plugins/other/x.php', '/var/www/wp-content/plugins/workforce-one'));
        $this->assertSame('', M::relative('/var/www/wp-content/plugins/workforce-one-evil/x.php', '/var/www/wp-content/plugins/workforce-one'), 'a sibling directory with the same start');
        $this->assertSame('warning', M::level(E_WARNING));
        $this->assertSame('error', M::level(E_USER_ERROR));
        $this->assertSame('deprecated', M::level(E_DEPRECATED));
        $this->assertSame('notice', M::level(E_NOTICE));
        $this->assertSame(M::fingerprint('warning', 'a.php', 3), M::fingerprint('warning', 'a.php', 3));
    }
}
