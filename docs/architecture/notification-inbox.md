# Notifications inbox: first extracted application boundary

The existing web, REST and module callers keep their legacy entry points. The
Notifications trait now delegates inbox persistence and read-state use cases:

```
Web / REST / other modules
  -> existing Notifications entry points (policy, sanitisation, auth, nonce, URL)
    -> Notifications\Inbox (use cases + request-local unread cache)
      -> Notifications\InboxStore (storage contract)
        -> Notifications\WordPressInboxStore (wpdb + ews_notifications)
```

`Inbox` needs only an `InboxStore`; it neither receives `EWS_Manager_V31_1` nor
calls WordPress. The WordPress store owns inbox SQL. `InboxSchema` owns the
unchanged table definition, invoked by the existing schema coordinator. No new
tables, options, migration version, REST contract or business rules are added.

The compatibility adapter creates one inbox and schema helper per table prefix
per manager/request. Repeated calls share the unread-count cache, including zero
counts. A site-prefix switch uses another store/cache, and returning to the old
site reuses its instance. Mutations invalidate the affected recipient. Existing
explicit Mark read versus unread-only landing timestamp semantics are retained.

## Scope and remaining work

This is an extraction of the in-app inbox, not the whole Notifications module.
Policy/settings, URL authorisation, rendering and Push delivery still live behind
the legacy trait. Other modules still call the legacy API. Follow-up slices can
move policy and dispatch behind explicit contracts, then adapt consumers without
passing the shared plugin object into the module. Cross-module database access
elsewhere in the plugin remains tracked by `check_modules.py`.

## Verification

- `NotificationInboxTest`: pure use cases, cache invalidation, timestamp semantics,
  recipient ownership, list bounds and separate store instances.
- `e2e_notification_inbox.php`: real WordPress/MySQL adapter, ownership predicates,
  ordering/filtering, read times, saved links, retention and prefix-switch reuse.
  Uses temporary tables; invoked in the disposable CI WordPress installation.
- Existing notification view/link/Push end-to-end tests exercise the unchanged
  legacy entry points and business policies.
- `check_notification_inbox_boundary.py` adds a narrow boundary guard for this
  slice. The legacy trait ratchet does not inspect namespaced classes, so this
  guard runs alongside it. It is a structural check, not a complete PHP analyser.

No production site is needed for these checks.
