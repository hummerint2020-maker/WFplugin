# 3.31.111: bug round (design)

Source: `docs/tasks/bug-review-2026-10.md`, unticked items except Speed and Clean-up. Checked against 3.31.110
(9ed7a87): all still open. No schema change. One new wp-config constant: `WORKFORCE_ONE_TRUSTED_PROXIES` (off unless defined).

Owner decisions: (1) the deduction cap covers attendance only; unpaid leave is always deducted in full.
(2) Joining mid-month is prorated by the month's real calendar days.

## Items

1. **Cap** (`src/Payroll/PayCalculator.php` `month()` ~134): `attendance = absence+late+early`; `capped = min(attendance, cap)`;
   `deductions = capped + leave`; `deductions_before_cap = attendance`. Update the docblock.
2. **Mid-month** (`PayCalculator.php` ~63): `earned = round(monthly / days_in_month * paid_days, 2)`; add `prorated.rate`.
   The same branch serves the last working day of a leaver.
3. **Payslip arithmetic**: print `prorated.rate` in `PayslipPdf.php` ~104, `templates/admin/payroll.php` ~88, `templates/app/pay.php` ~70.
   Old snapshots without `rate` fall back to `monthly / day_divisor`. The cap row shows as a credit so the lines add up.
4. **Daily workers, today**: refuse (`date_today`) only while no sheet exists for today (`dw_admin_set_day_locked`); show today read-only in the Days tab.
5. **Self-approval outside workflows**: helper `approval_self_check($key, $requester)` in `trait-approvals.php`; call it in overtime, leave,
   early leave, the Requests Hub fallbacks and `dw_decide_change_locked`. Rule `allow` writes audit `approval_self_decision`.
6. **Corrections month-31**: use the month's last day in `trait-corrections.php` (~101, 773, 802, 828) and `trait-leave-view.php` ~139.
7. **WP 6.8 rehash**: `api_on_password_set` takes `$old_user_data`; same password or rehash does not revoke devices.
8. **Refresh race**: `Tokens::REFRESH_GRACE = 30`; a used token within the grace gets a new pair, the device stays.
9. **Login limit behind a proxy**: `src/Api/Auth/ClientIp.php`, trusted proxies from the constant, right-most untrusted hop.
10. **Per-account limit**: key by the resolved user id (login or email).
11. **API errors**: `ErrorMap::fromWpStatus` (400 VALIDATION_FAILED, 401 TOKEN_MISSING, 404 NOT_FOUND, else FORBIDDEN).
12. **App menu links**: build from a clean base when the view differs from the current one.
13. **Tasks "Show all"**: `src/Ui/OnceKeys.php` keeps `task_done`; mirrored in `assets/js/workforce-one.js`.
14. **Payout PDF**: validate `start`.
15. **Correction photo**: delete the file on every failure path of `cx_create`.

## Tests

One per item (unit: PayrollTest, PdfTest, ApiAuthTest, ApiFoundationTest, OnceKeysTest; e2e: payroll, money_safety, payroll_close,
daily_workers, self_approval, corrections, api_auth, layout). Also phpstan, phpcs, modules, translations and the related suites.

## Risks

Open months are recalculated on upgrade (closed snapshots stay). Refresh grace widens a stolen token's window to 30 s and one use.
A wrong trusted-proxy value lets clients spoof the address. With a non-`allow` rule an administrator cannot approve their own request.

## Open questions and the owner's answers

- Open months and reopened months: recalculated with the new rules on upgrade, no switch (CHANGELOG note).
- Leaving mid-month: same real-days proration as joining (recommended default, not contradicted).
- Self-approval outside workflows: an administrator is still allowed; the rule refuses ordinary managers deciding their own request.
- Early leave and Daily Workers (no workflow card): a new setting in each feature; the default keeps today's behaviour.
- Trusted proxy: a wp-config constant, off by default, X-Forwarded-For, used for app login only (the owner did not follow the
  question; explained in plain words, the recommended default stands).
- Daily Workers today: refused only until the site has saved its sheet (recommended default).
- Payslip cap row as a credit; prorated pay rounded from the rounded rate (recommended defaults).
- XLSX column "Attendance deductions": left unchanged in this release (changing the meaning of a column HR may use is a separate decision).
