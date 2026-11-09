<?php
/**
 * wp-admin → Employees → Update details from a file (HR letters HL2): download the file, fill it, upload it; then the preview of what will
 * change and of every row that cannot be used, and Apply. Mockup: docs/mockups/employee-fields/ (import, import-preview).
 *
 * @var array{file:string,unchanged:int,employees:int,changes:list<array<string,string>>,problems:list<array<string,mixed>>}|null $preview
 * @var string $post_url
 * @var string $employees_url
 * @var string $export_url
 * @var string $error
 * @var string $notice
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap ews-ef-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Update details from a file', 'workforce-one'); ?></h1>
    <a href="<?php echo esc_url($employees_url); ?>" class="page-title-action"><?php esc_html_e('Back to Employees', 'workforce-one'); ?></a>
    <hr class="wp-header-end">
    <?php if ($error !== ''): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

    <?php if ($preview === null): ?>
    <div class="ews-ef-add">
        <h2><?php esc_html_e('Update name in Arabic, gender and hiring date from a file', 'workforce-one'); ?></h2>
        <ol class="ews-ef-steps">
            <li><?php esc_html_e('Download the file: every employee with their current details.', 'workforce-one'); ?></li>
            <li><?php esc_html_e('Fill the columns name_ar, gender (male or female) and hire_date (YYYY-MM-DD). Other columns are ignored.', 'workforce-one'); ?></li>
            <li><?php esc_html_e('Upload it here: you see the changes first; nothing is saved before you press Apply.', 'workforce-one'); ?></li>
        </ol>
        <p><a href="<?php echo esc_url($export_url); ?>" class="button"><?php esc_html_e('Download the employees file (Excel)', 'workforce-one'); ?></a></p>
        <form method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('ews31_employee_details_import'); ?><input type="hidden" name="action" value="ews31_employee_details_import">
            <p><input type="file" name="details_file" accept=".xlsx,.csv" required> <button class="button button-primary"><?php esc_html_e('Upload and preview', 'workforce-one'); ?></button></p>
        </form>
        <p class="description"><?php esc_html_e('Excel or CSV. An empty cell means "leave it as it is".', 'workforce-one'); ?></p>
    </div>
    <?php else: $n = count($preview['changes']); ?>
    <h2><?php esc_html_e('Preview the update', 'workforce-one'); ?> · <?php echo esc_html($preview['file']); ?></h2>
    <div class="notice notice-info inline"><p><?php
        echo esc_html(sprintf(/* translators: 1: changes, 2: employees, 3: refused rows, 4: employees without a change */ __('%1$d change(s) for %2$d employee(s) will be applied. %3$d row(s) are refused and will not be applied. %4$d employee(s) without a change.', 'workforce-one'),
            $n, (int) $preview['employees'], count($preview['problems']), (int) $preview['unchanged']));
    ?></p></div>
    <table class="widefat striped ews-ef-preview"><thead><tr><th><?php esc_html_e('Domain name', 'workforce-one'); ?></th><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Field', 'workforce-one'); ?></th><th><?php esc_html_e('Now', 'workforce-one'); ?></th><th><?php esc_html_e('New', 'workforce-one'); ?></th><th><?php esc_html_e('Result', 'workforce-one'); ?></th></tr></thead><tbody>
        <?php foreach ($preview['changes'] as $c): ?>
        <tr><td dir="ltr"><?php echo esc_html($c['domain']); ?></td><td><?php echo esc_html($c['name']); ?></td><td><?php echo esc_html($c['field']); ?></td><td dir="auto"><?php echo esc_html($c['from']); ?></td><td dir="auto"><?php echo esc_html($c['to']); ?></td><td><?php esc_html_e('Will change', 'workforce-one'); ?></td></tr>
        <?php endforeach; ?>
        <?php foreach ($preview['problems'] as $x): ?>
        <tr class="ews-ef-refused"><td dir="ltr"><?php echo esc_html($x['domain']); ?></td><td><?php echo esc_html($x['name']); ?></td><td colspan="2"><?php echo esc_html(sprintf(/* translators: %d: row number in the file */ __('Row %d', 'workforce-one'), (int) $x['row'])); ?></td><td dir="auto"><?php echo esc_html($x['value']); ?></td><td><?php echo esc_html($x['why']); ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$n && !$preview['problems']): ?><tr><td colspan="6"><?php esc_html_e('Nothing to change: the file has the same details as the employees.', 'workforce-one'); ?></td></tr><?php endif; ?>
    </tbody></table>
    <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-top:14px">
        <?php wp_nonce_field('ews31_employee_details_import_apply'); ?><input type="hidden" name="action" value="ews31_employee_details_import_apply">
        <?php if ($n): ?><button class="button button-primary"><?php echo esc_html(sprintf(/* translators: %d: number of changes */ __('Apply %d change(s)', 'workforce-one'), $n)); ?></button><?php endif; ?>
        <button class="button" name="cancel" value="1"><?php esc_html_e('Cancel', 'workforce-one'); ?></button>
    </form>
    <p class="description"><?php esc_html_e('Every change is kept in the Audit Log under your name.', 'workforce-one'); ?></p>
    <?php endif; ?>
</div>
