<?php
namespace WorkforceOne\HrLetters;

use WorkforceOne\Pdf\Document;
use WorkforceOne\Pdf\Image;
use WorkforceOne\Pdf\TextBlock;
use WorkforceOne\Pdf\TrueTypeFont;

if (!defined('ABSPATH')) exit;

/**
 * An HR letter as a PDF (HL3), laid out as the trial letters the owner approved (docs/mockups/hr-letter/letter-ar.png): the company and its
 * address with the logo, the reference and the date (the Hijri date too in Arabic), a rule, the addressee in bold, the subject centred, the
 * body (more pages when it is long), the signatory's title and name with the signature and the stamp, and the footer. Arabic letters run
 * right to left. Amiri, bundled with the plugin. Pure: the font and the images come in as bytes.
 */
final class LetterPdf
{
    private const L = 56.0;
    private const R = 539.0;
    private const NAVY = [0.16, 0.18, 0.43];
    private const MUTED = [0.42, 0.45, 0.52];
    private const INK = [0.06, 0.09, 0.16];

    /** @var string */
    private $font;

    public function __construct(string $fontBytes)
    {
        $this->font = $fontBytes;
    }

    /**
     * @param array{lang:string,company:string,address:string,ref:string,date:string,date_hijri:string,addressee:string,subject:string,
     *   paragraphs:list<string>,signatory_title:string,signatory_name:string,footer:string} $l
     * @param array{logo?:string,signature?:string,stamp?:string} $images the bytes of each image HR saved (JPEG or PNG); a broken one is left out
     */
    public function render(array $l, array $images = []): string
    {
        $ar = $l['lang'] === 'ar';
        $base = $ar ? 'R' : 'L';
        $doc = new Document(new TrueTypeFont($this->font), ['Title' => $l['subject'] . ' ' . $l['ref'], 'Author' => $l['company']]);
        $doc->setFontName('Amiri-Regular');
        $doc->addPage();
        $img = [];
        foreach (['logo', 'signature', 'stamp'] as $k) {
            if (($images[$k] ?? '') === '') continue;
            try { $img[$k] = Image::fromString((string) $images[$k]); } catch (\Throwable $e) { /* a broken image is left out */ }
        }
        $start = $ar ? self::R : self::L;
        $end = $ar ? self::L : self::R;
        $alignStart = $ar ? 'right' : 'left';
        $alignEnd = $ar ? 'left' : 'right';
        $doc->rect(0, 0, Document::WIDTH, 6, self::NAVY);
        $x = $start;
        if (isset($img['logo'])) {
            $h = 46.0;
            $w = min(120.0, $h * $img['logo']->ratio());
            $doc->image($img['logo'], $ar ? self::R - $w : self::L, 34, $w, $h);
            $x = $ar ? self::R - $w - 12 : self::L + $w + 12;
        }
        $doc->text($x, 60, $l['company'], 18, $alignStart, self::NAVY, true, $base);
        if ($l['address'] !== '') $doc->text($x, 80, $l['address'], 9.5, $alignStart, self::MUTED, false, $base);
        $doc->text($end, 60, ($ar ? 'الرقم: ' : 'Ref: ') . $l['ref'], 9.5, $alignEnd, self::MUTED, false, $base);
        $doc->text($end, 76, ($ar ? 'التاريخ: ' : 'Date: ') . $l['date'], 9.5, $alignEnd, self::MUTED, false, $base);
        $y = 96.0;
        if ($ar && $l['date_hijri'] !== '') { $doc->text($end, 92, 'الموافق: ' . $l['date_hijri'], 9.5, $alignEnd, self::MUTED, false, $base); $y = 106.0; }
        $doc->line(self::L, $y, self::R, $y, [0.85, 0.86, 0.9]);
        $y += 40;
        $doc->text($start, $y, $l['addressee'], 13, $alignStart, self::INK, true, $base);
        $y += 36;
        $doc->text(Document::WIDTH / 2, $y, $l['subject'], 16, 'center', self::NAVY, true, $base);
        $y += 40;
        $size = $ar ? 12.5 : 11.5;
        $leading = $ar ? 24.0 : 20.0;
        $page = ['top' => 60.0, 'bottom' => 760.0];
        foreach ($l['paragraphs'] as $p) $y = TextBlock::draw($doc, $p, self::L, self::R, $y, $size, $leading, $ar, $page) + 10;
        // The signature block needs about 130 points; it starts a new page rather than being cut.
        if ($y + 130 > $page['bottom']) { $doc->addPage(); $y = $page['top']; }
        $y += 30;
        $sx = $ar ? self::L + 160 : self::R - 120;
        if ($l['signatory_title'] !== '') $doc->text($sx, $y, $l['signatory_title'], 12, 'center', self::INK, true, $base);
        if ($l['signatory_name'] !== '') $doc->text($sx, $y + 20, $l['signatory_name'], 12, 'center', self::INK, false, $base);
        if (isset($img['signature'])) {
            $w = min(140.0, 50 * $img['signature']->ratio());
            $doc->image($img['signature'], $sx - $w / 2, $y + 30, $w, 50);
        }
        if (isset($img['stamp'])) {
            $w = min(110.0, 90 * $img['stamp']->ratio());
            $doc->image($img['stamp'], $ar ? self::R - $w : self::L, $y - 10, $w, 90);
        }
        if ($l['footer'] !== '') {
            $doc->line(self::L, 800, self::R, 800, [0.85, 0.86, 0.9]);
            $doc->text(Document::WIDTH / 2, 816, $l['footer'], 8.5, 'center', self::MUTED, false, $base);
        }
        return $doc->output();
    }
}
