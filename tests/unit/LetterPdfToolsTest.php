<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Pdf\ArabicText as A;
use WorkforceOne\Pdf\Document;
use WorkforceOne\Pdf\Image;
use WorkforceOne\Pdf\TextBlock;
use WorkforceOne\Pdf\TrueTypeFont;
use WorkforceOne\Support\HijriDate;
use WorkforceOne\Support\NumberWords;

/** The PDF groundwork of HR letters (docs/tasks/hr-letter.md §16, HL1): direction, numbers, wrapping, images, Hijri dates, words. */
final class LetterPdfToolsTest extends TestCase
{
    private static function amiri(): TrueTypeFont
    {
        return TrueTypeFont::fromFile(dirname(__DIR__, 2) . '/workforce-one/assets/vendor/amiri/Amiri-Regular.ttf');
    }

    /** A visual run written as text: Arabic letters as 'a', Arabic-Indic digits as Western ones, the rest as it is. */
    private static function plain(array $cps): string
    {
        $o = '';
        foreach ($cps as $c) $o .= $c >= 0x0660 && $c <= 0x0669 ? (string) ($c - 0x0660) : ($c === 0x066C ? ',' : ($c === 0x066B ? '.' : ($c < 0x80 ? chr($c) : 'a')));
        return $o;
    }

    public function testAThousandsSeparatorStaysInsideTheNumber(): void
    {
        $this->assertSame('18,500 aaaa', self::plain(A::visual('قدره ١٨٬٥٠٠')), 'the groups keep their order (the trial showed ٥٠٠٬١٨)');
        $this->assertSame('3.5 aaaa', self::plain(A::visual('قدره ٣٫٥')));
        $this->assertSame('10:30 aaaaaa', self::plain(A::visual('الساعة 10:30')));
        $this->assertSame('3a12', self::plain(A::visual('١٢،٣', 'R')), 'the Arabic comma (U+060C) is not a number separator: two numbers');
    }

    public function testTheCallerGivesTheParagraphDirection(): void
    {
        // A wrapped line of an Arabic letter that starts with a number: right to left all the same.
        $this->assertSame('.(3,500 aaaaa 15,000', self::plain(A::visual('١٥٬٠٠٠ وبدلات ٣٬٥٠٠).', 'R')));
        $this->assertSame('15,000 aaaaa 3,500).', self::plain(A::visual('١٥٬٠٠٠ وبدلات ٣٬٥٠٠).')), 'without a direction the first strong character decides, as before');
        $this->assertSame('(aaa aaaaaa) aaaa', self::plain(A::visual('مصري (ثمانية عشر)', 'R')), 'brackets mirror inside a right-to-left line');
        $this->assertSame('.HR-2026-000123 :aaaaa', self::plain(A::visual('الرقم: HR-2026-000123.', 'R')), 'the full stop ends the line on the left');
        $this->assertSame(A::codepoints('Ref: 12.'), A::visual('Ref: 12.', 'L'));
        $this->assertSame(A::codepoints('Net 10.50'), A::visual('Net 10.50'), 'English without a direction is unchanged');
    }

    public function testWrappingKeepsLogicalOrderAndLineBreaks(): void
    {
        $d = new Document(self::amiri());
        $text = str_repeat('تشهد الشركة بأن الموظف يعمل لديها ', 8) . "\n\nسطر جديد";
        $lines = TextBlock::wrap($d, $text, 12, 300);
        $this->assertGreaterThan(3, count($lines));
        foreach (array_slice($lines, 0, -2) as $l) if ($l !== '') $this->assertLessThanOrEqual(300.01, $d->textWidth($l, 12), $l);
        $this->assertSame(['', 'سطر جديد'], array_slice($lines, -2), 'an empty line is kept, a line break starts a line');
        $this->assertSame(str_repeat('تشهد الشركة بأن الموظف يعمل لديها ', 8), implode(' ', array_slice($lines, 0, -3)) . ' ' . $lines[count($lines) - 3] . ' ', 'the words in reading order');
        $this->assertSame(['supercalifragilisticexpialidocious'], TextBlock::wrap($d, 'supercalifragilisticexpialidocious', 12, 20), 'a word wider than the line is never cut');
    }

    public function testDrawingFlowsOntoNewPages(): void
    {
        $d = new Document(self::amiri());
        $d->addPage();
        $y = TextBlock::draw($d, str_repeat("سطر من نص الخطاب\n", 40), 56, 539, 700, 12, 24, true, ['top' => 60, 'bottom' => 800]);
        $this->assertSame(3, $d->pageCount(), '4 lines fit on the first page, 30 on the second, the rest on a third');
        $this->assertGreaterThan(60, $y);
        $this->assertStringStartsWith('%PDF-1.4', $d->output());
    }

    public function testImages(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD');
        $im = imagecreatetruecolor(4, 3);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagesetpixel($im, 1, 1, imagecolorallocatealpha($im, 200, 10, 10, 0));
        ob_start(); imagepng($im); $png = (string) ob_get_clean();
        ob_start(); imagejpeg($im); $jpg = (string) ob_get_clean();
        $p = Image::fromString($png);
        $this->assertSame([4, 3, 'DeviceRGB', 'FlateDecode'], [$p->width, $p->height, $p->colorSpace, $p->filter]);
        $this->assertSame(36, strlen((string) gzuncompress($p->data)), 'three bytes per pixel');
        $alpha = (string) gzuncompress($p->alpha);
        $this->assertSame(12, strlen($alpha));
        $this->assertSame("\0", $alpha[0], 'a clear pixel');
        $this->assertSame("\xFF", $alpha[5], 'the opaque pixel (1,1)');
        $this->assertSame(substr((string) gzuncompress($p->data), 15, 3), "\xC8\x0A\x0A", 'its colour');
        $j = Image::fromString($jpg);
        $this->assertSame([4, 3, 'DCTDecode', ''], [$j->width, $j->height, $j->filter, $j->alpha]);
        $this->assertSame($jpg, $j->data, 'JPEG passed through');
        $opaque = imagecreatetruecolor(2, 2);
        ob_start(); imagepng($opaque); $o = (string) ob_get_clean();
        $this->assertSame('', Image::fromString($o)->alpha, 'an opaque PNG has no mask');
        $d = new Document(self::amiri());
        $d->image($p, 56, 40, 80, 60);
        $d->image($p, 56, 140, 80, 60);
        $d->image($j, 200, 40, 80, 60);
        $pdf = $d->output();
        $this->assertSame(1, substr_count($pdf, '/SMask'), 'one mask for the PNG, drawn twice');
        $this->assertSame(1, substr_count($pdf, '/DCTDecode'));
        $this->assertStringContainsString('/XObject << /Im1 ', $pdf);
        $this->assertStringContainsString(' /Im2 ', $pdf, 'two images, each written once');
        $this->assertStringNotContainsString('/Im3', $pdf);
        foreach (['GIF89a....', '', "\x89PNG\r\n\x1A\nbroken"] as $bad) {
            try { Image::fromString($bad); $this->fail('refused: ' . bin2hex(substr($bad, 0, 6))); } catch (\InvalidArgumentException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
    }

    public function testADocumentWithoutImagesIsAsBefore(): void
    {
        $d = new Document(self::amiri());
        $d->text(48, 60, 'Hello', 12);
        $pdf = $d->output();
        $this->assertStringContainsString('/Resources << /Font << /F1 3 0 R >> >>', $pdf);
        $this->assertStringContainsString('/BaseFont /WFOAAA+DejaVuSans', $pdf, 'the default name');
        $d->setFontName('Amiri Regular!');
        $this->assertStringContainsString('/BaseFont /WFOAAA+AmiriRegular', $d->output());
    }

    public function testAmiriHasTheArabicForms(): void
    {
        $f = self::amiri();
        foreach ([0xFE8D, 0xFEE3, 0xFEFB, 0x0661, 0x066C, 0x066B, ord('A')] as $c) $this->assertGreaterThan(0, $f->glyphId($c), sprintf('U+%04X', $c));
    }

    public function testHijriDates(): void
    {
        $this->assertSame(['y' => 1420, 'm' => 9, 'd' => 24], HijriDate::tabular(2000, 1, 1));
        $this->assertSame(['y' => 1445, 'm' => 9, 'd' => 1], HijriDate::tabular(2024, 3, 11));
        $this->assertNull(HijriDate::fromGregorian('2026-02-30'));
        $h = HijriDate::fromGregorian('2024-03-11');
        $this->assertSame(['y' => 1445, 'm' => 9, 'd' => 1], $h, '1 Ramadan 1445 by either calendar');
        $this->assertSame('١ رمضان ١٤٤٥ هـ', HijriDate::format($h, 'ar'));
        $this->assertSame('1 Ramadan 1445 AH', HijriDate::format($h, 'en'));
    }

    public function testNumbersInWordsAndDigits(): void
    {
        $ar = [0 => 'صفر', 1 => 'واحد', 11 => 'أحد عشر', 12 => 'اثنا عشر', 21 => 'واحد وعشرون', 200 => 'مائتان', 1000 => 'ألف', 2000 => 'ألفان',
            3500 => 'ثلاثة آلاف وخمسمائة', 15000 => 'خمسة عشر ألفًا', 18500 => 'ثمانية عشر ألفًا وخمسمائة', 200000 => 'مائتا ألف',
            1250 => 'ألف ومائتان وخمسون', 1000000 => 'مليون', 2500000 => 'مليونان وخمسمائة ألف'];
        foreach ($ar as $n => $w) $this->assertSame($w, NumberWords::arabic($n), (string) $n);
        $this->assertSame('eighteen thousand five hundred', NumberWords::english(18500));
        $this->assertSame('one million two hundred thirty-four thousand five hundred sixty-seven', NumberWords::english(1234567));
        $this->assertSame('zero', NumberWords::english(0));
        $this->assertSame('١٨٬٥٠٠٫٥٠ جنيه', NumberWords::arabicDigits('18,500.50 جنيه'));
        $this->assertSame('رقم ١٢، و٣', NumberWords::arabicDigits('رقم 12، و3'), 'a comma not between digits stays');
    }
}
