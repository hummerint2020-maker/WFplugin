<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Support\SchemaSql;

final class SchemaSqlTest extends TestCase
{
    public function testOneLineTableBecomesOneColumnPerLine(): void
    {
        $sql = "CREATE TABLE wp_ews_notes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NOT NULL,type VARCHAR(30) NOT NULL DEFAULT 'a,b (c)',amount DECIMAL(10,2) NULL,PRIMARY KEY(id),UNIQUE KEY user_type(user_id, type),KEY user_id(user_id)) DEFAULT CHARACTER SET utf8mb4";
        $this->assertSame("CREATE TABLE wp_ews_notes (\n"
            . "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "user_id BIGINT(20) UNSIGNED NOT NULL,\n"
            . "type VARCHAR(30) NOT NULL DEFAULT 'a,b (c)',\n"
            . "amount DECIMAL(10,2) NULL,\n"
            . "PRIMARY KEY  (id),\n"
            . "UNIQUE KEY user_type (user_id,type),\n"
            . "KEY user_id (user_id)\n"
            . ") DEFAULT CHARACTER SET utf8mb4", SchemaSql::forDbDelta($sql));
    }

    public function testKeySpellings(): void
    {
        $out = SchemaSql::forDbDelta("CREATE TABLE IF NOT EXISTS `wp_ews_x` (\n  a INT NOT NULL,\n  b TINYINT(1) NOT NULL DEFAULT 0,\n  c SMALLINT UNSIGNED NULL,\n  d INTEGER NULL,\n  `key` VARCHAR(20) NULL,\n  PRIMARY KEY (a),\n  UNIQUE (b),\n  INDEX c_idx (c),\n  KEY (d),\n  UNIQUE INDEX u (a,b),\n  KEY pre (`key`(10))\n)");
        $this->assertSame("CREATE TABLE wp_ews_x (\n"
            . "a INT(11) NOT NULL,\n"
            . "b TINYINT(1) NOT NULL DEFAULT 0,\n"
            . "c SMALLINT(5) UNSIGNED NULL,\n"
            . "d INT(11) NULL,\n"
            . "`key` VARCHAR(20) NULL,\n"
            . "PRIMARY KEY  (a),\n"
            . "UNIQUE KEY b (b),\n"
            . "KEY c_idx (c),\n"
            . "KEY d (d),\n"
            . "UNIQUE KEY u (a,b),\n"
            . "KEY pre (`key`(10))\n"
            . ")", $out);
    }

    public function testAlreadyReadableStatementKeepsItsMeaning(): void
    {
        $sql = "CREATE TABLE wp_ews_tasks (\n    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n    title VARCHAR(255) NOT NULL,\n    PRIMARY KEY  (id),\n    KEY title (title)\n) ENGINE=InnoDB";
        $this->assertSame("CREATE TABLE wp_ews_tasks (\nid BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\ntitle VARCHAR(255) NOT NULL,\nPRIMARY KEY  (id),\nKEY title (title)\n) ENGINE=InnoDB", SchemaSql::forDbDelta($sql));
        $this->assertSame(SchemaSql::forDbDelta($sql), SchemaSql::forDbDelta(SchemaSql::forDbDelta($sql)));
    }

    public function testOtherStatementsAreLeftAlone(): void
    {
        $wp = "CREATE TABLE wp_posts (ID bigint(20) unsigned NOT NULL auto_increment,PRIMARY KEY(ID))";
        $this->assertSame($wp, SchemaSql::forDbDelta($wp));
        $this->assertSame('INSERT INTO wp_ews_x VALUES (1)', SchemaSql::forDbDelta('INSERT INTO wp_ews_x VALUES (1)'));
        $this->assertSame('CREATE TABLE wp_ews_broken (a INT', SchemaSql::forDbDelta('CREATE TABLE wp_ews_broken (a INT'));
        $this->assertSame('not an array', SchemaSql::filterQueries('not an array'));
        $this->assertSame(["CREATE TABLE wp_ews_y (\na INT(11)\n)", 'x'], SchemaSql::filterQueries(['CREATE TABLE wp_ews_y (a INT)', 'x']));
    }
}
