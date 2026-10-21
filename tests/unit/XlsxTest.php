<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Support\Xlsx;

final class XlsxTest extends TestCase
{
    public function testWrittenWorkbookReadsBack(): void
    {
        if (!Xlsx::available()) $this->markTestSkipped('ZipArchive');
        $data = Xlsx::write([['Managers', ['Employee ID', 'Name', 'Manager ID'], [[1, 'أحمد سمير', ''], [22, '=HYPERLINK("x")', 1]]], ['Help', ['How'], [['Line']]]]);
        $f = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($f, $data);
        $rows = Xlsx::read($f);
        @unlink($f);
        $this->assertSame([['Employee ID', 'Name', 'Manager ID'], ['1', 'أحمد سمير', ''], ['22', '=HYPERLINK("x")', '1']], $rows);
    }

    public function testCsvWithSemicolonsAndBom(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'c');
        file_put_contents($f, "\xEF\xBB\xBFDomain name;Manager domain name\r\nnour;'=mona\r\n");
        $this->assertSame([['Domain name', 'Manager domain name'], ['nour', '=mona']], Xlsx::read($f));
        @unlink($f);
    }

    public function testColumns(): void
    {
        $this->assertSame('A', Xlsx::col(1));
        $this->assertSame('Z', Xlsx::col(26));
        $this->assertSame('AA', Xlsx::col(27));
    }

    public function testNotAWorkbook(): void
    {
        if (!Xlsx::available()) $this->markTestSkipped('ZipArchive');
        $f = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($f, "PK\x03\x04broken");
        $this->assertNull(Xlsx::read($f));
        @unlink($f);
    }
}
