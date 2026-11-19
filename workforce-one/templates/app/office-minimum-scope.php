<?php
/**
 * Office minimum by department (3.31.154, docs/tasks/office-minimum-departments.md): above the day counters, whose minimum the
 * page shows (a department with its own settings, or the whole company) and, for an administrator, the choice of department.
 * Printed only when some department has its own settings. Styles: assets/css/app-attendance.css (.wfo-omd-*).
 *
 * @var array<string,mixed> $office_min  om_attendance(), with 'scope' => [dept, name, min, mgr_min, choices, keep]
 */
if (!defined('ABSPATH')) exit;
$omd = $office_min['scope'];
$omd_name = $omd['dept'] ? sprintf(/* translators: %s: department */ __('%s department', 'workforce-one'), $omd['name']) : __('The whole company', 'workforce-one');
$omd_what = sprintf(/* translators: %d: people in the office each day */ _n('%d in the office', '%d in the office', (int) $omd['min'], 'workforce-one'), (int) $omd['min']);
if ($omd['mgr_min'] !== null) $omd_what .= ' · ' . sprintf(/* translators: %d: managers in the office each day */ _n('%d manager', '%d managers', (int) $omd['mgr_min'], 'workforce-one'), (int) $omd['mgr_min']);
?><div class="wfo-omd-bar" data-omd-scope="<?php echo (int) $omd['dept']; ?>"><?php echo \WorkforceOne\Ui\Icons::svg('office', 16, 2.2); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><span class="wfo-omd-what"><b><?php echo esc_html($omd_name); ?></b> · <?php echo esc_html($omd_what); ?></span>
<?php if ($omd['choices']): ?><form method="get" class="wfo-omd-pick"><?php foreach ($omd['keep'] as $k => $v): if ($k === 'om_dept') continue; ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?><?php foreach (['q', 'team'] as $k): if (isset($_GET[$k]) && is_scalar($_GET[$k]) && $_GET[$k] !== ''): /* the search and team filter stay (review 7) */ ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr(sanitize_text_field(wp_unslash((string) $_GET[$k]))); ?>"><?php endif; endforeach; ?>
<label><?php esc_html_e('Minimum for', 'workforce-one'); ?> <select name="om_dept" data-omd-pick><?php foreach ($omd['choices'] as $id => $label): ?><option value="<?php echo (int) $id; ?>"<?php selected((int) $omd['dept'], (int) $id); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
<noscript><button type="submit" class="ews-btn secondary"><?php esc_html_e('Show', 'workforce-one'); ?></button></noscript></form><?php endif; ?></div>
