<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * How the employee app looks (wp-admin → Workforce One → Appearance): brand names, theme colours,
 * header style, font and corners. Colours are checked for contrast so text on them stays readable.
 * The app reads the result as CSS custom properties (cssVars()). Pure: no WordPress calls.
 */
final class Appearance
{
    public const PRESETS = [
        'indigo' => ['label' => 'Indigo Night', 'header_start' => '#13235B', 'header_end' => '#5B3FD9', 'highlight' => '#FFC83D', 'primary' => '#5B3FD9', 'font' => 'alexandria'],
        'nile' => ['label' => 'Nile Teal', 'header_start' => '#0B3B3A', 'header_end' => '#11796C', 'highlight' => '#F2C14E', 'primary' => '#0F7C70', 'font' => 'cairo'],
        'royal' => ['label' => 'Royal Blue', 'header_start' => '#0B2A6F', 'header_end' => '#2563EB', 'highlight' => '#FFFFFF', 'primary' => '#2563EB', 'font' => 'plex'],
        'sunset' => ['label' => 'Sunset', 'header_start' => '#6B1730', 'header_end' => '#B8432A', 'highlight' => '#FFE3A3', 'primary' => '#C2410C', 'font' => 'tajawal'],
    ];

    /** Bundled in assets/fonts/<key>/ (Arabic + Latin). 'variable' = one file covers every weight. */
    public const FONTS = [
        'alexandria' => ['label' => 'Alexandria', 'family' => 'Alexandria', 'variable' => true, 'weights' => [400, 700]],
        'cairo' => ['label' => 'Cairo', 'family' => 'Cairo', 'variable' => true, 'weights' => [400, 700]],
        'plex' => ['label' => 'IBM Plex Sans Arabic', 'family' => 'IBM Plex Sans Arabic', 'variable' => false, 'weights' => [400, 500, 600, 700]],
        'tajawal' => ['label' => 'Tajawal', 'family' => 'Tajawal', 'variable' => false, 'weights' => [400, 500, 700]],
        'system' => ['label' => 'Device font', 'family' => '', 'variable' => false, 'weights' => []],
    ];

    /** Corner radius of cards, controls and small items, in px. */
    public const CORNERS = [
        'sharp' => ['label' => 'Sharp', 'card' => 6, 'control' => 6, 'small' => 4],
        'soft' => ['label' => 'Soft', 'card' => 12, 'control' => 10, 'small' => 8],
        'round' => ['label' => 'Round', 'card' => 20, 'control' => 14, 'small' => 12],
    ];

    public const HEADER_STYLES = ['gradient' => 'Gradient', 'solid' => 'Solid colour'];

    public const COLOR_FIELDS = ['header_start', 'header_end', 'highlight', 'primary'];

    /** Text on the header, and the primary colour as text on white, need at least this contrast (WCAG AA). */
    public const MIN_CONTRAST = 4.5;

    public const DEFAULTS = [
        'preset' => 'indigo',
        'header_start' => '#13235B',
        'header_end' => '#5B3FD9',
        'highlight' => '#FFC83D',
        'primary' => '#5B3FD9',
        'header_style' => 'gradient',
        'font' => 'alexandria',
        'corners' => 'round',
        'company_name' => 'BA Team',
        'app_name' => 'Workforce One',
        'tagline' => 'One Platform. One Team. One Goal.',
        'logo_url' => '',
        // '1': the app's page shows only the app (no theme header, footer or page padding).
        'fullscreen' => '1',
    ];

    /**
     * Saved settings merged over the defaults; anything invalid falls back to the default.
     * @param mixed $saved
     * @return array<string, string>
     */
    public static function config($saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $out = self::DEFAULTS;
        $preset = (string) ($saved['preset'] ?? '');
        $out['preset'] = isset(self::PRESETS[$preset]) || $preset === 'custom' ? $preset : self::DEFAULTS['preset'];
        foreach (self::COLOR_FIELDS as $f) {
            $hex = self::hex($saved[$f] ?? '');
            if ($hex !== '') $out[$f] = $hex;
        }
        foreach (['header_style' => self::HEADER_STYLES, 'font' => self::FONTS, 'corners' => self::CORNERS] as $f => $allowed) {
            $v = (string) ($saved[$f] ?? '');
            if (isset($allowed[$v])) $out[$f] = $v;
        }
        foreach (['company_name' => 60, 'app_name' => 60, 'tagline' => 120] as $f => $max) {
            if (array_key_exists($f, $saved)) $out[$f] = self::cut(trim((string) $saved[$f]), $max);
        }
        $logo = trim((string) ($saved['logo_url'] ?? ''));
        $out['logo_url'] = preg_match('#^https?://[^\s"\'<>]+$#i', $logo) ? $logo : '';
        if (array_key_exists('fullscreen', $saved)) $out['fullscreen'] = (string) $saved['fullscreen'] === '0' ? '0' : '1';
        return $out;
    }

    /**
     * From the form. A preset fills in its colours and font unless "custom" is chosen; a colour that
     * fails the contrast check keeps its previous value and is reported.
     * @param array<string, mixed> $post   already unslashed; text fields already sanitised
     * @param mixed $previous               the stored value
     * @return array{config: array<string, string>, errors: list<string>}
     */
    public static function fromPost(array $post, $previous): array
    {
        $prev = self::config($previous);
        $errors = [];
        $in = $post;
        $preset = (string) ($post['preset'] ?? '');
        if (isset(self::PRESETS[$preset]) && (string) ($post['preset_applied'] ?? '') === '1') {
            // "Use this theme": the preset's colours and font replace what is in the colour fields.
            foreach (self::COLOR_FIELDS as $f) $in[$f] = self::PRESETS[$preset][$f];
            $in['font'] = self::PRESETS[$preset]['font'];
        }
        foreach (self::COLOR_FIELDS as $f) {
            $raw = trim((string) ($in[$f] ?? ''));
            if ($raw === '') { $in[$f] = $prev[$f]; continue; }
            if (self::hex($raw) === '') {
                $errors[] = sprintf('%s: "%s" is not a colour like #1A2B3C. The previous colour was kept.', self::fieldLabel($f), $raw);
                $in[$f] = $prev[$f];
            }
        }
        $cfg = self::config($in);
        // Contrast: white text on both ends of the header; the primary colour as text and as a button.
        foreach (['header_start', 'header_end'] as $f) {
            if ($f === 'header_end' && $cfg['header_style'] === 'solid') continue;
            if (self::contrast($cfg[$f], '#FFFFFF') < self::MIN_CONTRAST) {
                $errors[] = sprintf('%s %s is too light for white text (%.1f : 1, needs %.1f). The previous colour was kept.', self::fieldLabel($f), $cfg[$f], self::contrast($cfg[$f], '#FFFFFF'), self::MIN_CONTRAST);
                $cfg[$f] = $prev[$f];
            }
        }
        if (self::contrast($cfg['primary'], '#FFFFFF') < self::MIN_CONTRAST) {
            $errors[] = sprintf('%s %s is too light to read on white (%.1f : 1, needs %.1f). The previous colour was kept.', self::fieldLabel('primary'), $cfg['primary'], self::contrast($cfg['primary'], '#FFFFFF'), self::MIN_CONTRAST);
            $cfg['primary'] = $prev['primary'];
        }
        $ink = self::onColor($cfg['highlight'], $cfg['header_start']);
        if (self::contrast($cfg['highlight'], $ink) < self::MIN_CONTRAST) {
            $errors[] = sprintf('%s %s leaves its button text hard to read (%.1f : 1, needs %.1f). Pick a lighter or darker colour. The previous colour was kept.', self::fieldLabel('highlight'), $cfg['highlight'], self::contrast($cfg['highlight'], $ink), self::MIN_CONTRAST);
            $cfg['highlight'] = $prev['highlight'];
        }
        if (!isset(self::PRESETS[$cfg['preset']]) ||!self::matchesPreset($cfg, $cfg['preset'])) {
            $cfg['preset'] = self::presetOf($cfg);
        }
        return ['config' => $cfg, 'errors' => $errors];
    }

    /** The preset whose colours these are, or 'custom'. @param array<string, string> $cfg */
    public static function presetOf(array $cfg): string
    {
        foreach (array_keys(self::PRESETS) as $key) {
            if (self::matchesPreset($cfg, $key)) return $key;
        }
        return 'custom';
    }

    /** @param array<string, string> $cfg */
    private static function matchesPreset(array $cfg, string $key): bool
    {
        if (!isset(self::PRESETS[$key])) return false;
        foreach (self::COLOR_FIELDS as $f) {
            if (strtoupper($cfg[$f]) !== strtoupper(self::PRESETS[$key][$f])) return false;
        }
        return true;
    }

    /**
     * The design tokens as CSS custom properties for the app wrapper. The legacy names (--purple,
     * --purple-soft, --blue) follow the primary colour so pages not yet redesigned match the theme.
     * @param array<string, string> $cfg config()
     */
    public static function cssVars(array $cfg, string $selector = '.ews-app'): string
    {
        $c = self::config($cfg);
        $mid = self::mix($c['header_start'], $c['header_end'], 0.5);
        $hero = $c['header_style'] === 'solid'
            ? $c['header_start']
            : sprintf('linear-gradient(135deg,%s 0%%,%s 55%%,%s 100%%)', $c['header_start'], $mid, $c['header_end']);
        $soft = self::mix($c['primary'], '#FFFFFF', 0.88);
        $r = self::CORNERS[$c['corners']];
        $vars = [
            '--wfo-hero' => $hero,
            '--wfo-hero-start' => $c['header_start'],
            '--wfo-hero-mid' => $c['header_style'] === 'solid' ? $c['header_start'] : $mid,
            '--wfo-hl' => $c['highlight'],
            '--wfo-hl-ink' => self::onColor($c['highlight'], $c['header_start']),
            '--wfo-pri' => $c['primary'],
            '--wfo-pri-dark' => self::mix($c['primary'], '#000000', 0.18),
            '--wfo-pri-soft' => $soft,
            '--wfo-font' => self::fontStack($c['font']),
            '--wfo-r-card' => $r['card'] . 'px',
            '--wfo-r-ctl' => $r['control'] . 'px',
            '--wfo-r-sm' => $r['small'] . 'px',
            '--purple' => $c['primary'],
            '--purple2' => $c['primary'],
            '--purple-soft' => $soft,
            '--blue' => $c['primary'],
        ];
        $css = '';
        foreach ($vars as $k => $v) $css .= $k . ':' . $v . ';';
        return $selector . '{' . $css . '}';
    }

    /**
     * @font-face rules for the chosen font. $url maps a file name in assets/fonts/<key>/ to its URL.
     * @param callable(string): string $url
     */
    public static function fontFaces(string $font, callable $url): string
    {
        if (!isset(self::FONTS[$font]) || $font === 'system') return '';
        $def = self::FONTS[$font];
        // Unicode ranges as Google Fonts splits them (Arabic, Latin).
        $ranges = [
            'arabic' => 'U+0600-06FF,U+0750-077F,U+0870-088E,U+0890-0891,U+0897-08E1,U+08E3-08FF,U+200C-200E,U+2010-2011,U+204F,U+2E41,U+FB50-FDFF,U+FE70-FE74,U+FE76-FEFC',
            'latin' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
        ];
        $out = '';
        foreach ($ranges as $subset => $range) {
            $files = $def['variable'] ? [implode(' ', $def['weights']) => $font . '-' . $subset . '.woff2']
                : array_combine(array_map('strval', $def['weights']), array_map(function ($w) use ($font, $subset) { return $font . '-' . $w . '-' . $subset . '.woff2'; }, $def['weights']));
            foreach ($files as $weight => $file) {
                $out .= "@font-face{font-family:'" . $def['family'] . "';font-style:normal;font-weight:" . $weight . ';font-display:swap;src:url(' . $url($file) . ") format('woff2');unicode-range:" . $range . '}';
            }
        }
        return $out;
    }

    public static function fontStack(string $font): string
    {
        $system = 'system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif';
        $family = self::FONTS[$font]['family'] ?? '';
        return $family !== '' ? "'" . $family . "'," . $system : $system;
    }

    /** '#abc' / 'abc' / '#AABBCC' → '#AABBCC'; anything else → ''. @param mixed $v */
    public static function hex($v): string
    {
        $v = ltrim(trim((string) $v), '#');
        if (preg_match('/^[0-9a-f]{3}$/i', $v)) $v = $v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2];
        return preg_match('/^[0-9a-f]{6}$/i', $v) ? '#' . strtoupper($v) : '';
    }

    /** WCAG 2 contrast ratio between two colours (1 … 21). */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Readable text colour on $bg: $dark (the header colour) or white, whichever contrasts more. */
    public static function onColor(string $bg, string $dark = '#111827'): string
    {
        return self::contrast($bg, $dark) >= self::contrast($bg, '#FFFFFF') ? self::hex($dark) : '#FFFFFF';
    }

    /** $a moved $t of the way towards $b (0 = $a, 1 = $b). */
    public static function mix(string $a, string $b, float $t): string
    {
        $ra = self::rgb($a);
        $rb = self::rgb($b);
        $o = [];
        for ($i = 0; $i < 3; $i++) $o[] = (int) round($ra[$i] + ($rb[$i] - $ra[$i]) * $t);
        return sprintf('#%02X%02X%02X', $o[0], $o[1], $o[2]);
    }

    public static function fieldLabel(string $f): string
    {
        return ['header_start' => 'Header colour (start)', 'header_end' => 'Header colour (end)', 'highlight' => 'Highlight colour', 'primary' => 'Main colour'][$f] ?? $f;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim(self::hex($hex) ?: '#000000', '#');
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    private static function luminance(string $hex): float
    {
        $l = [];
        foreach (self::rgb($hex) as $c) {
            $c /= 255;
            $l[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    }

    private static function cut(string $s, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }
}
