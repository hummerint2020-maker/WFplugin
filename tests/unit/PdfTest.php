<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Pdf\ArabicText as A;
use WorkforceOne\Pdf\Document;
use WorkforceOne\Pdf\TrueTypeFont;
use WorkforceOne\Payroll\PayslipPdf;

final class PdfTest extends TestCase
{
    private static function font(): TrueTypeFont
    {
        return TrueTypeFont::fromFile(dirname(__DIR__, 2) . '/workforce-one/assets/vendor/dejavu/DejaVuSans.ttf');
    }

    public function testArabicLettersJoinAndRunRightToLeft(): void
    {
        // محمد: meem (initial), hah (medial), meem (medial), dal (final), drawn right to left.
        $this->assertSame([0xFEAA, 0xFEE4, 0xFEA4, 0xFEE3], A::visual('محمد'));
        $this->assertSame([0xFEFB], A::visual('لا'), 'lam-alef ligature');
        $this->assertSame([0xFEFC, 0xFEB3], A::visual('سلا'), 'lam-alef joined to the letter before');
        $this->assertSame([0xFE80, 0xFE8E, 0xFEE4, 0xFEB3], A::visual('سماء'), 'hamza does not join');
        $this->assertSame(A::codepoints('Net 10.50'), A::visual('Net 10.50'), 'Latin text is untouched');
        $this->assertSame(A::codepoints('ABC'), array_slice(A::visual('مشروع ABC'), 0, 3), 'a Latin word stays left to right inside Arabic');
        $this->assertSame(array_merge(A::codepoints('Bonus: '), A::visual('مكافأة')), A::visual('Bonus: مكافأة'), 'an Arabic run inside English');
        $this->assertSame([0xFEE1], A::visual('مَ'), 'harakat are dropped');
    }

    public function testFontAndSubset(): void
    {
        $f = self::font();
        $a = $f->glyphId(ord('A'));
        $this->assertGreaterThan(0, $a);
        $this->assertGreaterThan(500, $f->advance($a));
        $this->assertGreaterThan(0, $f->glyphId(0xFEE3), 'Arabic presentation forms are in the font');
        $sub = $f->subset([$a, $f->glyphId(0xFEE3)]);
        $this->assertLessThan(60000, strlen($sub), 'only the used outlines are kept');
        $again = new TrueTypeFont($sub);
        $this->assertSame($f->numGlyphs, $again->numGlyphs, 'glyph ids are unchanged');
        $this->assertSame($f->advance($a), $again->advance($a));
    }

    public function testDocumentIsAWellFormedPdf(): void
    {
        $d = new Document(self::font(), ['Title' => 'Test']);
        $d->text(48, 60, 'Hello أحمد', 12);
        $d->addPage();
        $d->text(48, 60, 'Page 2', 12, 'right');
        $pdf = $d->output();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertSame(2, $d->pageCount());
        preg_match('/startxref\n(\d+)/', $pdf, $m);
        $xref = (int) $m[1];
        $this->assertSame('xref', substr($pdf, $xref, 4));
        preg_match_all('/(\d{10}) 00000 n/', substr($pdf, $xref), $offsets);
        foreach ($offsets[1] as $i => $o) $this->assertSame(($i + 1) . ' 0 obj', substr($pdf, (int) $o, strlen(($i + 1) . ' 0 obj')), 'xref entry ' . ($i + 1));
    }

    public function testPayslipLongMonthFlowsOntoAnotherPage(): void
    {
        $late = [];
        for ($i = 1; $i <= 80; $i++) $late[] = ['date' => '2026-09-01', 'minutes' => 12, 'sign_in' => '08:12', 'amount' => 8.75];
        $pay = ['net' => 1.0, 'basic' => 9000.0, 'allowances' => [], 'allowances_total' => 0.0, 'earned' => 9000.0, 'prorated' => null, 'day_value' => 300.0,
            'stats' => ['worked_days' => 20, 'expected_days' => 22, 'late_days' => 80, 'absent_days' => 0],
            'overtime' => ['days' => [], 'minutes' => 0, 'amount' => 0.0], 'absence' => ['days' => [], 'amount' => 0.0],
            'late' => ['days' => $late, 'minutes' => 960, 'amount' => 700.0], 'early' => ['days' => [], 'minutes' => 0, 'amount' => 0.0],
            'leave' => ['days' => [], 'types' => [], 'amount' => 0.0], 'bonuses' => ['items' => [], 'amount' => 0.0], 'manual' => ['items' => [], 'amount' => 0.0],
            'cap' => null, 'deductions_before_cap' => 700.0];
        $pdf = PayslipPdf::render(self::font(), ['company' => 'BA Team', 'employee' => 'أحمد محمد', 'month_label' => 'September 2026', 'closed_at' => '2026-10-01 10:00:00',
            'currency' => 'EGP', 'rules' => ['day_divisor' => 30, 'day_base' => 'gross', 'max_deduction_days' => 0], 'pay' => $pay, 'generated' => '3 Oct 2026']);
        $this->assertGreaterThanOrEqual(2, substr_count($pdf, '/Type /Page '), 'more than one page');
    }
}
