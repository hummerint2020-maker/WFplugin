<?php
/**
 * Test only (tests/e2e_api_refresh_race.py): copied to the test site's mu-plugins and removed by the test; never part of
 * the plugin. A request carrying X-E2E-Fail-Refresh: winner|grace|issue gets that one database statement of the refresh
 * broken (invalid SQL), as a lock timeout or a lost connection would, so the test can see that it never ends the session.
 */
if (!empty($_SERVER['HTTP_X_E2E_FAIL_REFRESH'])) {
    $ews_e2e_fail = (string) $_SERVER['HTTP_X_E2E_FAIL_REFRESH'];
    add_filter('query', function ($q) use ($ews_e2e_fail) {
        $match = [
            'winner' => '/^\s*UPDATE \S*ews_api_tokens SET used_at=.*used_at IS NULL/i',
            'grace'  => '/^\s*UPDATE \S*ews_api_tokens SET grace_uses=grace_uses\+1/i',
            'issue'  => '/^\s*INSERT INTO `?\S*ews_api_tokens/i',
        ];
        return isset($match[$ews_e2e_fail]) && preg_match($match[$ews_e2e_fail], $q) ? 'SELECT broken FROM no_such_table_e2e' : $q;
    });
}
