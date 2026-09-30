# End-to-end tests

`e2e_presence_face.py` drives a real WordPress install over HTTP and checks the
Face Sign In / QR Kiosk security fixes (19 checks: kiosk access, QR expiry and
tampering, forwarded-QR geofence, face token, enrollment lock).

## Run

```bash
# 1. A throwaway WordPress site at http://127.0.0.1:8080 with the plugin active
#    (any DB; SQLite via the sqlite-database-integration plugin works).
# 2. Seed test data (creates user emp1/emp1pass, an employee, today's Office
#    schedule, a location in Cairo and one kiosk). WARNING: wipes Workforce One
#    employees, schedule, time logs, locations and kiosks.
S=/tmp/wf-e2e wp eval-file tests/e2e_setup.php     # writes $S/ids.json
# 3. Run
python3 tests/e2e_presence_face.py /tmp/wf-e2e
```

Do not run against a production site.
