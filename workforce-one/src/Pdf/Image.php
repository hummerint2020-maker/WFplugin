<?php
namespace WorkforceOne\Pdf;

if (!defined('ABSPATH')) exit;

/**
 * An image for Document::image() (HR letters: the letterhead logo, the signature, the stamp). JPEG is passed through as it is (DCT);
 * PNG (8 bits per channel, not interlaced: greyscale, RGB, palette, each with or without transparency) is decoded and written as
 * Flate data, its transparency as a separate greyscale mask (SMask), so a signature on a white or clear background prints cleanly.
 * Anything else is refused. Pure: no WordPress calls.
 */
final class Image
{
    /** @var int */
    public $width;
    /** @var int */
    public $height;
    /** @var string DeviceRGB | DeviceGray | DeviceCMYK */
    public $colorSpace;
    /** @var string DCTDecode | FlateDecode */
    public $filter;
    /** @var string the image data in that filter */
    public $data;
    /** @var string the transparency, one byte per pixel, Flate-compressed; '' when the image is opaque */
    public $alpha = '';
    /** @var string a /Decode entry (inverted CMYK JPEGs), or '' */
    public $decode = '';

    private function __construct()
    {
    }

    /**
     * @throws \InvalidArgumentException when the data is not a JPEG or a PNG this writer reads
     */
    public static function fromString(string $data): self
    {
        if (strncmp($data, "\xFF\xD8", 2) === 0) return self::jpeg($data);
        if (strncmp($data, "\x89PNG\r\n\x1A\n", 8) === 0) return self::png($data);
        throw new \InvalidArgumentException('image_type');
    }

    /** Width ÷ height (for fitting into a box). */
    public function ratio(): float
    {
        return $this->height > 0 ? $this->width / $this->height : 1.0;
    }

    private static function jpeg(string $data): self
    {
        $len = strlen($data);
        $i = 2;
        while ($i + 9 < $len) {
            if (ord($data[$i]) !== 0xFF) throw new \InvalidArgumentException('image_data');
            $marker = ord($data[$i + 1]);
            if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) { $i += 2; continue; }
            $seg = (ord($data[$i + 2]) << 8) | ord($data[$i + 3]);
            // SOF0–SOF15 except DHT (C4), JPG (C8) and DAC (CC): the frame header with the size and the number of components.
            if ($marker >= 0xC0 && $marker <= 0xCF && !in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                $img = new self();
                $img->height = (ord($data[$i + 5]) << 8) | ord($data[$i + 6]);
                $img->width = (ord($data[$i + 7]) << 8) | ord($data[$i + 8]);
                $components = ord($data[$i + 9]);
                if ($img->width < 1 || $img->height < 1 || !in_array($components, [1, 3, 4], true)) throw new \InvalidArgumentException('image_data');
                $img->colorSpace = $components === 1 ? 'DeviceGray' : ($components === 3 ? 'DeviceRGB' : 'DeviceCMYK');
                if ($components === 4) $img->decode = ' /Decode [1 0 1 0 1 0 1 0]';   // Adobe CMYK JPEGs are stored inverted
                $img->filter = 'DCTDecode';
                $img->data = $data;
                return $img;
            }
            $i += 2 + $seg;
        }
        throw new \InvalidArgumentException('image_data');
    }

    private static function png(string $data): self
    {
        $pos = 8;
        $len = strlen($data);
        $ihdr = null;
        $palette = '';
        $trns = '';
        $idat = '';
        while ($pos + 8 <= $len) {
            $n = unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            $body = substr($data, $pos + 8, $n);
            if (strlen($body) !== $n) throw new \InvalidArgumentException('image_data');
            if ($type === 'IHDR') $ihdr = unpack('Nw/Nh/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $body);
            elseif ($type === 'PLTE') $palette = $body;
            elseif ($type === 'tRNS') $trns = $body;
            elseif ($type === 'IDAT') $idat .= $body;
            elseif ($type === 'IEND') break;
            $pos += 12 + $n;
        }
        if (!$ihdr || $ihdr['w'] < 1 || $ihdr['h'] < 1 || $ihdr['depth'] !== 8 || $ihdr['interlace'] !== 0 || $ihdr['compression'] !== 0) throw new \InvalidArgumentException('image_format');
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$ihdr['color']] ?? 0;
        if (!$channels || ($ihdr['color'] === 3 && $palette === '')) throw new \InvalidArgumentException('image_format');
        $raw = @gzuncompress($idat);
        $w = (int) $ihdr['w'];
        $h = (int) $ihdr['h'];
        $stride = $w * $channels;
        if (!is_string($raw) || strlen($raw) !== $h * ($stride + 1)) throw new \InvalidArgumentException('image_data');
        $pixels = self::unfilter($raw, $w, $h, $channels);
        $color = '';
        $alpha = '';
        $hasAlpha = false;
        $color0 = (int) $ihdr['color'];
        if ($color0 === 0 || $color0 === 2) {
            // Opaque greyscale / RGB; a tRNS colour key makes that one colour clear.
            $color = $pixels;
            if ($trns !== '') {
                $key = $color0 === 0 ? chr(ord($trns[1] ?? "\0")) : chr(ord($trns[1] ?? "\0")) . chr(ord($trns[3] ?? "\0")) . chr(ord($trns[5] ?? "\0"));
                for ($p = 0, $t = $w * $h; $p < $t; $p++) {
                    $clear = substr($pixels, $p * $channels, $channels) === $key;
                    $alpha .= $clear ? "\0" : "\xFF";
                    if ($clear) $hasAlpha = true;
                }
            }
        } elseif ($color0 === 3) {
            for ($p = 0, $t = $w * $h; $p < $t; $p++) {
                $idx = ord($pixels[$p]);
                $color .= substr($palette, $idx * 3, 3);
                $a = $idx < strlen($trns) ? $trns[$idx] : "\xFF";
                $alpha .= $a;
                if ($a !== "\xFF") $hasAlpha = true;
            }
        } else {
            // Greyscale + alpha, or RGB + alpha: the colour and the alpha go to two images.
            $c = $channels - 1;
            for ($p = 0, $t = $w * $h; $p < $t; $p++) {
                $px = substr($pixels, $p * $channels, $channels);
                $color .= substr($px, 0, $c);
                $alpha .= $px[$c];
                if ($px[$c] !== "\xFF") $hasAlpha = true;
            }
        }
        $img = new self();
        $img->width = $w;
        $img->height = $h;
        $img->colorSpace = ($color0 === 0 || $color0 === 4) ? 'DeviceGray' : 'DeviceRGB';
        $img->filter = 'FlateDecode';
        $img->data = (string) gzcompress($color, 6);
        $img->alpha = $hasAlpha ? (string) gzcompress($alpha, 6) : '';
        return $img;
    }

    /** Reverses PNG's per-row filters (None, Sub, Up, Average, Paeth). */
    private static function unfilter(string $raw, int $w, int $h, int $bpp): string
    {
        $stride = $w * $bpp;
        $out = '';
        $prev = str_repeat("\0", $stride);
        for ($y = 0; $y < $h; $y++) {
            $off = $y * ($stride + 1);
            $type = ord($raw[$off]);
            $line = substr($raw, $off + 1, $stride);
            $cur = '';
            for ($x = 0; $x < $stride; $x++) {
                $a = $x >= $bpp ? ord($cur[$x - $bpp]) : 0;
                $b = ord($prev[$x]);
                $c = $x >= $bpp ? ord($prev[$x - $bpp]) : 0;
                $v = ord($line[$x]);
                switch ($type) {
                    case 0: break;
                    case 1: $v += $a; break;
                    case 2: $v += $b; break;
                    case 3: $v += intdiv($a + $b, 2); break;
                    case 4:
                        $p = $a + $b - $c;
                        $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                        $v += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        break;
                    default: throw new \InvalidArgumentException('image_data');
                }
                $cur .= chr($v & 0xFF);
            }
            $out .= $cur;
            $prev = $cur;
        }
        return $out;
    }
}
