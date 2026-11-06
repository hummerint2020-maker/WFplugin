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

    public function testCsvWithBomBeforeAQuotedFirstHeader(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'c');
        file_put_contents($f, "\xEF\xBB\xBF\"Employee ID\",\"Job title\"\r\n\"employee6\",\"Operations Coordinator\"\r\n");
        $this->assertSame([['Employee ID', 'Job title'], ['employee6', 'Operations Coordinator']], Xlsx::read($f));
        file_put_contents($f, "\"Employee ID\",\"Job title\"\r\n\"employee6\",\"Operations Coordinator\"\r\n");
        $this->assertSame([['Employee ID', 'Job title'], ['employee6', 'Operations Coordinator']], Xlsx::read($f), 'the same file without the mark');
        @unlink($f);
    }

    public function testWorkbookWithPrefixedElements(): void
    {
        if (!Xlsx::available()) $this->markTestSkipped('ZipArchive');
        $f = tempnam(sys_get_temp_dir(), 'x');
        $zip = new ZipArchive();
        $zip->open($f, ZipArchive::OVERWRITE);
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><x:workbook xmlns:x="' . $main . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><x:sheets><x:sheet name="S" sheetId="1" r:id="rId1"/></x:sheets></x:workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><pr:Relationships xmlns:pr="http://schemas.openxmlformats.org/package/2006/relationships"><pr:Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/data.xml"/></pr:Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><x:sst xmlns:x="' . $main . '"><x:si><x:t>Employee ID</x:t></x:si><x:si><x:t>Job title</x:t></x:si></x:sst>');
        $zip->addFromString('xl/worksheets/data.xml', '<?xml version="1.0"?><x:worksheet xmlns:x="' . $main . '"><x:sheetData><x:row r="1"><x:c r="A1" t="s"><x:v>0</x:v></x:c><x:c r="B1" t="s"><x:v>1</x:v></x:c></x:row><x:row r="2"><x:c r="A2" t="inlineStr"><x:is><x:t>employee6</x:t></x:is></x:c><x:c r="B2" t="inlineStr"><x:is><x:t>Coordinator</x:t></x:is></x:c></x:row></x:sheetData></x:worksheet>');
        $zip->close();
        $rows = Xlsx::read($f);
        @unlink($f);
        $this->assertSame([['Employee ID', 'Job title'], ['employee6', 'Coordinator']], $rows);
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
