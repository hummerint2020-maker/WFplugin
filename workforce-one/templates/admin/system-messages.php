<?php
/**
 * wp-admin → Settings → System messages (3.31.166, docs/tasks/system-messages.md; mockup /mnt/project-files/system-messages/mockup.html).
 * Only with the feature on. Prints what it is given; the edit panel opens with &edit=<hash>.
 *
 * @var string $lang            'ar' | 'en': the language being edited
 * @var array<string,mixed> $filters   lang, kind, module, q, edited
 * @var array{rows:list<array<string,mixed>>,total:int,page:int,pages:int} $list  MessageCatalog::listing()
 * @var array<string,mixed>|null $editing   MessageCatalog::row() of the text being edited
 * @var string|null $draft      what was typed when a save was refused
 * @var array<string,string> $modules   module => name
 * @var list<array{lang:string,key:string,original:string,text:string,hash:string}> $review  saved texts that no longer apply
 * @var int    $total           texts in the plugin
 * @var int    $edited_count    edited texts (both languages)
 * @var string $notice          translated
 * @var string $error           translated
 * @var bool   $locale_warning  an Arabic locale other than "ar": the shipped Arabic translation is not loaded there
 * @var string $post_url
 * @var callable(array<string,mixed>):string $url  the page's address with the current filters, changed by $args
 * @var array<string,string> $links   the other places texts are edited
 */
if (!defined('ABSPATH')) exit;
$kinds = ['' => __('All', 'workforce-one'), 'notification' => __('Notifications', 'workforce-one'), 'banner' => __('Notices & banners', 'workforce-one'),
    'error' => __('Errors', 'workforce-one'), 'ui' => __('Buttons & titles', 'workforce-one')];
$kind_badge = ['notification' => [__('Notification', 'workforce-one'), 'notif'], 'banner' => [__('Notice', 'workforce-one'), 'banner'],
    'error' => [__('Error', 'workforce-one'), 'error'], 'ui' => [__('Button / title', 'workforce-one'), 'ui']];
/** The text with each placeholder shown as a named chip (escaped). */
$chips = static function (string $s, array $labels): string {
    $out = esc_html($s);
    $n = 0;
    return (string) preg_replace_callback('/%(?:(\d+)\$)?[-+ 0\'#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', static function ($m) use ($labels, &$n) {
        $key = $m[0];
        if (($m[1] ?? '') === '') { $n++; $key = '%' . $n . '$' . substr($m[0], 1); }
        $label = $labels[$key] ?? $labels[$m[0]] ?? $m[0];
        return '<span class="ews-sm-ph" title="' . esc_attr($m[0]) . '">' . esc_html($label) . '</span>';
    }, $out);
};
$hidden = static function () use ($filters, $list): void {
    echo '<input type="hidden" name="lang" value="' . esc_attr((string) $filters['lang']) . '"><input type="hidden" name="f_kind" value="' . esc_attr((string) $filters['kind']) . '">'
        . '<input type="hidden" name="f_module" value="' . esc_attr((string) $filters['module']) . '"><input type="hidden" name="f_q" value="' . esc_attr((string) $filters['q']) . '">'
        . '<input type="hidden" name="f_edited" value="' . (int) $filters['edited'] . '"><input type="hidden" name="f_paged" value="' . (int) $list['page'] . '">';
};
$dir = $lang === 'ar' ? 'rtl' : 'ltr';
?>
<style>
.ews-sm{max-width:1200px}
.ews-sm-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 18px;margin:14px 0}
.ews-sm-stats{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0}
.ews-sm-stats span{border:1px solid #dcdcde;border-radius:6px;padding:3px 10px;background:#fff}
.ews-sm-stats .warn{border-color:#dba617;background:#fcf9e8}
.ews-sm-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:10px 0}
.ews-sm-chip{display:inline-block;border:1px solid #c3c4c7;border-radius:999px;padding:2px 12px;text-decoration:none;color:#1d2327;background:#fff}
.ews-sm-chip.on{background:#2271b1;border-color:#2271b1;color:#fff}
.ews-sm table{table-layout:fixed}
.ews-sm td{vertical-align:top;word-wrap:break-word}
.ews-sm-kind{display:inline-block;font-size:11px;border-radius:4px;padding:0 7px}
.ews-sm-kind.notif{background:#efe9fb;color:#5b3fd9}.ews-sm-kind.banner{background:#fcf0d6;color:#8a5a00}
.ews-sm-kind.error{background:#fcebea;color:#b32d2e}.ews-sm-kind.ui{background:#f0f0f1;color:#50575e}
.ews-sm-orig{color:#50575e;direction:ltr;text-align:left;unicode-bidi:plaintext}
.ews-sm-ph{display:inline-block;background:#efe9fb;color:#5b3fd9;border-radius:4px;padding:0 6px;font-size:12px;white-space:nowrap}
.ews-sm-badge{font-size:12px;border-radius:999px;padding:1px 9px;white-space:nowrap;border:1px solid #dcdcde;color:#50575e}
.ews-sm-badge.ed{background:#edfaef;border-color:#00a32a;color:#007017}
.ews-sm-edit{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:18px}
@media (max-width:900px){.ews-sm-edit{grid-template-columns:1fr}}
.ews-sm-edit textarea{width:100%;min-height:90px}
.ews-sm-note{color:#50575e;border-inline-start:3px solid #dcdcde;padding-inline-start:10px;margin:8px 0}
.ews-sm-preview{background:#f0f0f1;border-radius:12px;padding:12px}
.ews-sm-push{background:#fff;border-radius:10px;padding:10px 12px;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.ews-sm-strip{background:#fcf0d6;color:#8a5a00;border-radius:8px;padding:10px 12px}
.ews-sm-err{background:#fcebea;color:#b32d2e;border-radius:8px;padding:10px 12px}
.ews-sm-review{border:1px solid #dba617;background:#fcf9e8;border-radius:6px;padding:10px 12px;margin:8px 0}
</style>
<div class="wrap ews-sm" data-sm-page>
<h1><?php esc_html_e('System messages', 'workforce-one'); ?></h1>
<p style="max-width:780px;color:#50575e"><?php esc_html_e('Change the words of any text the system shows, in each language. An empty text, or "Back to default", shows the original again. A change applies to new notifications only: the ones already sent keep their words.', 'workforce-one'); ?>
 <?php esc_html_e('Also edited elsewhere:', 'workforce-one'); ?> <a href="<?php echo esc_url($links['offline']); ?>"><?php esc_html_e('Offline screen', 'workforce-one'); ?></a> · <a href="<?php echo esc_url($links['login']); ?>"><?php esc_html_e('Login page', 'workforce-one'); ?></a> · <a href="<?php echo esc_url($links['occasions']); ?>"><?php esc_html_e('Occasions', 'workforce-one'); ?></a></p>
<div class="ews-sm-stats">
    <span><strong><?php echo esc_html(number_format_i18n($total)); ?></strong> <?php esc_html_e('texts', 'workforce-one'); ?></span>
    <span data-sm-edited-count><strong><?php echo (int) $edited_count; ?></strong> <?php esc_html_e('edited', 'workforce-one'); ?></span>
    <?php if ($review): ?><span class="warn"><strong><?php echo count($review); ?></strong> <?php esc_html_e('need review', 'workforce-one'); ?></span><?php endif; ?>
</div>
<?php if ($notice !== ''): ?><div class="notice notice-success is-dismissible" data-sm-notice><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php if ($error !== '' && !$editing): ?><div class="notice notice-error" data-sm-error><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<?php if ($locale_warning): ?><div class="notice notice-warning inline"><p><?php esc_html_e('This site\'s language is an Arabic variant the plugin has no translation for: it shows the English texts, except the ones edited here in Arabic.', 'workforce-one'); ?></p></div><?php endif; ?>

<nav class="nav-tab-wrapper">
    <a class="nav-tab<?php echo $lang === 'ar' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($url(['lang' => 'ar', 'paged' => 0])); ?>" data-sm-lang="ar">العربية</a>
    <a class="nav-tab<?php echo $lang === 'en' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($url(['lang' => 'en', 'paged' => 0])); ?>" data-sm-lang="en">English</a>
</nav>

<div class="ews-sm-filters">
    <?php foreach ($kinds as $k => $label): ?><a class="ews-sm-chip<?php echo (string) $filters['kind'] === $k ? ' on' : ''; ?>" href="<?php echo esc_url($url(['kind' => $k, 'paged' => 0])); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?>
</div>
<form method="get" class="ews-sm-filters" action="<?php echo esc_url(admin_url('admin.php')); ?>">
    <input type="hidden" name="page" value="ews31-system-messages"><input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>"><input type="hidden" name="kind" value="<?php echo esc_attr((string) $filters['kind']); ?>">
    <select name="module" aria-label="<?php esc_attr_e('Part of the system', 'workforce-one'); ?>"><option value=""><?php esc_html_e('Every part of the system', 'workforce-one'); ?></option>
        <?php foreach ($modules as $m => $name): ?><option value="<?php echo esc_attr($m); ?>" <?php selected((string) $filters['module'], $m); ?>><?php echo esc_html($name); ?></option><?php endforeach; ?></select>
    <input type="search" name="q" value="<?php echo esc_attr((string) $filters['q']); ?>" placeholder="<?php esc_attr_e('Search the original or the current text…', 'workforce-one'); ?>" style="min-width:260px">
    <label><input type="checkbox" name="edited" value="1" <?php checked(!empty($filters['edited'])); ?>> <?php esc_html_e('Edited only', 'workforce-one'); ?></label>
    <button class="button"><?php esc_html_e('Filter', 'workforce-one'); ?></button>
</form>

<?php if ($editing): $e = $editing; $value = $draft !== null ? $draft : ($e['edited'] ? (string) $e['current'] : ''); ?>
<div class="ews-sm-box" id="ews-sm-edit" data-sm-editor data-kind="<?php echo esc_attr((string) $e['kind']); ?>">
    <h2 style="margin-top:0"><?php echo $lang === 'ar' ? esc_html__('Edit the Arabic text', 'workforce-one') : esc_html__('Edit the English text', 'workforce-one'); ?></h2>
    <?php if ($error !== ''): ?><div class="notice notice-error inline" data-sm-error><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
    <div class="ews-sm-edit">
        <form method="post" action="<?php echo esc_url($post_url); ?>">
            <?php wp_nonce_field('ews_messages_save'); ?><input type="hidden" name="action" value="ews_messages_save"><?php $hidden(); ?>
            <input type="hidden" name="hash" value="<?php echo esc_attr((string) $e['hash']); ?>"><input type="hidden" name="expected" value="<?php echo esc_attr((string) $e['current']); ?>">
            <p><strong><?php esc_html_e('Original (English)', 'workforce-one'); ?></strong><br><span class="ews-sm-orig"><?php echo esc_html((string) $e['original']); ?></span><?php if ($e['context'] !== ''): ?> <em>(<?php echo esc_html((string) $e['context']); ?>)</em><?php endif; ?></p>
            <p><strong><?php esc_html_e('Default', 'workforce-one'); ?></strong><br><span dir="<?php echo esc_attr($dir); ?>" data-sm-default><?php echo $chips((string) $e['default'], (array) $e['labels']); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by $chips ?></span></p>
            <?php if ($e['comment'] !== ''): ?><p class="ews-sm-note"><?php echo esc_html((string) $e['comment']); ?></p><?php endif; ?>
            <?php if ((int) $e['places'] > 1): ?><div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(/* translators: %d: number of places */ __('This text is used in %d places: the change applies to all of them.', 'workforce-one'), (int) $e['places'])); ?></p></div><?php endif; ?>
            <?php if ($e['plural']): ?><p data-sm-plural><em><?php esc_html_e('Texts with a number in them (one day, 2 days…) cannot be edited yet.', 'workforce-one'); ?></em></p>
            <?php else: ?>
            <p><label for="ews-sm-text"><strong><?php esc_html_e('Your text', 'workforce-one'); ?></strong> <span style="color:#50575e"><?php echo esc_html(sprintf(/* translators: %d: characters */ __('(up to %d characters)', 'workforce-one'), (int) $e['limit'])); ?></span></label>
                <textarea id="ews-sm-text" name="text" dir="<?php echo esc_attr($dir); ?>" maxlength="<?php echo (int) $e['limit']; ?>" placeholder="<?php echo esc_attr((string) $e['default']); ?>" data-sm-text><?php echo esc_textarea($value !== '' ? $value : (string) $e['default']); ?></textarea></p>
            <?php if ($e['labels']): ?><p data-sm-placeholders><?php esc_html_e('Keep these in the text:', 'workforce-one'); ?>
                <?php foreach ((array) $e['labels'] as $ph => $label): ?><button type="button" class="button button-small" data-sm-insert="<?php echo esc_attr((string) $ph); ?>">+ <?php echo esc_html((string) $label); ?></button> <?php endforeach; ?></p><?php endif; ?>
            <p><button class="button button-primary" data-sm-save><?php esc_html_e('Save', 'workforce-one'); ?></button>
                <?php if ($e['edited']): ?><button class="button-link button-link-delete" name="reset" value="1" style="margin-inline-start:10px" data-sm-reset><?php esc_html_e('Back to default', 'workforce-one'); ?></button><?php endif; ?>
                <a href="<?php echo esc_url($url([])); ?>" style="margin-inline-start:10px"><?php esc_html_e('Close', 'workforce-one'); ?></a></p>
            <?php endif; ?>
        </form>
        <div class="ews-sm-preview" aria-live="polite"><p style="margin-top:0;color:#50575e"><?php esc_html_e('Preview', 'workforce-one'); ?></p><div data-sm-preview data-labels="<?php echo esc_attr((string) wp_json_encode((array) $e['labels'])); ?>"></div></div>
    </div>
</div>
<?php endif; ?>

<table class="widefat striped" data-sm-table>
    <thead><tr><th style="width:150px"><?php esc_html_e('Where', 'workforce-one'); ?></th><th><?php esc_html_e('Original', 'workforce-one'); ?></th><th><?php esc_html_e('Current text', 'workforce-one'); ?></th><th style="width:80px"><?php esc_html_e('Status', 'workforce-one'); ?></th><th style="width:80px"></th></tr></thead>
    <tbody>
    <?php if (!$list['rows']): ?><tr><td colspan="5"><?php esc_html_e('No text matches.', 'workforce-one'); ?></td></tr><?php endif; ?>
    <?php foreach ($list['rows'] as $r): $b = $kind_badge[(string) $r['kind']] ?? $kind_badge['ui']; ?>
    <tr data-sm-row="<?php echo esc_attr((string) $r['hash']); ?>">
        <td><span class="ews-sm-kind <?php echo esc_attr($b[1]); ?>"><?php echo esc_html($b[0]); ?></span><br><small><?php echo esc_html($modules[(string) $r['module']] ?? (string) $r['module']); ?><?php if ((int) $r['places'] > 1): ?> · <?php echo esc_html(sprintf(/* translators: %d: number of places */ __('in %d places', 'workforce-one'), (int) $r['places'])); ?><?php endif; ?></small></td>
        <td class="ews-sm-orig"><?php echo $chips((string) $r['original'], (array) $r['labels']); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by $chips ?><?php if ($r['context'] !== ''): ?> <em>(<?php echo esc_html((string) $r['context']); ?>)</em><?php endif; ?></td>
        <td dir="<?php echo esc_attr($dir); ?>"><?php echo $chips((string) $r['current'], (array) $r['labels']); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by $chips ?></td>
        <td><?php if ($r['plural']): ?><span class="ews-sm-badge"><?php esc_html_e('Not editable yet', 'workforce-one'); ?></span><?php elseif ($r['edited']): ?><span class="ews-sm-badge ed" data-sm-edited><?php esc_html_e('Edited', 'workforce-one'); ?></span><?php else: ?><span class="ews-sm-badge"><?php esc_html_e('Default', 'workforce-one'); ?></span><?php endif; ?></td>
        <td><?php if (!$r['plural']): ?><a class="button button-small" href="<?php echo esc_url($url(['edit' => (string) $r['hash'], 'paged' => (int) $list['page']]) . '#ews-sm-edit'); ?>"><?php esc_html_e('Edit', 'workforce-one'); ?></a><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<div class="tablenav"><div class="tablenav-pages">
    <span class="displaying-num"><?php echo esc_html(sprintf(/* translators: %s: number of texts */ __('%s texts', 'workforce-one'), number_format_i18n((int) $list['total']))); ?></span>
    <?php if ($list['pages'] > 1): ?>
        <?php if ($list['page'] > 1): ?><a class="button" href="<?php echo esc_url($url(['paged' => (int) $list['page'] - 1])); ?>">‹</a><?php endif; ?>
        <span><?php echo esc_html(sprintf(/* translators: 1: page, 2: pages */ __('%1$d of %2$d', 'workforce-one'), (int) $list['page'], (int) $list['pages'])); ?></span>
        <?php if ($list['page'] < $list['pages']): ?><a class="button" href="<?php echo esc_url($url(['paged' => (int) $list['page'] + 1])); ?>">›</a><?php endif; ?>
    <?php endif; ?>
</div></div>

<p style="margin-top:18px">
<?php foreach (array_filter(['module' => (string) $filters['module'] !== '' ? __('Back to default: every edited text of this part', 'workforce-one') : '', 'all' => $lang === 'ar' ? __('Back to default: every edited Arabic text', 'workforce-one') : __('Back to default: every edited English text', 'workforce-one')]) as $scope => $label): ?>
<form method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline-block;margin-inline-end:14px" onsubmit="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Put these texts back to the default?', 'workforce-one'), JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG)); ?>);">
    <?php wp_nonce_field('ews_messages_reset'); ?><input type="hidden" name="action" value="ews_messages_reset"><input type="hidden" name="scope" value="<?php echo esc_attr($scope); ?>"><?php $hidden(); ?>
    <button class="button-link button-link-delete" data-sm-reset-<?php echo esc_attr($scope); ?>><?php echo esc_html($label); ?></button>
</form>
<?php endforeach; ?>
</p>

<?php if ($review): ?>
<div class="ews-sm-box" data-sm-review>
    <h2 style="margin-top:0"><?php esc_html_e('Need review after an update', 'workforce-one'); ?></h2>
    <p style="color:#50575e"><?php esc_html_e('The original of these texts changed in an update of the plugin, so your text no longer applies and the new default shows. Search for the new text above and edit it again, then remove the old one here.', 'workforce-one'); ?></p>
    <?php foreach ($review as $r): ?>
    <div class="ews-sm-review" data-sm-review-row="<?php echo esc_attr($r['hash']); ?>">
        <div class="ews-sm-orig"><?php echo esc_html($r['original']); ?></div>
        <div dir="<?php echo $r['lang'] === 'ar' ? 'rtl' : 'ltr'; ?>"><strong><?php echo $r['lang'] === 'ar' ? 'العربية' : 'English'; ?>:</strong> <?php echo esc_html($r['text']); ?></div>
        <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-top:6px">
            <?php wp_nonce_field('ews_messages_reset'); ?><input type="hidden" name="action" value="ews_messages_reset"><input type="hidden" name="scope" value="one_review"><?php $hidden(); ?>
            <input type="hidden" name="hash" value="<?php echo esc_attr($r['hash']); ?>"><input type="hidden" name="review_lang" value="<?php echo esc_attr($r['lang']); ?>">
            <button class="button button-small"><?php esc_html_e('Remove it', 'workforce-one'); ?></button>
        </form>
    </div>
    <?php endforeach; ?>
    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_messages_reset'); ?><input type="hidden" name="action" value="ews_messages_reset"><input type="hidden" name="scope" value="review"><?php $hidden(); ?>
        <button class="button-link button-link-delete"><?php esc_html_e('Remove them all', 'workforce-one'); ?></button></form>
</div>
<?php endif; ?>
</div>
