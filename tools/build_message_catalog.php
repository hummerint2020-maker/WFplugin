<?php
/**
 * Builds workforce-one/languages/workforce-one-catalog.php: every text of the plugin that goes through gettext
 * (domain workforce-one), for the System messages page (3.31.165, docs/tasks/system-messages.md §3).
 *
 * Read from the code itself (PHP's own tokenizer), not from the .pot, so it is never stale: run it after changing
 * texts; tests/check_message_catalog.py fails when the shipped file differs from a fresh build.
 *
 * Per text (keyed like WordPress keys translations: msgid, or context . "\x04" . msgid):
 *   m  module (docs/architecture/modules.json name, from the file the text is in; the first place wins)
 *   r  up to 3 files that use it               n  how many places use it
 *   c  the translators comment             p  1 = a plural text (_n), not editable in R1
 *   j  1 = also used by JavaScript          k  kind guessed from the code: notification, error or ui
 *                                              (src/Messages/ImportantMessages.php can override it)
 *
 * Usage: php tools/build_message_catalog.php            writes the file
 *        php tools/build_message_catalog.php --stdout   prints it (the check compares)
 */
if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
$plugin = $root . '/workforce-one';
$modulesJson = json_decode((string) file_get_contents($root . '/docs/architecture/modules.json'), true)['modules'];

$traitModule = [];
foreach ($modulesJson as $name => $m) foreach ($m['traits'] as $t) $traitModule[$t] = $name;

const NS_MODULE = [
    'Achievements' => 'engagement', 'Api' => 'app', 'Approvals' => 'approvals', 'Attendance' => 'attendance', 'Audit' => 'platform',
    'DailyWorkers' => 'daily_workers', 'EarlyLeave' => 'overtime', 'Employees' => 'people', 'Health' => 'health', 'HrLetters' => 'hr_letters',
    'Leave' => 'leave', 'Locations' => 'locations', 'Messages' => 'messages', 'Notifications' => 'notifications', 'Organization' => 'people',
    'Overtime' => 'overtime', 'Payroll' => 'payroll', 'Pdf' => 'hr_letters', 'People' => 'people', 'Platform' => 'platform', 'Polls' => 'engagement',
    'Presence' => 'locations', 'Pwa' => 'app', 'Reports' => 'reports', 'Requests' => 'approvals', 'Schedule' => 'schedule', 'Settings' => 'platform',
    'Support' => 'platform', 'Ui' => 'shell',
];
/** Templates: the first matching word of the file name. */
const TEMPLATE_WORDS = [
    'leave' => 'leave', 'correction' => 'attendance', 'attendance' => 'attendance', 'time' => 'attendance', 'presence' => 'locations',
    'auto-attendance' => 'attendance', 'schedule' => 'schedule', 'office-minimum' => 'schedule', 'om-' => 'schedule', 'overtime' => 'overtime',
    'approvals' => 'approvals', 'requests' => 'approvals', 'new-request' => 'approvals', 'substitute' => 'approvals', 'face-reset' => 'attendance',
    'notifications' => 'notifications', 'tasks' => 'tasks', 'pay' => 'payroll', 'advances' => 'payroll', 'hr-letters' => 'hr_letters',
    'dw-' => 'daily_workers', 'daily-workers' => 'daily_workers', 'poll' => 'engagement', 'recognition' => 'engagement', 'achievements' => 'engagement',
    'moments' => 'engagement', 'smart-nudges' => 'engagement', 'report' => 'reports', 'employee' => 'people', 'people' => 'people',
    'departments' => 'people', 'teams' => 'people', 'team' => 'people', 'org-chart' => 'people', 'profile' => 'people', 'my-profile' => 'people',
    'locations' => 'locations', 'kiosk' => 'locations', 'system-health' => 'health', 'dashboard' => 'shell', 'layout' => 'shell',
    'navigation' => 'shell', 'login' => 'app', 'page-fullscreen' => 'app', 'pwa' => 'app', 'features' => 'platform', 'roles' => 'platform',
    'settings' => 'platform', 'appearance' => 'platform', 'audit' => 'platform', 'list-pager' => 'shell', 'system-messages' => 'messages',
];

/** name => [msgid arg index, context arg index or -1, plural] */
const FUNCS = [
    '__' => [0, -1, 0], '_e' => [0, -1, 0], 'esc_html__' => [0, -1, 0], 'esc_html_e' => [0, -1, 0], 'esc_attr__' => [0, -1, 0],
    'esc_attr_e' => [0, -1, 0], '_x' => [0, 1, 0], '_ex' => [0, 1, 0], 'esc_html_x' => [0, 1, 0], 'esc_attr_x' => [0, 1, 0],
    '_n' => [0, -1, 1], '_nx' => [0, 3, 1], '_n_noop' => [0, -1, 1], '_nx_noop' => [0, 2, 1],
];

function module_of(string $rel, array $traitModule): string
{
    if (preg_match('#^includes/trait-(.+)\.php$#', $rel, $m)) return $traitModule[$m[1]] ?? 'general';
    if (preg_match('#^src/([A-Za-z]+)/#', $rel, $m)) return NS_MODULE[$m[1]] ?? 'general';
    if (preg_match('#^templates/(?:admin/|app/|pwa/)?([^/]+)\.php$#', $rel, $m)) {
        foreach (TEMPLATE_WORDS as $w => $mod) if (strpos($m[1], $w) === 0) return $mod;
        return 'general';
    }
    return 'general';
}

function literal(string $tok): ?string
{
    if ($tok === '' ) return null;
    if ($tok[0] === "'") return str_replace(["\\'", '\\\\'], ["'", '\\'], substr($tok, 1, -1));
    if ($tok[0] === '"') {
        $s = substr($tok, 1, -1);
        if (preg_match('/(?<!\\\\)\$[a-zA-Z_{]/', $s)) return null;   // interpolation: not a fixed text
        return stripcslashes(str_replace('\\$', '$', $s));
    }
    return null;
}

/** Splits a call's argument tokens at top-level commas; each argument is its literal or null. @return list<?string> */
function call_args(array $toks, int $open, int &$close): array
{
    $depth = 0; $args = []; $cur = [];
    for ($i = $open; $i < count($toks); $i++) {
        $t = $toks[$i]; $s = is_array($t) ? $t[1] : $t;
        if (in_array($s, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            if ($depth === 1) continue;
        }
        if (in_array($s, [')', ']', '}'], true)) {
            $depth--;
            if ($depth === 0) { $args[] = $cur; $close = $i; break; }
        }
        if ($depth === 1 && $s === ',') { $args[] = $cur; $cur = []; continue; }
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        $cur[] = $t;
    }
    $out = [];
    foreach ($args as $a) {
        $out[] = (count($a) === 1 && is_array($a[0]) && $a[0][0] === T_CONSTANT_ENCAPSED_STRING) ? literal($a[0][1]) : null;
    }
    return $out;
}

$files = [];
foreach (['includes', 'src', 'templates'] as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin . '/' . $d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if (substr((string) $f, -4) === '.php') $files[] = substr((string) $f, strlen($plugin) + 1);
}
foreach (glob($plugin . '/*.php') ?: [] as $f) $files[] = basename($f);
sort($files);

$rows = [];
foreach ($files as $rel) {
    $src = (string) file_get_contents($plugin . '/' . $rel);
    $toks = token_get_all($src);
    $n = count($toks);
    // Functions that send a notification (their $msg / $title lines are notification texts).
    $fnOf = []; $fn = ''; $depth = 0; $fnDepth = -1; $notifies = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (is_array($t) && $t[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $n; $j++) if (is_array($toks[$j]) && $toks[$j][0] === T_STRING) { $fn = $toks[$j][1]; $fnDepth = $depth; break; } elseif ($toks[$j] === '(') break;
        }
        $s = is_array($t) ? $t[1] : $t;
        if ($s === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
        if ($s === '}') { $depth--; if ($depth === $fnDepth) { $fn = ''; $fnDepth = -1; } }
        $fnOf[$i] = $fn;
        if (is_array($t) && $t[0] === T_STRING && preg_match('/notify$/i', $t[1]) && $fn !== '') $notifies[$fn] = true;
    }
    $stack = []; $stmtVar = ''; $lastComment = ['', -10];
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        $s = is_array($t) ? $t[1] : $t;
        $line = is_array($t) ? $t[2] : null;
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            if (stripos($t[1], 'translators:') !== false) {
                $c = trim((string) preg_replace(['#^/\*+|\*+/$#', '#^//#', '#\s*\n\s*\*?\s*#'], ['', '', ' '], $t[1]));
                $lastComment = [trim(substr($c, stripos($c, 'translators:'))), $t[2] + substr_count($t[1], "\n")];
            }
            continue;
        }
        if (is_array($t) && $t[0] === T_WHITESPACE) continue;
        // The variable a statement assigns ($msg = …, $msg .= …), also after an if (…) or else.
        if ($s === ';' || $s === '{' || $s === '}') $stmtVar = '';
        if (!$stack && is_array($t) && $t[0] === T_VARIABLE) {
            $k = $i + 1; while ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) $k++;
            $nx = $toks[$k] ?? '';
            if ($nx === '=' || (is_array($nx) && $nx[0] === T_CONCAT_EQUAL)) $stmtVar = $t[1];
        }
        if ($s === '(') { $prev = $toks[$i - 1] ?? ''; $k = $i - 1; while ($k >= 0 && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) $k--; $stack[] = (is_array($toks[$k] ?? null) && $toks[$k][0] === T_STRING) ? strtolower($toks[$k][1]) : ''; continue; }
        if ($s === ')') { array_pop($stack); continue; }
        if (!(is_array($t) && $t[0] === T_STRING && isset(FUNCS[$t[1]]))) continue;
        $k = $i + 1; while ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) $k++;
        if (($toks[$k] ?? '') !== '(') continue;
        $prev = $i - 1; while ($prev >= 0 && is_array($toks[$prev]) && $toks[$prev][0] === T_WHITESPACE) $prev--;
        if (is_array($toks[$prev] ?? null) && in_array($toks[$prev][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) continue;
        $close = $k;
        $args = call_args($toks, $k, $close);
        [$mi, $ci, $plural] = FUNCS[$t[1]];
        $domainArg = $args[$plural ? ($t[1] === '_nx' ? 4 : ($t[1] === '_nx_noop' ? 3 : ($t[1] === '_n_noop' ? 2 : 3))) : ($ci >= 0 ? 2 : 1)] ?? null;
        if ($domainArg !== 'workforce-one') continue;
        $msgid = $args[$mi] ?? null;
        if ($msgid === null || $msgid === '') continue;
        $ctx = $ci >= 0 ? (string) ($args[$ci] ?? '') : '';
        $key = $ctx === '' ? $msgid : $ctx . "\x04" . $msgid;
        $kind = 'ui';
        $inNotify = false; $inDie = false;
        foreach ($stack as $f) { if (preg_match('/notify$/', $f)) $inNotify = true; if ($f === 'wp_die') $inDie = true; }
        $fn = $fnOf[$i] ?? '';
        if ($inNotify || (isset($notifies[$fn]) && in_array($stmtVar, ['$msg', '$title', '$message', '$request_msg', '$body'], true))) $kind = 'notification';
        elseif ($inDie || preg_match('/error|notice_text|_message$|_messages$/', $fn)) $kind = 'error';
        // As wp i18n make-pot: the translators comment on the line before the call (or on its line), used once.
        $comment = ($lastComment[1] >= $line - 1) ? $lastComment[0] : '';
        if ($comment !== '') $lastComment = ['', -10];
        // The file only, not the line: the catalog then changes only when a text is added, removed or moved to another file.
        $ref = $rel;
        if (!isset($rows[$key])) {
            $rows[$key] = ['m' => module_of($rel, $traitModule), 'r' => [$ref], 'n' => 1, 'c' => $comment, 'p' => $plural, 'j' => 0, 'k' => $kind];
        } else {
            $r = &$rows[$key];
            $r['n']++;
            if (count($r['r']) < 3 && !in_array($ref, $r['r'], true)) $r['r'][] = $ref;
            if ($r['c'] === '' && $comment !== '') $r['c'] = $comment;
            if ($kind === 'notification' || ($kind === 'error' && $r['k'] === 'ui')) $r['k'] = $kind;   // the most telling use wins
            unset($r);
        }
    }
}
// JavaScript texts (wp.i18n): the keys of the shipped script translations.
foreach (glob($plugin . '/languages/*.json') ?: [] as $f) {
    $j = json_decode((string) file_get_contents($f), true);
    foreach (array_keys($j['locale_data']['messages'] ?? []) as $key) {
        if ($key === '') continue;
        if (isset($rows[$key])) { $rows[$key]['j'] = 1; continue; }
        $rows[$key] = ['m' => 'app', 'r' => [(string) ($j['source'] ?? 'assets/js')], 'n' => 1, 'c' => '', 'p' => 0, 'j' => 1, 'k' => 'ui'];
    }
}
ksort($rows, SORT_STRING);

$lines = [];
foreach ($rows as $key => $r) {
    $lines[] = var_export((string) $key, true) . '=>[\'m\'=>' . var_export($r['m'], true) . ',\'r\'=>[' . implode(',', array_map(function ($x) { return var_export($x, true); }, $r['r']))
        . '],\'n\'=>' . $r['n'] . ',\'c\'=>' . var_export($r['c'], true) . ',\'p\'=>' . $r['p'] . ',\'j\'=>' . $r['j'] . ',\'k\'=>' . var_export($r['k'], true) . '],';
}
$out = "<?php\n// Generated by tools/build_message_catalog.php — do not edit. The texts of the plugin for the System messages page.\n"
    . "if (!defined('ABSPATH')) exit;\nreturn [\n" . implode("\n", $lines) . "\n];\n";
if (in_array('--stdout', $argv, true)) { echo $out; exit(0); }
file_put_contents($plugin . '/languages/workforce-one-catalog.php', $out);
fwrite(STDERR, count($rows) . " texts\n");
