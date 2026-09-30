<?php
/**
 * Wrap visible text inside HTML-bearing PHP string literals and inline HTML with gettext.
 *
 * php tools/i18n_wrap_php.php <file> <start-marker> <end-marker> [--apply]
 *
 * - '...<h3>Request Leave</h3>...'  ->  '...<h3>'.esc_html__('Request Leave','workforce-one').'</h3>...'
 * - inline HTML (outside <?php ?>)  ->  <?php esc_html_e('...','workforce-one'); ?>
 * Only single-quoted literals that contain a tag are touched, so array keys and stored
 * values (e.g. 'Approved') are left alone. Prints every string; review before --apply.
 */
[$file, $start, $end] = array_slice($argv, 1, 3);
$apply = in_array('--apply', $argv, true);
$src = file_get_contents($file);
$a = strpos($src, $start); $b = strpos($src, $end, $a);
if ($a === false || $b === false) { fwrite(STDERR, "markers not found\n"); exit(1); }
$region = substr($src, $a, $b - $a);
$tokens = token_get_all("<?php\n" . $region);
array_shift($tokens);
$D = "'workforce-one'";
$log = [];
$inTag = false;

function core_split(string $t): ?array {
    if (!preg_match('/[A-Za-z]{2,}/', $t)) return null;
    if (!preg_match('/^([^A-Za-z]*)(.*?)([^A-Za-z.!?…:)%\'’]*)$/su', $t, $m)) return null;
    if (trim($m[2]) === '' || preg_match('/[&{}$=\\\\"+;]|\bclass\b|https?:|\w\.\w+\(/', $m[2])) return null;
    return [$m[1], $m[2], $m[3]];
}
function q(string $s): string { return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'"; }

/** Walk HTML, calling $cb on each text run outside tags. $inTag carries across calls. */
function walk(string $html, bool &$inTag, callable $cb, ?callable $esc = null): string {
    $esc = $esc ?: function ($x) { return $x; };
    $out = ''; $i = 0; $n = strlen($html);
    while ($i < $n) {
        if ($inTag) {
            $j = strpos($html, '>', $i);
            if ($j === false) { $out .= $esc(substr($html, $i)); break; }
            $out .= $esc(substr($html, $i, $j - $i + 1)); $i = $j + 1; $inTag = false;
        } else {
            $j = strpos($html, '<', $i);
            $text = $j === false ? substr($html, $i) : substr($html, $i, $j - $i);
            $out .= $cb($text);
            if ($j === false) break;
            $i = $j; $inTag = true;
        }
    }
    return $out;
}

$out = '';
foreach ($tokens as $tok) {
    if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING && $tok[1][0] === "'"
        && preg_match('/<\/?[a-z][^<]*>|^\'[^<]*>|<[a-z]/i', $tok[1])) {
        $raw = stripcslashes_single(substr($tok[1], 1, -1));
        $new = walk($raw, $inTag, function ($t) use (&$log, $D) {
            $c = core_split($t); if (!$c) return str_replace(["\\", "'"], ["\\\\", "\\'"], $t);
            $log[] = $c[1];
            return str_replace(["\\", "'"], ["\\\\", "\\'"], $c[0]) . "'.esc_html__(" . q($c[1]) . ",$D).'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $c[2]);
        }, function ($x) { return str_replace(["\\", "'"], ["\\\\", "\\'"], $x); });
        // $new is already escaped for a single-quoted literal (text parts escaped above, tags below).
        $out .= "'" . $new . "'";
        continue;
    }
    if (is_array($tok) && $tok[0] === T_INLINE_HTML) {
        $out .= walk($tok[1], $inTag, function ($t) use (&$log, $D) {
            $c = core_split($t); if (!$c) return $t;
            $log[] = $c[1];
            return $c[0] . "<?php esc_html_e(" . q($c[1]) . ",$D); ?>" . $c[2];
        });
        continue;
    }
    $out .= is_array($tok) ? $tok[1] : $tok;
}
function stripcslashes_single(string $s): string { return str_replace(["\\'", "\\\\"], ["'", "\\"], $s); }

foreach ($log as $l) echo "  $l\n";
echo count($log), " strings\n";
if ($apply) file_put_contents($file, substr($src, 0, $a) . $out . substr($src, $b));
