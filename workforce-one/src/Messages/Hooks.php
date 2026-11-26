<?php
namespace WorkforceOne\Messages;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of the System messages module (3.31.165). */
final class Hooks
{
    /** The page's two actions; they refuse while the feature is off. @param object $plugin */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_messages_save', [$plugin, 'system_messages_save']);
        add_action('admin_post_ews_messages_reset', [$plugin, 'system_messages_reset']);
    }

    /** The filters that show the edited texts: only the workforce-one domain reaches the gettext ones. */
    public static function filters(MessageFilter $filter): void
    {
        add_filter('gettext_workforce-one', [$filter, 'gettext'], 10, 3);
        add_filter('gettext_with_context_workforce-one', [$filter, 'gettextWithContext'], 10, 4);
        add_filter('load_script_translations', [$filter, 'scriptTranslations'], 10, 4);
        add_filter('pre_load_script_translations', [$filter, 'preScriptTranslations'], 10, 4);
        add_action('change_locale', [$filter, 'forgetLocale']);
        add_action('set_current_user', [$filter, 'forgetLocale']);
    }
}
