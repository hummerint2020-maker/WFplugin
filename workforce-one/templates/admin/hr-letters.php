<?php
/**
 * wp-admin → HR letters (HL3; mockups docs/mockups/hr-letter/admin-list, admin-types, admin-letterhead, admin-new, admin-ready).
 * Styles: assets/css/admin-hr-letters.css; script: assets/js/admin-hr-letters.js (the placeholder chips).
 *
 * @var string $tab list | types | head | new
 * @var bool $on
 * @var array{type:string,text:string}|null $notice
 * @var list<array{l:object,ref:string,title:string,file_url:string,can:array<string,bool>}> $rows
 * @var int $total
 * @var int $paged
 * @var string $status
 * @var string $search
 * @var array<string,int> $counts
 * @var list<array<string,mixed>> $types
 * @var string $edit
 * @var array<string,mixed> $settings
 * @var array<string,string> $head
 * @var list<string> $placeholders
 * @var bool $can_payroll
 * @var list<array{id:int,name:string,active:bool}> $employees
 * @var object|null $me
 * @var string $post_url
 * @var string $page_url
 * @var callable $image_url
 * @var string $features_url
 * @var string $workflow_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\HrLetters\LetterSettings;
use WorkforceOne\HrLetters\LetterTemplate;

$tabs = ['list' => __('Letters', 'workforce-one'), 'types' => __('Types & templates', 'workforce-one'), 'head' => __('Letterhead', 'workforce-one')];
$delivery = ['pdf' => __('Automatic PDF', 'workforce-one'), 'upload' => __('HR uploads the file', 'workforce-one'), 'none' => __('Request only', 'workforce-one')];
$states = ['pending' => __('Waiting for a decision', 'workforce-one'), 'approved' => __('Approved', 'workforce-one'), 'issued' => __('Issued', 'workforce-one'), 'ready' => __('Ready to collect', 'workforce-one'),
    'rejected' => __('Rejected', 'workforce-one'), 'withdrawn' => __('Withdrawn', 'workforce-one'), 'cancelled' => __('Cancelled', 'workforce-one')];
$why = ['template_missing' => __('The type has no template in this language', 'workforce-one'), 'no_salary' => __('No pay rate is on file', 'workforce-one'), 'render' => __('The PDF could not be made', 'workforce-one'), 'write' => __('The file could not be saved', 'workforce-one')];
$lang_name = static function ($l) { return $l === 'ar' ? __('Arabic', 'workforce-one') : __('English', 'workforce-one'); };
?>
<div class="wrap ews-hl-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('HR letters', 'workforce-one'); ?></h1>
    <?php if ($on): ?><a href="<?php echo esc_url(add_query_arg('tab', 'new', $page_url)); ?>" class="page-title-action"><?php esc_html_e('Letter for an employee', 'workforce-one'); ?></a><?php endif; ?>
    <hr class="wp-header-end">
    <?php if (!$on): ?><div class="notice notice-warning inline"><p><?php esc_html_e('HR letters are off: employees cannot ask, and nothing is decided. The letters already issued can still be downloaded here.', 'workforce-one'); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Features', 'workforce-one'); ?></a></p></div><?php endif; ?>
    <?php if ($notice): ?><div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['text']); ?></p></div><?php endif; ?>
    <?php if ($tab !== 'new'): ?>
    <nav class="nav-tab-wrapper"><?php foreach ($tabs as $k => $label): ?><a href="<?php echo esc_url(add_query_arg('tab', $k, $page_url)); ?>" class="nav-tab<?php echo $tab === $k ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav>
    <?php endif; ?>

<?php if ($tab === 'list'): ?>
    <ul class="subsubsub">
        <?php $filters = ['' => [__('All', 'workforce-one'), array_sum(array_diff_key($counts, ['waiting_file' => 1]))], 'pending' => [__('Waiting for a decision', 'workforce-one'), $counts['pending'] ?? 0],
            'waiting_file' => [__('Waiting for a file', 'workforce-one'), $counts['waiting_file'] ?? 0], 'approved' => [__('Approved', 'workforce-one'), $counts['approved'] ?? 0], 'issued' => [__('Issued', 'workforce-one'), $counts['issued'] ?? 0]];
        $i = 0; foreach ($filters as $k => [$label, $n]): ?><li><?php echo $i++ ? ' | ' : ''; ?><a href="<?php echo esc_url(add_query_arg(['tab' => 'list', 'status' => $k], $page_url)); ?>"<?php echo $status === $k ? ' class="current"' : ''; ?>><?php echo esc_html($label); ?> <span class="count">(<?php echo (int) $n; ?>)</span></a></li><?php endforeach; ?>
    </ul>
    <form method="get" class="search-box"><input type="hidden" name="page" value="ews31-hr-letters"><input type="hidden" name="tab" value="list"><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>"><input type="search" name="s" value="<?php echo esc_attr($search); ?>"> <button class="button"><?php esc_html_e('Search', 'workforce-one'); ?></button></form>
    <br class="clear">
    <table class="wp-list-table widefat striped">
        <thead><tr><th><?php esc_html_e('Ref', 'workforce-one'); ?></th><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Letter', 'workforce-one'); ?></th><th><?php esc_html_e('Status', 'workforce-one'); ?></th><th><?php esc_html_e('Action', 'workforce-one'); ?></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5"><?php esc_html_e('No letters yet.', 'workforce-one'); ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $l = $r['l']; $id = (int) $l->id; $nonce = wp_create_nonce('ews31_hr_letter_action_' . $id); ?>
            <tr>
                <td><strong><?php echo esc_html($r['ref']); ?></strong><br><small><?php echo esc_html(date_i18n('j M Y', strtotime((string) $l->requested_at))); ?></small></td>
                <td><?php echo esc_html((string) $l->employee_name . ((int) $l->employee_active === 1 ? '' : ' (' . __('former', 'workforce-one') . ')')); ?></td>
                <td><?php echo esc_html($r['title'] . ' · ' . $lang_name((string) $l->language)); ?><br><small><?php echo esc_html(sprintf(/* translators: %s: addressee */ __('To %s', 'workforce-one'), (string) $l->addressee)); ?><?php echo (int) $l->with_salary ? ' · ' . esc_html__('shows the salary', 'workforce-one') : ''; ?><?php echo (string) $l->source === 'hr' ? ' · ' . esc_html__('made by HR', 'workforce-one') : ''; ?></small>
                    <?php if (trim((string) $l->note) !== ''): ?><br><small><?php echo esc_html(sprintf(/* translators: %s: the employee's note */ __('Note: %s', 'workforce-one'), (string) $l->note)); ?></small><?php endif; ?></td>
                <td><?php echo esc_html(($states[(string) $l->status] ?? (string) $l->status) . ((string) $l->status === 'approved' ? ' · ' . ($delivery[(string) $l->delivery] ?? '') : '') . ((string) $l->status === 'issued' ? ' · ' . ((string) $l->file_source === 'uploaded' ? __('uploaded', 'workforce-one') : __('generated', 'workforce-one')) : '')); ?>
                    <?php if ((string) $l->issue_error !== '' && (string) $l->status === 'approved'): ?><br><span class="ews-hl-err"><?php echo esc_html($why[(string) $l->issue_error] ?? (string) $l->issue_error); ?></span><?php endif; ?>
                    <?php if (in_array((string) $l->status, ['ready', 'cancelled', 'rejected'], true) && trim((string) $l->decision_note) !== ''): ?><br><small><?php echo esc_html((string) $l->decision_note); ?></small><?php endif; ?>
                    <?php if ((string) $l->status === 'issued' && $l->downloaded_at): ?><br><small class="ews-hl-ok"><?php esc_html_e('Downloaded by the employee', 'workforce-one'); ?></small><?php endif; ?></td>
                <td class="ews-hl-actions">
                    <?php if ($r['file_url'] !== ''): ?><a href="<?php echo esc_url($r['file_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Download', 'workforce-one'); ?></a><br><?php endif; ?>
                    <?php if ($on && $r['can']['regenerate']): ?><form method="post" action="<?php echo esc_url($post_url); ?>"><input type="hidden" name="action" value="ews31_hr_letter_action"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>"><input type="hidden" name="letter_id" value="<?php echo $id; ?>"><input type="hidden" name="do" value="regenerate"><button class="button-link"><?php esc_html_e('Regenerate', 'workforce-one'); ?></button></form><br><?php endif; ?>
                    <?php if ($r['can']['upload']): ?><details><summary class="button-link"><?php echo (string) $l->status === 'issued' ? esc_html__('Replace', 'workforce-one') : esc_html__('Upload file', 'workforce-one'); ?></summary>
                        <form method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data"><input type="hidden" name="action" value="ews31_hr_letter_action"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>"><input type="hidden" name="letter_id" value="<?php echo $id; ?>"><input type="hidden" name="do" value="upload">
                            <input type="file" name="letter_file" accept="application/pdf" required> <button class="button button-primary"><?php esc_html_e('Upload and tell the employee', 'workforce-one'); ?></button><p class="description"><?php esc_html_e('PDF only, up to 10 MB. Private: only the employee and HR can open it.', 'workforce-one'); ?></p></form></details><br><?php endif; ?>
                    <?php if ($on && $r['can']['ready']): ?><details><summary class="button-link"><?php esc_html_e('Ready to collect', 'workforce-one'); ?></summary>
                        <form method="post" action="<?php echo esc_url($post_url); ?>"><input type="hidden" name="action" value="ews31_hr_letter_action"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>"><input type="hidden" name="letter_id" value="<?php echo $id; ?>"><input type="hidden" name="do" value="ready">
                            <label><?php esc_html_e('Where to collect it (optional, the employee sees it)', 'workforce-one'); ?><br><input type="text" name="where" class="regular-text" maxlength="190"></label><p><button class="button button-primary"><?php esc_html_e('Ready, tell the employee', 'workforce-one'); ?></button></p></form></details><br><?php endif; ?>
                    <?php if ($on && $r['can']['cancel']): ?><details><summary class="button-link"><?php esc_html_e('Cancel', 'workforce-one'); ?></summary>
                        <form method="post" action="<?php echo esc_url($post_url); ?>"><input type="hidden" name="action" value="ews31_hr_letter_action"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>"><input type="hidden" name="letter_id" value="<?php echo $id; ?>"><input type="hidden" name="do" value="cancel">
                            <label><?php esc_html_e('Reason (required, the employee sees it)', 'workforce-one'); ?><br><textarea name="reason" rows="2" class="large-text" required maxlength="500"></textarea></label><p><button class="button button-primary"><?php esc_html_e('Cancel the letter, tell the employee', 'workforce-one'); ?></button></p><p class="description"><?php esc_html_e('Only while no file is attached. The cancel is in the Audit Log.', 'workforce-one'); ?></p></form></details><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($total > 30): $pages = (int) ceil($total / 30); ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post((string) paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages])); ?></div></div><?php endif; ?>

<?php elseif ($tab === 'types'): $cur = $edit !== '' ? LetterSettings::type($types, $edit) : null; ?>
    <table class="wp-list-table widefat striped">
        <thead><tr><th><?php esc_html_e('Type', 'workforce-one'); ?></th><th><?php esc_html_e('Delivery', 'workforce-one'); ?></th><th><?php esc_html_e('Templates', 'workforce-one'); ?></th><th><?php esc_html_e('Status', 'workforce-one'); ?></th><th></th></tr></thead>
        <tbody><?php foreach ($types as $t): ?><tr><td><strong><?php echo esc_html(LetterSettings::name($t, 'ar')); ?></strong> · <?php echo esc_html(LetterSettings::name($t, 'en')); ?></td><td><?php echo esc_html($delivery[$t['delivery']] ?? ''); ?></td>
            <td><?php echo esc_html(implode(' · ', array_filter([trim((string) $t['tpl_ar']) !== '' ? __('Arabic', 'workforce-one') : '', trim((string) $t['tpl_en']) !== '' ? __('English', 'workforce-one') : ''])) ?: '—'); ?><?php echo LetterTemplate::usesSalary((string) $t['tpl_ar'] . (string) $t['tpl_en']) ? ' · ' . esc_html__('shows the salary', 'workforce-one') : ''; ?></td>
            <td><?php echo (int) $t['active'] ? esc_html__('Active', 'workforce-one') : esc_html__('Off', 'workforce-one'); ?></td><td><a href="<?php echo esc_url(add_query_arg(['tab' => 'types', 'type' => $t['key']], $page_url)); ?>"><?php esc_html_e('Edit', 'workforce-one'); ?></a></td></tr><?php endforeach; ?></tbody>
    </table>
    <p class="description"><?php esc_html_e('"Other" always goes to HR to upload. Types are never deleted, only switched off.', 'workforce-one'); ?> <a href="<?php echo esc_url(add_query_arg(['tab' => 'types', 'type' => 'new'], $page_url)); ?>"><?php esc_html_e('Add a type', 'workforce-one'); ?></a></p>
    <?php if ($edit !== ''): $t = $cur ?: ['key' => '', 'name_ar' => '', 'name_en' => '', 'delivery' => $settings['new_type_delivery'], 'active' => 1, 'tpl_ar' => '', 'tpl_en' => '']; ?>
    <div class="ews-hl-box">
        <h2><?php echo esc_html($cur ? sprintf(/* translators: %s: letter type */ __('Edit: %s', 'workforce-one'), LetterSettings::name($t, 'en')) : __('A new type', 'workforce-one')); ?></h2>
        <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews31_hr_letter_types_save'); ?><input type="hidden" name="action" value="ews31_hr_letter_types_save"><input type="hidden" name="key" value="<?php echo esc_attr($t['key']); ?>">
        <table class="form-table">
            <tr><th><?php esc_html_e('Name (Arabic · English)', 'workforce-one'); ?></th><td><input type="text" name="name_ar" dir="rtl" class="regular-text" value="<?php echo esc_attr($t['name_ar']); ?>"> <input type="text" name="name_en" class="regular-text" value="<?php echo esc_attr($t['name_en']); ?>"></td></tr>
            <tr><th><?php esc_html_e('Delivery', 'workforce-one'); ?></th><td><select name="delivery"><?php foreach ($delivery as $k => $label): ?><option value="<?php echo esc_attr($k); ?>"<?php selected($t['delivery'], $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select> <label><input type="checkbox" name="active" value="1"<?php checked((int) $t['active'], 1); ?>> <?php esc_html_e('Active', 'workforce-one'); ?></label></td></tr>
            <?php foreach (['ar' => __('Arabic template', 'workforce-one'), 'en' => __('English template', 'workforce-one')] as $l => $label): ?>
            <tr><th><?php echo esc_html($label); ?></th><td>
                <div class="ews-hl-chips"><?php foreach ($placeholders as $p): ?><button type="button" class="button button-small" data-hl-chip="{<?php echo esc_attr($p); ?>}">{<?php echo esc_html($p); ?>}</button><?php endforeach; ?><button type="button" class="button button-small" data-hl-chip="{g:|}">{g:…|…}</button></div>
                <textarea name="tpl_<?php echo esc_attr($l); ?>" rows="8" class="large-text ews-hl-tpl"<?php echo $l === 'ar' ? ' dir="rtl"' : ''; ?> data-hl-tpl data-hl-known="<?php echo esc_attr(implode(',', $placeholders)); ?>"><?php echo esc_textarea((string) $t['tpl_' . $l]); ?></textarea>
                <p class="ews-hl-err" data-hl-bad="<?php esc_attr_e('Not in the list:', 'workforce-one'); ?>" hidden></p>
            </td></tr>
            <?php endforeach; ?>
        </table>
        <p class="description"><?php esc_html_e('A blank line starts a paragraph. Words that depend on the employee\'s gender: {g:male words|female words}. A template that shows the salary needs "Manage payroll" to save. Left empty, a PDF type is not offered in that language.', 'workforce-one'); ?></p>
        <p><button class="button button-primary"><?php esc_html_e('Save', 'workforce-one'); ?></button></p>
        </form>
        <?php if ($cur): ?>
        <form method="post" action="<?php echo esc_url($post_url); ?>" target="_blank"><?php wp_nonce_field('ews31_hr_letter_preview'); ?><input type="hidden" name="action" value="ews31_hr_letter_preview"><input type="hidden" name="from_types" value="1"><input type="hidden" name="type" value="<?php echo esc_attr($t['key']); ?>">
            <p><?php esc_html_e('Preview the saved template for an employee:', 'workforce-one'); ?> <select name="employee_id" required><?php foreach ($employees as $e): ?><option value="<?php echo (int) $e['id']; ?>"><?php echo esc_html($e['name']); ?></option><?php endforeach; ?></select>
            <select name="language"><option value="ar">عربي</option><option value="en">English</option></select> <button class="button"><?php esc_html_e('Preview', 'workforce-one'); ?></button></p>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php elseif ($tab === 'head'): ?>
    <div class="ews-hl-box">
        <form method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data"><?php wp_nonce_field('ews31_hr_letter_letterhead_save'); ?><input type="hidden" name="action" value="ews31_hr_letter_letterhead_save">
        <table class="form-table">
            <?php foreach (['company' => __('Company name', 'workforce-one'), 'address' => __('Address', 'workforce-one'), 'signatory_name' => __('Signatory', 'workforce-one'), 'signatory_title' => __('Signatory\'s title', 'workforce-one'), 'footer' => __('Footer', 'workforce-one')] as $f => $label): ?>
            <tr><th><?php echo esc_html($label); ?></th><td><input type="text" name="<?php echo esc_attr($f); ?>_ar" dir="rtl" class="regular-text" value="<?php echo esc_attr($head[$f . '_ar']); ?>" placeholder="عربي"> <input type="text" name="<?php echo esc_attr($f); ?>_en" class="regular-text" value="<?php echo esc_attr($head[$f . '_en']); ?>" placeholder="English"></td></tr>
            <?php endforeach; ?>
            <tr><th><?php esc_html_e('Images', 'workforce-one'); ?></th><td class="ews-hl-imgs">
                <?php foreach (['logo' => __('Logo', 'workforce-one'), 'signature' => __('Signature', 'workforce-one'), 'stamp' => __('Stamp', 'workforce-one')] as $img => $label): ?>
                <div class="ews-hl-img"><span><?php echo esc_html($label); ?></span><?php if ($head[$img . '_key'] !== ''): ?><img src="<?php echo esc_url($image_url($img)); ?>" alt=""><label><input type="checkbox" name="remove_<?php echo esc_attr($img); ?>" value="1"> <?php esc_html_e('Remove', 'workforce-one'); ?></label><?php endif; ?><input type="file" name="<?php echo esc_attr($img); ?>" accept="image/png,image/jpeg"></div>
                <?php endforeach; ?>
                <p class="description"><?php esc_html_e('JPEG or PNG up to 1 MB. The signature and stamp are kept private, never in the Media Library, and cannot be opened from a link.', 'workforce-one'); ?></p></td></tr>
            <tr><th><?php esc_html_e('Font', 'workforce-one'); ?></th><td><p><?php esc_html_e('Amiri (formal Arabic), bundled with the plugin', 'workforce-one'); ?></p></td></tr>
        </table>
        <p><button class="button button-primary"><?php esc_html_e('Save', 'workforce-one'); ?></button></p>
        </form>
    </div>

<?php else: /* new: Letter for an employee */ ?>
    <div class="ews-hl-box">
        <h2><?php esc_html_e('Letter for an employee', 'workforce-one'); ?></h2>
        <p><?php esc_html_e('No request and no approval: the letter is issued at once and shows in the employee\'s My requests. It is in the Audit Log.', 'workforce-one'); ?></p>
        <form method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data"><?php wp_nonce_field('ews31_hr_letter_create'); ?><input type="hidden" name="action" value="ews31_hr_letter_create">
        <table class="form-table">
            <tr><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><td><select name="employee_id" required><option value=""><?php esc_html_e('— Pick —', 'workforce-one'); ?></option><?php foreach ($employees as $e): if ($me && (int) $me->id === $e['id']) continue; ?><option value="<?php echo (int) $e['id']; ?>"><?php echo esc_html($e['name'] . ($e['active'] ? '' : ' (' . __('former', 'workforce-one') . ')')); ?></option><?php endforeach; ?></select>
                <p class="description"><?php esc_html_e('You cannot make a letter for yourself here: ask in the app and the administrators decide.', 'workforce-one'); ?></p></td></tr>
            <tr><th><?php esc_html_e('Type', 'workforce-one'); ?></th><td><select name="type"><?php foreach ($types as $t): ?><option value="<?php echo esc_attr($t['key']); ?>"><?php echo esc_html(LetterSettings::name($t, 'ar') . ' · ' . LetterSettings::name($t, 'en')); ?></option><?php endforeach; ?><option value="other"><?php esc_html_e('Other', 'workforce-one'); ?></option></select>
                <input type="text" name="other_text" class="regular-text" placeholder="<?php esc_attr_e('For "Other": which letter', 'workforce-one'); ?>"></td></tr>
            <tr><th><?php esc_html_e('Language', 'workforce-one'); ?></th><td><?php foreach ($settings['languages'] as $i => $l): ?><label><input type="radio" name="language" value="<?php echo esc_attr($l); ?>"<?php checked($i, 0); ?>> <?php echo $l === 'ar' ? 'عربي' : 'English'; ?></label> <?php endforeach; ?></td></tr>
            <tr><th><?php esc_html_e('Addressed to', 'workforce-one'); ?></th><td><input type="text" name="addressee" class="regular-text" placeholder="<?php echo esc_attr($settings['addressee_ar'] . ' / ' . $settings['addressee_en']); ?>"></td></tr>
            <tr><th><?php esc_html_e('The letter', 'workforce-one'); ?></th><td><label><input type="radio" name="make" value="template" checked> <?php esc_html_e('Made from the type\'s template (PDF)', 'workforce-one'); ?></label><br><label><input type="radio" name="make" value="upload"> <?php esc_html_e('I upload a PDF', 'workforce-one'); ?></label> <input type="file" name="letter_file" accept="application/pdf"></td></tr>
        </table>
        <?php if (!$can_payroll): ?><div class="notice notice-info inline"><p><?php esc_html_e('A letter that shows the salary also needs "Manage payroll" to preview and issue.', 'workforce-one'); ?></p></div><?php endif; ?>
        <p><button class="button" name="preview" value="1" formtarget="_blank"><?php esc_html_e('Preview', 'workforce-one'); ?></button>
            <button class="button button-primary"><?php esc_html_e('Issue the letter', 'workforce-one'); ?></button></p>
        </form>
    </div>
<?php endif; ?>
</div>
