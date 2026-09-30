# Changelog

## 3.29.0
### Security
- Face Sign In can no longer be bypassed by posting `face_verified=1`: a successful
  `/face/verify` issues a single-use, user-bound token that Sign In verifies server-side.
- Employees can no longer overwrite or delete their own face enrollment (bypassed the
  admin-approved reset workflow).
- QR Sign-In requires the employee's own GPS to be inside the kiosk's work location
  (a forwarded QR photo no longer works remotely); QR rotates every 15 s.

### Fixed
- Kiosk display always returned "Kiosk access denied".
- QR Sign-In always failed with "The link you followed has expired".
- Face module lost its REST base URL because of a broken HTML attribute.
- Plugin activated via WP-CLI never received the employee profile/department columns.
- Kiosk errors return 403 instead of 500.

### Performance
- No SHOW TABLES / SHOW COLUMNS / dbDelta on normal requests; schema work runs once
  per schema version (`ews_schema_target()`).
- Kiosk fetches its QR payload once per slot instead of twice per second.

### Privacy
- Optional retention period for GPS coordinates and IP address in attendance logs.
- Face templates of archived employees are deleted automatically (default on).
- `uninstall.php`: opt-in removal of all plugin data.
- face-api.js + models, jsQR and the QR generator are bundled; no third-party CDNs or
  QR APIs are contacted.

### i18n
- Text domain `workforce-one`, `.pot` template, Arabic translation for the attendance,
  face, QR, presence and privacy strings, automatic RTL stylesheets.
