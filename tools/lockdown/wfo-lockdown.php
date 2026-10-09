<?php
/**
 * Plugin Name: Workforce One — hosted site lockdown
 * Description: Installed by tools/new_company.sh as a must-use plugin on every hosted company site.
 *              The company's own login gets the "Company Admin" role: every Workforce One page, users
 *              and settings, but no way to change code (plugins, themes, files) or to make itself or
 *              anyone a WordPress Administrator. Administrators (the operator) are unaffected.
 * Version: 1.0.0
 *
 * Must-use plugins load before normal plugins and cannot be switched off from wp-admin. The file is
 * owned by root on the server, so a site cannot change it either.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WFO_LOCKDOWN_VERSION = '1.0.0';
const WFO_COMPANY_ROLE = 'wfo_company_admin';

/**
 * Administrator capabilities the Company Admin does not get: anything that changes code, runs
 * unfiltered markup or uploads, imports content, or updates WordPress.
 */
function wfo_lockdown_denied_caps(): array
{
    return [
        'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins',
        'resume_plugins',
        'switch_themes', 'install_themes', 'update_themes', 'delete_themes', 'edit_themes',
        'resume_themes', 'edit_theme_options', 'customize',
        'edit_files', 'update_core', 'install_languages', 'update_languages',
        'unfiltered_html', 'unfiltered_upload', 'import', 'setup_network', 'manage_network',
    ];
}

/** Options only the operator may change (privilege escalation, site address, code paths). */
function wfo_lockdown_locked_options(): array
{
    global $wpdb;
    return [
        'default_role', 'users_can_register', 'siteurl', 'home', 'upload_path', 'upload_url_path',
        'template', 'stylesheet', 'active_plugins', 'recently_activated', $wpdb->prefix . 'user_roles',
    ];
}

/** The operator: a real WordPress Administrator, or WP-CLI on the server. */
function wfo_lockdown_is_operator(): bool
{
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }
    $user = wp_get_current_user();
    return $user && $user->exists() && in_array('administrator', (array) $user->roles, true);
}

/**
 * Keeps the Company Admin role in step with Administrator minus the denied capabilities, so the
 * Workforce One capabilities the plugin grants to Administrator (ews_*) are always included.
 * Runs when the lockdown version or Administrator's capability list changes.
 */
function wfo_lockdown_sync_role(): void
{
    $admin = get_role('administrator');
    if (!$admin) {
        return;
    }
    $caps = array_filter((array) $admin->capabilities);
    foreach (wfo_lockdown_denied_caps() as $cap) {
        unset($caps[$cap]);
    }
    $signature = WFO_LOCKDOWN_VERSION . '|' . md5(wp_json_encode(array_keys($caps)));
    if (get_option('wfo_lockdown_role_signature') === $signature && get_role(WFO_COMPANY_ROLE)) {
        return;
    }
    remove_role(WFO_COMPANY_ROLE);
    add_role(WFO_COMPANY_ROLE, 'Company Admin', $caps);
    update_option('wfo_lockdown_role_signature', $signature, false);
}
add_action('init', 'wfo_lockdown_sync_role', 99);

// Belt and braces: even if a denied capability reaches the role, it is never granted.
add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
    if ($user instanceof WP_User && in_array(WFO_COMPANY_ROLE, (array) $user->roles, true)
        && !in_array('administrator', (array) $user->roles, true)) {
        foreach (wfo_lockdown_denied_caps() as $cap) {
            $allcaps[$cap] = false;
        }
    }
    return $allcaps;
}, 99, 4);

// Nobody but the operator may touch an Administrator account or hand out the Administrator role.
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    if (wfo_lockdown_is_operator()) {
        return $caps;
    }
    if (in_array($cap, ['edit_user', 'delete_user', 'remove_user', 'promote_user'], true) && !empty($args[0])) {
        $target = get_userdata((int) $args[0]);
        if ($target && in_array('administrator', (array) $target->roles, true)) {
            return ['do_not_allow'];
        }
    }
    return $caps;
}, 99, 4);

add_filter('editable_roles', function ($roles) {
    if (!wfo_lockdown_is_operator()) {
        unset($roles['administrator']);
    }
    return $roles;
});

// Last line: any attempt to give the Administrator role by someone else is reverted.
add_action('set_user_role', function ($user_id, $role) {
    if ($role === 'administrator' && !wfo_lockdown_is_operator()) {
        $user = new WP_User((int) $user_id);
        $user->set_role(WFO_COMPANY_ROLE);
    }
}, 10, 2);
add_action('user_register', function ($user_id) {
    if (wfo_lockdown_is_operator()) {
        return;
    }
    $user = new WP_User((int) $user_id);
    if (in_array('administrator', (array) $user->roles, true)) {
        $user->set_role('subscriber');
    }
});

// Operator accounts are not listed to the company (they cannot edit them anyway).
add_action('pre_get_users', function ($query) {
    if (is_admin() && !wfo_lockdown_is_operator()) {
        $exclude = (array) $query->get('role__not_in');
        $exclude[] = 'administrator';
        $query->set('role__not_in', array_values(array_unique($exclude)));
    }
});

// Locked options keep their value unless the operator changes them.
foreach (wfo_lockdown_locked_options() as $wfo_option) {
    add_filter('pre_update_option_' . $wfo_option, function ($value, $old_value) {
        return wfo_lockdown_is_operator() ? $value : $old_value;
    }, 99, 2);
}
unset($wfo_option);

// Registration stays off and new users never default to Administrator, whatever the database says.
add_filter('option_users_can_register', function ($value) {
    return wfo_lockdown_is_operator() ? $value : 0;
});
add_filter('option_default_role', function ($value) {
    return $value === 'administrator' ? 'subscriber' : $value;
});

// Menus the Company Admin cannot use anyway are hidden; their screens stay blocked by capability.
add_action('admin_menu', function () {
    if (wfo_lockdown_is_operator()) {
        return;
    }
    remove_menu_page('plugins.php');
    remove_menu_page('themes.php');
    remove_menu_page('tools.php');
    remove_submenu_page('index.php', 'update-core.php');
}, 999);

add_action('admin_init', function () {
    if (wfo_lockdown_is_operator() || wp_doing_ajax()) {
        return;
    }
    global $pagenow;
    $blocked = ['plugins.php', 'plugin-install.php', 'plugin-editor.php', 'themes.php', 'theme-install.php',
        'theme-editor.php', 'customize.php', 'update-core.php', 'update.php', 'tools.php', 'import.php',
        'site-health.php'];
    if (in_array($pagenow, $blocked, true)) {
        wp_die(esc_html__('This page is managed by Workforce One.', 'default'), '', ['response' => 403]);
    }
});
