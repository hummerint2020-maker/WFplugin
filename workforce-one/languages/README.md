# Translations

- `workforce-one.pot` – template, regenerate after changing strings:
  `wp i18n make-pot . languages/workforce-one.pot --domain=workforce-one --exclude=assets/vendor,tests --skip-js`
- `workforce-one-ar.po` – Arabic. After editing run `wp i18n make-mo languages/workforce-one-ar.po languages/`
  and `wp i18n make-php languages/workforce-one-ar.po languages/`.

Coverage so far: the Sign In / Sign Out page (incl. breaks, QR, Face Sign In UI and
its JavaScript), Sign In messages, Face Sign In API errors, QR Sign-In,
Presence Verification, the Kiosk display and Privacy & Data Retention. The rest of
the UI still uses hard-coded English strings and is being converted module by module.

JavaScript strings (`assets/js/workforce-one.js`) use `wp.i18n`; after updating the
.po also run `wp i18n make-json languages/workforce-one-ar.po languages/ --no-purge`.
