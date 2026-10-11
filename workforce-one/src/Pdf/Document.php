<?php
namespace WorkforceOne\Pdf;

if (!defined('ABSPATH')) exit;

/**
 * A small PDF writer: A4 pages with text, lines and filled boxes, in one embedded TrueType font
 * (a subset, Identity-H, with a ToUnicode map so the text can be searched and copied). Arabic is
 * joined and ordered by ArabicText. Coordinates are points from the top-left corner.
 * Pure: no WordPress calls.
 */
final class Document
{
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;

    /** @var TrueTypeFont */
    private $font;
    /** @var string[] content stream of each page */
    private $pages = [];
    /** @var array<int,int> glyph id => codepoint drawn */
    private $used = [];
    /** @var array<string,string> */
    private $info;

    /** @param array<string,string> $info Title, Author… */
    public function __construct(TrueTypeFont $font, array $info = [])
    {
        $this->font = $font;
        $this->info = $info;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Width of a text in points at a size. */
    public function textWidth(string $text, float $size): float
    {
        $w = 0;
        foreach (ArabicText::visual($text) as $c) $w += $this->font->advance($this->font->glyphId($c));
        return $w * $size / 1000;
    }

    /**
     * @param string $align left | right | center (x is the left edge, the right edge or the centre)
     * @param float[] $rgb 0–1
     */
    public function text(float $x, float $y, string $text, float $size, string $align = 'left', array $rgb = [0.06, 0.09, 0.16], bool $bold = false): void
    {
        if ($text === '') return;
        $width = $this->textWidth($text, $size);
        if ($align === 'right') $x -= $width;
        elseif ($align === 'center') $x -= $width / 2;
        $hex = '';
        foreach (ArabicText::visual($text) as $c) {
            $g = $this->font->glyphId($c);
            if ($g === 0 && $c === 0x2212) { $c = 0x2D; $g = $this->font->glyphId($c); }
            $this->used[$g] = $this->used[$g] ?? $c;
            $hex .= sprintf('%04X', $g);
        }
        $color = vsprintf('%.3F %.3F %.3F', $rgb);
        $mode = $bold ? sprintf('2 Tr %.3F w %s RG ', $size * 0.035, $color) : '0 Tr ';
        $this->add(sprintf('BT %s rg %s/F1 %.2F Tf 1 0 0 1 %.2F %.2F Tm <%s> Tj ET', $color, $mode, $size, $x, self::HEIGHT - $y, $hex));
    }

    /** @param float[] $rgb */
    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->add(sprintf('%s rg %.2F %.2F %.2F %.2F re f', vsprintf('%.3F %.3F %.3F', $rgb), $x, self::HEIGHT - $y - $h, $w, $h));
    }

    /** @param float[] $rgb */
    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $width = 0.6): void
    {
        $this->add(sprintf('%s RG %.2F w %.2F %.2F m %.2F %.2F l S', vsprintf('%.3F %.3F %.3F', $rgb), $width, $x1, self::HEIGHT - $y1, $x2, self::HEIGHT - $y2));
    }

    private function add(string $op): void
    {
        if (!$this->pages) $this->addPage();
        $this->pages[count($this->pages) - 1] .= $op . "\n";
    }

    /** The PDF file. */
    public function output(): string
    {
        if (!$this->pages) $this->addPage();
        $objects = [];
        $n = count($this->pages);
        // 1 catalog, 2 pages, 3 font (Type0), 4 CIDFont, 5 descriptor, 6 font file, 7 ToUnicode, 8 info, then page + content pairs.
        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = (9 + $i * 2) . ' 0 R';
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $name = 'WFOAAA+DejaVuSans';
        $objects[3] = '<< /Type /Font /Subtype /Type0 /BaseFont /' . $name . ' /Encoding /Identity-H /DescendantFonts [4 0 R] /ToUnicode 7 0 R >>';
        ksort($this->used);
        $w = '';
        foreach ($this->used as $g => $c) $w .= $g . ' [' . $this->font->advance($g) . '] ';
        $objects[4] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $name . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 5 0 R /CIDToGIDMap /Identity /DW 600 /W [' . trim($w) . '] >>';
        $b = $this->font->bbox;
        $objects[5] = sprintf('<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 6 0 R >>',
            $name, $this->font->scale($b[0]), $this->font->scale($b[1]), $this->font->scale($b[2]), $this->font->scale($b[3]), $this->font->scale($this->font->ascent), $this->font->scale($this->font->descent), $this->font->scale($this->font->ascent));
        $sub = $this->font->subset(array_keys($this->used));
        $objects[6] = self::stream($sub, '/Length1 ' . strlen($sub));
        $objects[7] = self::stream($this->toUnicode());
        $info = '';
        foreach ($this->info as $k => $v) $info .= '/' . $k . ' ' . self::utf16($v) . ' ';
        $objects[8] = '<< ' . $info . '/Producer (Workforce One) >>';
        foreach ($this->pages as $i => $content) {
            $objects[9 + $i * 2] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>', self::WIDTH, self::HEIGHT, 10 + $i * 2);
            $objects[10 + $i * 2] = self::stream($content);
        }
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R /Info 8 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private static function stream(string $data, string $extra = ''): string
    {
        $filter = '';
        if (function_exists('gzcompress')) { $data = (string) gzcompress($data, 6); $filter = ' /Filter /FlateDecode'; }
        return '<< /Length ' . strlen($data) . $filter . ($extra !== '' ? ' ' . $extra : '') . " >>\nstream\n" . $data . "\nendstream";
    }

    /** Glyph → character map, so text can be found and copied. */
    private function toUnicode(): string
    {
        $map = '';
        $lines = [];
        foreach ($this->used as $g => $c) {
            $u = mb_convert_encoding(mb_chr($c, 'UTF-8'), 'UTF-16BE', 'UTF-8');
            $lines[] = sprintf('<%04X> <%s>', $g, strtoupper(bin2hex($u)));
        }
        foreach (array_chunk($lines, 100) as $chunk) $map .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" . $map . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    /** A PDF text string in UTF-16 (for the document info). */
    private static function utf16(string $s): string
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
