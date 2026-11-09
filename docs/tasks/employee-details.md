# HL2: employee details for letters (name in Arabic, gender, hiring date)

Part of the HR letter request (`docs/tasks/hr-letter.md` §16 and "Owner decisions on HL2"). Mockup: `docs/mockups/employee-fields/` (approved 2026-11-08).
Design by the read-only architect on branch `claude/hr-letters`; it is condensed here and was saved before any code.

## 1. Data model and upgrade path
- **New columns on `{prefix}ews_employees`**, all NULL, no index:
  - `name_ar VARCHAR(190)`
  - `gender VARCHAR(10)` (male / female)
  - `hire_date DATE`
- **New installs:** the columns are added to `base_tables()`.
- **Upgrades:** `ensure_employee_profile_schema()` (`includes/trait-schema.php`) adds them through its existing `$defs` loop.
- **Checks:** `SchemaCheck::COLUMNS['ews_employees']` lists the new columns, and `ews_schema_target()` is bumped to the release.
- Multisite needs nothing extra. Uninstall drops the whole table, as today.
- `created_at` is never written or read in a new way: `cx_started`, payroll and the reports keep using it.

## 2. Code (people.directory slice)
- **Pure rules — `src/Employees/EmployeeDetailsRules.php`:**
  - `clean($raw, $today)` returns values and errors:
    - Name: trimmed, at most 190 characters (multibyte), otherwise `too_long`.
    - Gender: male or female, otherwise `bad_gender`.
    - Hiring date: `Y-m-d` and a real calendar date, or an Excel serial; otherwise `bad_date`. A date later than today is `future_date`.
  - `since($hireDate, $createdAt)` gives the hiring date, or the date the employee was added.
  - `diff($row, $values)`.
  - Labels and error messages.
- **Import plan — `src/Employees/DetailsImportPlan.php`**, modelled on `Organization\ImportPlan`:
  - Headers found case- and space-insensitively: Employee ID or Domain name (one is required), plus at least one of `name_ar`, `gender`, `hire_date`.
  - At most 5000 rows. An empty cell means "leave it as it is" (owner).
  - If any field in a row is wrong, the whole row is refused.
  - Refusal codes: `employee_unknown`, `duplicate` (the first occurrence wins), `bad_gender`, `bad_date`, `future_date`, `too_long`.
  - Employee ID wins over Domain name. A value equal to the current one is not a change.
- **Store** (`PeopleStore` and `WordPressPeopleStore`):
  - `setDetails(id, fields, now)`: an UPDATE of only the given keys plus `updated_at`. Returns the rows changed, or `false`.
  - `detailsRows()`: id, name, domain_name, active, the three fields and created_at, ordered by name.
- **Service — `src/People/EmployeeDetailsService.php`** (store, audit, ports):
  - `save(id, values)`: re-read the employee, diff, write, clear the people memo through `ports->changed`, then one `employee_details` audit row listing old → new.
  - `apply(plan, file)`: per employee, apply only the fields whose current value still equals the preview's "now" (a concurrent edit is skipped and counted), with one audit row per employee and one summary row `employee_details_imported`.
  - Registered as `Services::employeeDetails()`.
- **`EmployeeService::create`:** adds the three fields only when they are filled, and audits them in the existing `employee_create` row. `update` is not touched, so the inline forms can never clear them.
- **Controllers — `includes/trait-employee-details.php`** (no SQL; listed in `modules.json`). Each handler has its nonce and the capability:
  - `ews31_employee_details_save` (from the profile);
  - `ews31_employee_details_export` (XLSX with a "How to import" sheet, or CSV with a BOM; hiring dates written as text);
  - `ews31_employee_details_import` (5 MB, xlsx or csv; the preview is kept in a 30-minute transient per user);
  - `ews31_employee_details_import_apply` (cancel, an expired preview, apply).
  - Screen: `admin.php?page=ews31-employees&view=details-import`, template `templates/admin/employee-details-import.php`.
- **Templates:**
  - `admin/employee-profile.php`: the Arabic name under the name, Employee Since through `since()`, a Gender row, and the Edit button with its form.
  - `admin/employees.php`: an optional section in Add Employee, and the "Update details from a file" button.
  - `app/my-profile.php`: Name with the Arabic name under it, Gender, Employee since, and the note.
  - Small `ews-ef-*` CSS. Arabic for every new string.
- **Personal data:** gender is never in the directory, the public profile, the organization chart or the API (those read fixed column lists).

## 3. Golden
- The pages change on purpose and are declared in `tests/golden/expected.json` with their exact options:
  - the Employees page entries;
  - the employee profile;
  - My Profile (emp1 and acomar).
- `hooks_added`: the four admin-post actions.
- The action traces are unchanged: the new columns are NULL on both sides, create writes them only when they are filled, and update never writes them.

## 4. Edge cases
- Archived employees: see Q4.
- An employee without a WordPress user: no My Profile, nothing else changes.
- Duplicate rows in a file: the first one is used, the others are refused.
- Domain names are matched case-insensitively.
- Clearing a value is done with the profile's Edit, not the file.
- The CSV reader already handles a BOM, `;` separators, Windows-1256 and a leading `'`. Excel date serials are converted. The existing size and zip guards apply.
- A stale preview: the changed fields are skipped and the notice says so.
- The profile save takes no lock: the last write wins, and both are audited.
- A hiring date before `created_at` is allowed.

## 5. Tests
- **Unit:**
  - `EmployeeDetailsRules`: multibyte lengths, gender, 30 February, Excel serials, future dates, `since()`.
  - `DetailsImportPlan`: missing columns, empty cells, duplicates, unknown employees, ID versus domain, unchanged values, row limit, BOM.
- **Storage contract:** `tests/e2e_people_store.php`.
- **New e2e — `tests/e2e_employee_details.py`:**
  - the schema;
  - Not set and the added date shown when nothing is filled;
  - who sees the Edit button;
  - save and audit; future date refused;
  - nonce and access refusals;
  - Add Employee with and without the details;
  - the update forms keep the details;
  - export (XLSX and CSV);
  - import (CSV with a BOM and Arabic, XLSX): preview with refused rows, Apply, audit per employee;
  - a stale preview, an expired preview, cancel;
  - `cx_started` and payroll unchanged.
- **Also run:** e2e employees, employee profile, my profile and org moves; golden; phpstan; phpcs; the module, layer and translation checks.

## 6. Risks
- Excel converts dates and IDs (mitigated: serials converted, text cells).
- The exact golden page options.
- Audit Log labels for the two new actions.
- Gender is personal data: keep it to HR and the employee.

## 7. Open questions for the owner (asked 2026-11-08)
Q1 who may edit · Q2 My Profile gets "Employee since" too (it is new there) · Q3 the sub-line under Employee Since in wp-admin ·
Q4 archived employees in the file · Q5 never shown to managers, the directory or the API · Q6 gender spellings the file accepts.

## Owner decisions (2026-11-08, "agreed to all")
- **Q1:** "Manage Employees" (`ews_manage_employees`) edits them, the same as every other employee edit. There is no new capability.
- **Q2:** My Profile shows Gender and "Employee since" for every employee, even when nothing is filled.
- **Q3:** in wp-admin, the line under Employee Since stays while the hiring date is not set.
- **Q4:** archived employees are in the file and can be updated (letters for former employees), marked as former.
- **Q5:** never shown to managers, the directory, the organization chart or the API.
- **Q6:** the file accepts male / female and ذكر / أنثى. Anything else is refused, with the reason in the preview.
