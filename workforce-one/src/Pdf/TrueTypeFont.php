<?php
namespace WorkforceOne\Pdf;

if (!defined('ABSPATH')) exit;

/**
 * Reads a TrueType font (glyph ids from the Unicode cmap, advance widths, metrics) and writes a
 * subset for embedding in a PDF: glyphs that are not used become empty, so glyph ids stay the same
 * (the PDF maps CID = glyph id) and the file shrinks to the outlines actually drawn.
 * Pure: no WordPress calls.
 */
final class TrueTypeFont
{
    /** @var string */
    private $data;
    /** @var array<string,array{offset:int,length:int}> */
    private $tables = [];
    /** @var array<int,int> codepoint => glyph id */
    private $cmap = [];
    /** @var int[] advance width per glyph, font units */
    private $advances = [];
    /** @var int[] glyph offsets into glyf (numGlyphs + 1) */
    private $loca = [];

    public $unitsPerEm = 1000;
    public $ascent = 0;
    public $descent = 0;
    /** @var int[] xMin, yMin, xMax, yMax */
    public $bbox = [0, 0, 0, 0];
    public $numGlyphs = 0;

    public function __construct(string $data)
    {
        $this->data = $data;
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $rec = 12 + $i * 16;
            $this->tables[substr($data, $rec, 4)] = ['offset' => $this->u32($rec + 8), 'length' => $this->u32($rec + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap'] as $t) {
            if (!isset($this->tables[$t])) throw new \RuntimeException('Not a usable TrueType font: no ' . $t . ' table.');
        }
        $head = $this->tables['head']['offset'];
        $this->unitsPerEm = $this->u16($head + 18);
        $this->bbox = [$this->s16($head + 36), $this->s16($head + 38), $this->s16($head + 40), $this->s16($head + 42)];
        $longLoca = $this->s16($head + 50) === 1;
        $hhea = $this->tables['hhea']['offset'];
        $this->ascent = $this->s16($hhea + 4);
        $this->descent = $this->s16($hhea + 6);
        $metrics = $this->u16($hhea + 34);
        $this->numGlyphs = $this->u16($this->tables['maxp']['offset'] + 4);
        $hmtx = $this->tables['hmtx']['offset'];
        $last = 0;
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            if ($g < $metrics) $last = $this->u16($hmtx + $g * 4);
            $this->advances[$g] = $last;
        }
        $loca = $this->tables['loca']['offset'];
        for ($g = 0; $g <= $this->numGlyphs; $g++) $this->loca[$g] = $longLoca ? $this->u32($loca + $g * 4) : $this->u16($loca + $g * 2) * 2;
        $this->readCmap();
    }

    public static function fromFile(string $path): self
    {
        $data = @file_get_contents($path);
        if ($data === false) throw new \RuntimeException('Font not found: ' . $path);
        return new self($data);
    }

    /** Glyph id of a Unicode codepoint (0 = missing). */
    public function glyphId(int $codepoint): int
    {
        return $this->cmap[$codepoint] ?? 0;
    }

    /** Advance width of a glyph in 1/1000 em. */
    public function advance(int $gid): int
    {
        return (int) round(($this->advances[$gid] ?? 0) * 1000 / $this->unitsPerEm);
    }

    /** A value in font units as 1/1000 em. */
    public function scale(int $units): int
    {
        return (int) round($units * 1000 / $this->unitsPerEm);
    }

    /**
     * The font with only these glyphs (and the parts of composite glyphs they use) kept.
     * @param int[] $gids
     */
    public function subset(array $gids): string
    {
        $keep = [0 => true];
        $queue = array_values(array_unique(array_map('intval', $gids)));
        while ($queue) {
            $g = array_pop($queue);
            if ($g < 0 || $g >= $this->numGlyphs || isset($keep[$g])) continue;
            $keep[$g] = true;
            foreach ($this->components($g) as $c) if (!isset($keep[$c])) $queue[] = $c;
        }
        $glyf = '';
        $loca = '';
        $base = $this->tables['glyf']['offset'];
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            $loca .= pack('N', strlen($glyf));
            if (!isset($keep[$g])) continue;
            $glyph = substr($this->data, $base + $this->loca[$g], $this->loca[$g + 1] - $this->loca[$g]);
            $glyf .= $glyph . str_repeat("\0", (4 - strlen($glyph) % 4) % 4);
        }
        $loca .= pack('N', strlen($glyf));
        $out = [];
        foreach (['cmap', 'cvt ', 'fpgm', 'prep', 'hhea', 'hmtx', 'maxp'] as $t) {
            if (isset($this->tables[$t])) $out[$t] = substr($this->data, $this->tables[$t]['offset'], $this->tables[$t]['length']);
        }
        $head = substr($this->data, $this->tables['head']['offset'], $this->tables['head']['length']);
        $head = substr($head, 0, 8) . "\0\0\0\0" . substr($head, 12, 38) . pack('n', 1) . substr($head, 52); // checksum adjustment 0, long loca
        $out['head'] = $head;
        $out['loca'] = $loca;
        $out['glyf'] = $glyf;
        return self::build($out);
    }

    /** @return int[] glyphs a composite glyph is made of */
    private function components(int $g): array
    {
        $start = $this->tables['glyf']['offset'] + $this->loca[$g];
        if ($this->loca[$g + 1] - $this->loca[$g] < 10 || $this->s16($start) >= 0) return [];
        $out = [];
        $p = $start + 10;
        do {
            $flags = $this->u16($p);
            $out[] = $this->u16($p + 2);
            $p += 4 + (($flags & 0x0001) ? 4 : 2);
            if ($flags & 0x0008) $p += 2;
            elseif ($flags & 0x0040) $p += 4;
            elseif ($flags & 0x0080) $p += 8;
        } while ($flags & 0x0020);
        return $out;
    }

    /** @param array<string,string> $tables */
    private static function build(array $tables): string
    {
        ksort($tables, SORT_STRING);
        $n = count($tables);
        $pow = 1; $log = 0;
        while ($pow * 2 <= $n) { $pow *= 2; $log++; }
        $dir = pack('Nnnnn', 0x00010000, $n, $pow * 16, $log, $n * 16 - $pow * 16);
        $offset = 12 + $n * 16;
        $body = '';
        foreach ($tables as $tag => $bytes) {
            $padded = $bytes . str_repeat("\0", (4 - strlen($bytes) % 4) % 4);
            $dir .= $tag . pack('NNN', self::checksum($padded), $offset, strlen($bytes));
            $body .= $padded;
            $offset += strlen($padded);
        }
        $font = $dir . $body;
        // head.checkSumAdjustment makes the whole file sum to 0xB1B0AFBA.
        $headAt = strpos($dir, 'head') + 8;
        $headOffset = unpack('N', substr($dir, $headAt, 4))[1];
        $adjust = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
        return substr($font, 0, $headOffset + 8) . pack('N', $adjust) . substr($font, $headOffset + 12);
    }

    private static function checksum(string $bytes): int
    {
        $bytes .= str_repeat("\0", (4 - strlen($bytes) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $bytes) ?: [] as $v) $sum = ($sum + $v) & 0xFFFFFFFF;
        return $sum;
    }

    /** Unicode BMP cmap (platform 3 encoding 1, format 4). */
    private function readCmap(): void
    {
        $cmap = $this->tables['cmap']['offset'];
        $n = $this->u16($cmap + 2);
        $sub = null;
        for ($i = 0; $i < $n; $i++) {
            $rec = $cmap + 4 + $i * 8;
            if ($this->u16($rec) === 3 && $this->u16($rec + 2) === 1) { $sub = $cmap + $this->u32($rec + 4); break; }
            if ($this->u16($rec) === 0 && $sub === null) $sub = $cmap + $this->u32($rec + 4);
        }
        if ($sub === null || $this->u16($sub) !== 4) throw new \RuntimeException('The font has no Unicode (format 4) cmap.');
        $segs = $this->u16($sub + 6) / 2;
        $ends = $sub + 14;
        $starts = $ends + $segs * 2 + 2;
        $deltas = $starts + $segs * 2;
        $ranges = $deltas + $segs * 2;
        for ($s = 0; $s < $segs; $s++) {
            $end = $this->u16($ends + $s * 2);
            $start = $this->u16($starts + $s * 2);
            $delta = $this->u16($deltas + $s * 2);
            $range = $this->u16($ranges + $s * 2);
            if ($start === 0xFFFF) continue;
            for ($c = $start; $c <= $end; $c++) {
                if ($range === 0) $g = ($c + $delta) & 0xFFFF;
                else {
                    $g = $this->u16($ranges + $s * 2 + $range + ($c - $start) * 2);
                    if ($g !== 0) $g = ($g + $delta) & 0xFFFF;
                }
                if ($g !== 0) $this->cmap[$c] = $g;
            }
        }
    }

    private function u16(int $at): int { return unpack('n', substr($this->data, $at, 2))[1]; }
    private function s16(int $at): int { $v = $this->u16($at); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32(int $at): int { return unpack('N', substr($this->data, $at, 4))[1]; }
}
