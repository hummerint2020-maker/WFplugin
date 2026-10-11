<?php
/**
 * Employee app → Employees (managers, 3.31.69): the active employees with search, and Add employee.
 * Teams, schedule, pay and the rest are set in wp-admin → Employees. Styles:
 * assets/css/app-employees.css; script: assets/js/employees.js (search).
 *
 * @var object[] $rows      id, name, domain_name, email, teams (names), url (profile)
 * @var string $post_url
 * @var string $admin_url   wp-admin → Employees ('' = not shown)
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
use WorkforceOne\Employees\ProfileSummary;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup.
?>
<div class="ews-page wfo-emps">
    <section class="wfo-emps-list" aria-labelledby="wfo-emps-title">
        <div class="wfo-emps-head">
            <h3 id="wfo-emps-title"><?php esc_html_e('Employees', 'workforce-one'); ?> <span><?php /* translators: %d: number of active employees */ printf(esc_html(_n('%d active', '%d active', count($rows), 'workforce-one')), count($rows)); ?></span></h3>
            <label class="wfo-emps-search"><?php echo Icons::svg('search', 17, 2); ?><span class="screen-reader-text"><?php esc_html_e('Search name or email', 'workforce-one'); ?></span><input type="search" id="wfo-emps-search" placeholder="<?php esc_attr_e('Search name or email', 'workforce-one'); ?>"></label>
        </div>
        <div class="wfo-emps-cols" aria-hidden="true"><span><?php esc_html_e('Name', 'workforce-one'); ?></span><span><?php esc_html_e('Domain Name', 'workforce-one'); ?></span><span><?php esc_html_e('Email Address', 'workforce-one'); ?></span><span><?php esc_html_e('Team', 'workforce-one'); ?></span></div>
        <ul class="wfo-emps-rows">
            <?php foreach ($rows as $r): ?>
            <li data-ews-emp="<?php echo esc_attr(mb_strtolower($r->name . ' ' . $r->domain_name . ' ' . $r->email)); ?>"><a href="<?php echo esc_url($r->url); ?>">
                <span class="wfo-emps-who"><span class="wfo-emps-avatar tone-<?php echo (int) $r->id % 5; ?>" aria-hidden="true"><?php echo esc_html(ProfileSummary::initials((string) $r->name) ?: '·'); ?></span><b><?php echo esc_html($r->name); ?></b></span>
                <span class="wfo-emps-user"><?php echo esc_html($r->domain_name); ?></span>
                <span class="wfo-emps-mail"><?php echo esc_html((string) $r->email); ?></span>
                <span class="wfo-emps-team"><?php if ($r->teams): foreach ($r->teams as $tn): ?><span class="wfo-chip"><?php echo esc_html($tn); ?></span><?php endforeach; else: ?><span class="wfo-chip is-none"><?php esc_html_e('No Team', 'workforce-one'); ?></span><?php endif; ?></span>
                <span class="wfo-emps-go" aria-hidden="true"><?php echo Icons::svg('chevron', 16, 2.2); ?></span>
            </a></li>
            <?php endforeach; ?>
        </ul>
        <p class="wfo-emps-none" hidden><?php esc_html_e('No employee matches your search.', 'workforce-one'); ?></p>
    </section>

    <section class="wfo-emps-add" aria-labelledby="wfo-emps-add-title">
        <h3 id="wfo-emps-add-title"><?php esc_html_e('Add Employee', 'workforce-one'); ?></h3>
        <form method="post" action="<?php echo esc_url($post_url); ?>">
            <input type="hidden" name="action" value="ews31_employee_save"><?php wp_nonce_field('ews31_employee_save'); ?>
            <div class="wfo-emps-field"><label for="wfo-emp-name"><?php esc_html_e('Name', 'workforce-one'); ?></label><input id="wfo-emp-name" name="name" required autocomplete="off"></div>
            <div class="wfo-emps-field"><label for="wfo-emp-domain"><?php esc_html_e('Domain Name', 'workforce-one'); ?></label><input id="wfo-emp-domain" name="domain_name" required autocomplete="off"></div>
            <div class="wfo-emps-field"><label for="wfo-emp-email"><?php esc_html_e('Email Address', 'workforce-one'); ?></label><input id="wfo-emp-email" type="email" name="email" placeholder="employee@company.com" required autocomplete="off"></div>
            <button class="wfo-emps-submit" type="submit"><?php echo Icons::svg('plus', 18, 2.4); ?><?php esc_html_e('Add Employee', 'workforce-one'); ?></button>
        </form>
        <?php if ($admin_url !== ''): ?><p><?php esc_html_e('Teams, schedule, pay and access are set in wp-admin.', 'workforce-one'); ?> <a href="<?php echo esc_url($admin_url); ?>"><?php esc_html_e('Open Employees in wp-admin', 'workforce-one'); ?></a></p><?php endif; ?>
    </section>
</div>
