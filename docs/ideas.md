# Ideas bank

Future features the owner wants to keep, not scheduled yet. When one is picked up, turn it into a
brief in `docs/tasks/` (like `docs/tasks/multisite.md`) and mark it here as **Scheduled**.

## Important: next up

| Idea | Status | Added |
|---|---|---|
| **Organization chart** → [`docs/tasks/org-chart.md`](tasks/org-chart.md), mockups [`docs/mockups/org-chart/`](mockups/org-chart/) | **Important, to build soon** (decisions taken, mockups ready) | 2026-10-10 |

## All ideas

| Idea | Status | Added |
|---|---|---|
| [Field visits and missions](#field-visits-and-missions) | Idea | 2026-10-09 |
| [Attendance fraud detection](#attendance-fraud-detection) | Idea | 2026-10-09 |
| [WhatsApp assistant](#whatsapp-assistant) | Idea | 2026-10-09 |
| [Payroll to accounting](#payroll-to-accounting) | Idea | 2026-10-09 |
| [Ramadan hours and Hijri calendar](#ramadan-hours-and-hijri-calendar) | Idea | 2026-10-09 |
| [Guard patrol tours](#guard-patrol-tours) | Idea | 2026-10-09 |
| [Expense claims](#expense-claims) | Idea | 2026-10-09 |
| [Health and safety](#health-and-safety) | Idea | 2026-10-09 |
| [Certificates and training expiry](#certificates-and-training-expiry) | Idea | 2026-10-09 |
| [Cost of lateness and absence](#cost-of-lateness-and-absence) | Idea | 2026-10-09 |
| [Manager's morning summary](#managers-morning-summary) | Idea | 2026-10-09 |
| [Overtime budget per department](#overtime-budget-per-department) | Idea | 2026-10-09 |
| [Self-service free trial](#self-service-free-trial) | Idea | 2026-10-09 |
| [Reseller programme](#reseller-programme) | Idea | 2026-10-09 |
| [Company groups (holding)](#company-groups-holding) | Idea | 2026-10-09 |
| [Automatic shift scheduling](#automatic-shift-scheduling) | Idea | 2026-10-09 |
| [Burnout warning](#burnout-warning) | Idea | 2026-10-09 |
| [Team leave planner](#team-leave-planner) | Idea | 2026-10-09 |
| [Anonymous suggestion and complaint box](#anonymous-suggestion-and-complaint-box) | Idea | 2026-10-09 |
| [Company buses](#company-buses) | Idea | 2026-10-09 |
| [Shift handover notes](#shift-handover-notes) | Idea | 2026-10-09 |
| [Meal count for the canteen](#meal-count-for-the-canteen) | Idea | 2026-10-09 |
| [Policy acknowledgement with signature](#policy-acknowledgement-with-signature) | Idea | 2026-10-09 |
| [Official holidays per country, updated](#official-holidays-per-country-updated) | Idea | 2026-10-09 |
| [Hands-free sign-in on office Wi-Fi](#hands-free-sign-in-on-office-wi-fi) | Idea | 2026-10-09 |
| [Voice sign-in](#voice-sign-in) | Idea | 2026-10-09 |
| [Owner's app](#owners-app) | Idea | 2026-10-09 |
| [Resignation risk signals](#resignation-risk-signals) | Idea | 2026-10-09 |
| [Employee perks and discounts](#employee-perks-and-discounts) | Idea | 2026-10-09 |
| Attendance correction requests → [`docs/tasks/attendance-corrections.md`](tasks/attendance-corrections.md) | **Scheduled** (mockups first) | 2026-10-09 |
| Daily workers → [`docs/tasks/daily-workers.md`](tasks/daily-workers.md) | **Scheduled** (mockups first) | 2026-10-09 |
| Bugs from the code review → [`docs/tasks/bug-review-2026-10.md`](tasks/bug-review-2026-10.md) | **To do** (not urgent) | 2026-10-10 |

---

## Field visits and missions

**For:** sales reps, technicians, merchandisers, collectors: staff who work at customers' sites,
not in the office. A large segment that today has nothing in Workforce One.

**Promise to the customer:** the manager sees, for each rep and day, which customers were visited,
when, for how long, with a photo taken on the spot, and which planned visits were missed.

### What the PWA can and cannot do

The browser stops the app when the screen locks or the employee leaves it, so a PWA **cannot track
location in the background**. The "route" in the PWA is the line between visits (arrived at A
10:00, at B 11:30), not every street. Continuous tracking belongs to the Flutter app (see the last
section). This covers what most managers ask for: who, when, how long.

### Data

- **Customer sites:** name, latitude, longitude, radius in metres, contact, address. Close to
  today's work locations (`ews_locations`, `src/Locations/`); either a location type "customer site"
  or its own table that reuses the same rules.
- **Visits:** planned (the manager sets customer, day, purpose, assigned employee) or unplanned (the
  rep adds one on the spot; a new customer site waits for a manager to approve its pin).
  Arrival and departure time, GPS + accuracy + integrity result, photo, notes, outcome (sale /
  quotation / customer absent / follow-up / other, configurable).

### Rep's screen (app)

- "Today's visits" list, planned first, then a button for an unplanned visit.
- **Arrived:** GPS must be inside the customer's radius, checked with the same integrity rules as
  Sign In (`src/Attendance/LocationAssessment.php`: accuracy, clock skew, impossible speed since
  the previous visit).
- **Photo from the camera only** (getUserMedia, not the gallery), so an old photo cannot be used.
  The server stamps time, place and employee on it and keeps it outside the public uploads, like
  task files.
- **Done:** notes and outcome; the visit time is departure − arrival.
- **First visit can count as Sign In** for field staff: a schedule type "Field" in the existing
  schedule types configuration that does not require the office location.

### Manager's screen

- **Day map per rep:** visits in order with times, the line between them, time spent at each
  customer and travel time between them. Planned visits not done shown in red.
- **Photo gallery** per visit and per day.
- **Reports** (Report Center + PDF): visits per rep, average visit time, outcomes, customers not
  visited this month, planned vs done.

### Maps

Leaflet + OpenStreetMap tiles: free, no API key. Google Maps looks nicer but is billed per load;
keep it optional for customers who pay for it.

### Weak signal

Reps are often where the network is weak. The PWA keeps the visit (time, GPS, photo) on the phone
(IndexedDB) and sends it when the connection is back; the server marks it as recorded offline and
checks the time and place against the integrity rules.

### Anti-fraud (reuses what exists)

Integrity rules of Sign In, impossible speed between consecutive visits, camera-only photos,
identical coordinates across days flagged (typical of fake-GPS apps), and the same device used by
two reps flagged for the manager. The app flags, the manager decides; nothing is punished
automatically.

### Later, in the Flutter app

- Background location every few minutes **during working hours only**, with the employee's consent
  (legal requirement and battery).
- Android's mock-location flag and device integrity checks (Play Integrity / App Attest) to block
  fake GPS outright.
- Suggested visit order for the day (shortest route).

### Packaging

A natural add-on for a higher plan ("Field"), priced per field employee.

---

## Attendance fraud detection

**For:** management. "The system tells you when someone signs in for a colleague or fakes the
location" sells well, especially to companies with many sites or field staff.

### Already in the code (3.31.71)

Low GPS accuracy refused (> 100 m, `LocationAssessment::MAX_ACCURACY_METERS`), clock skew (> 5 min),
impossible speed between events (> 180 km/h), face sign-in with blink + head-movement liveness and a
server-issued face token, random presence checks, dynamic QR at kiosks. This idea builds on them.

### Limits of a PWA

The browser does not tell the page that a fake-GPS app is running, and gives no stable device id.
So no single check decides: several signals are combined, and patterns are analysed on the server.

### Someone signing in for a colleague (buddy punching)

- **Passkey per phone (WebAuthn):** each employee registers their phone once; every Sign In then
  needs the phone owner's fingerprint or face (the phone's own unlock). A colleague cannot sign in
  for them from another phone. Works in PWAs on current iOS and Android. **Strongest item; start here.**
- **Device mark:** a random id stored on the phone at first use (IndexedDB + cookie). Flag one device
  signing in two employees within minutes, or an employee suddenly on a new device.
- **Photo only when suspicious:** keep a small snapshot for the manager to review, with the
  employee's consent, never for every sign-in.

### Fake GPS

- **Several readings:** 3-4 readings over ~2 s instead of one. Real GPS jitters; fake GPS is often
  perfectly still.
- **Fingerprints of fake-GPS apps in the history:** the same accuracy value every day, coordinates
  identical to the last decimal across days, altitude / heading / speed always empty.
- **Office network:** if the company has a fixed public IP, a Sign In from that IP is strong
  evidence; GPS "inside the office" with a mobile-network IP elsewhere is flagged. Cheap and
  effective; start here too.

### Patterns (nightly job on the server)

Two employees always signing within seconds of each other from the same device or network;
sign-ins always right on the edge of the geofence; suspiciously perfect times (08:59 every day for
a month).

### How it is shown

A trust level per sign-in with the reason in plain language ("the same phone signed in Ahmed and
Mohamed two minutes apart"), a weekly summary for managers, and a per-employee history. **The system
never punishes on its own**: the manager decides. This protects against false positives and legal
trouble.

### Later, in the Flutter app

Android's mock-location flag, Play Integrity / App Attest to reject rooted or modified devices.

### Packaging

Part of a higher plan ("Security" / "Enterprise").

---

## WhatsApp assistant

**For:** employees who live in WhatsApp (Egypt, Gulf) and managers who want to approve requests
without opening the app.

### Official API only

Use the **WhatsApp Business Platform (Cloud API from Meta)**. Libraries that drive WhatsApp Web on a
normal number (Baileys, whatsapp-web.js) are free but against WhatsApp's terms; the number can be
banned without warning and every customer loses the service at once. Not for a product we sell.

### Cost model (Meta, per message since July 2025)

Only template messages the business starts are charged (marketing, utility, authentication). When
the employee writes first, a 24-hour customer service window opens and the replies, and utility
templates inside that window, are free. Design for that: employee-started conversations are almost
free; reminders we start are the paid part. Check the current rate card before pricing the plan.

### Flows

1. **Link the number:** in the app, "Link WhatsApp" shows a code the employee sends to our number.
   Proves the number is theirs and records the opt-in.
2. **Employee asks:** "my balance" / "leave" / a button menu.
   - Leave balance answered from the Leave module.
   - Leave request through a **WhatsApp Flow** (form inside WhatsApp: type, dates, reason), recorded
     in the existing Leave + Approvals engine.
3. **Manager approves:** the request arrives with **Approve / Reject** buttons and is decided from
   WhatsApp. The selling point.
4. **Attendance reminder:** only for employees who have not signed in after the grace period, with a
   "Sign in now" button that opens the app. **Sign In itself stays in the app** (GPS, face);
   WhatsApp gives neither reliably.

### Keep it cheap

- Attendance reminders and manager alerts by **web push first** (already built, free, sent 20 at a
  time); WhatsApp reminders as a paid option in a higher plan, so the customer pays the messages.
- Telegram bot as an optional free channel (no templates, no per-message fees), for customers who
  use it.

### Technical outline

- Webhook REST endpoint with Meta's signature check on every request (`X-Hub-Signature-256`).
- Sending service + queue, sent in batches like push.
- Table linking a WhatsApp number to an employee, with opt-in date and opt-out.
- No new business rules: the bot calls the existing Leave, Approvals and Attendance modules.

### Business setup

A verified Meta Business account, a dedicated phone number (not a personal one), an approved display
name, approved templates (a day or two each). Twilio or 360dialog can help with onboarding.
One shared "Workforce One" number for all companies at first (employee recognised by number); a
company's own number and name as a premium option.

---

## Payroll to accounting

**For:** the accountant. Today Payroll (wp-admin → Payroll, phase 1 in 3.31.14) closes the month and
exports Excel with one row per employee. Accountants need a **journal entry** instead, so they
retype the totals by hand. This removes that step.

### Depends on

Payroll amounts are **before income tax and social insurance** (CHANGELOG 3.31.14). The entry would
be incomplete without them, so Egyptian social insurance + salary tax (and the Gulf equivalents)
come first or together with this.

### The entry

One balanced entry per closed month: **debit** salary expense (split by department as cost center),
**credit** salaries payable, deductions, insurance and tax payable, advances. Debit must equal
credit to the piaster before anything is exported.

### Account mapping (once per company)

A screen where the accountant maps every pay component (basic, each allowance, overtime, each
deduction type, advances, insurance, tax) to an account code of their own chart of accounts, and
each department to a cost center. Without this the export is useless.

### Outputs, in order

1. **Excel in the company's own layout:** the accountant picks the columns, their order and headers
   once; works with any accounting program that imports Excel, including local Egyptian ones.
   Cheapest, covers everyone; do this first.
2. **Odoo** (widespread in Egypt and the Gulf): its external API creates the entry directly, as a
   draft.
3. **Zoho Books** (common in the Gulf): journal API.
4. **QuickBooks Online:** needs an app registered with Intuit and OAuth; wait until a customer asks.

### Rules

- **Always a draft** in the accounting system; the accountant posts it. Workforce One never posts
  into someone's books on its own.
- **One entry per month:** exporting twice never duplicates it; reopening and changing the month
  updates the same entry (keep the external id).
- The Audit Log records that an export happened and where, never the amounts (same rule as
  salaries today).

---

## Ramadan hours and Hijri calendar

**For:** every customer in the Gulf (it is the law) and many in Egypt (company decision).

### The rules differ by country (private sector, per Morgan Lewis, Feb 2026)

| Country | Ramadan limit | Applies to |
|---|---|---|
| Saudi Arabia | 6 h/day or 36 h/week | Muslim employees |
| UAE (onshore) | regular hours − 2 h/day | everyone |
| Kuwait | 36 h/week | everyone |
| Oman | 6 h/day or 30 h/week | Muslim employees |
| Qatar | 36 h/week | everyone |
| Bahrain | 6 h/day or 36 h/week | Muslim employees |
| Egypt | no private-sector obligation; often a company decision | — |

Re-check before building: these change and some come by yearly decree.

### Seasonal working-hours periods (Ramadan is the first)

- The admin sets a date range and the new hours (new times, or "minus N hours"), and who it applies
  to (everyone, departments, chosen employees).
- **Country presets** fill the rules (pick "Saudi Arabia"); the admin can change them.
- **Never store an employee's religion** (sensitive data). Where the law covers Muslim employees
  only, the admin ticks "Ramadan hours apply" per employee or department.

### Where it plugs in

Every rule reads an employee's hours from one function, `working_hours()` in
`includes/trait-work-time.php` (sign-in window, late, absent, early leave, overtime). Make it
date-aware (its cache is per employee today, not per date) and return the seasonal hours when the
day falls in a period; everything else follows without changes.

### Hijri calendar

- Show the Hijri date next to the Gregorian one in the app and reports (Umm al-Qura via PHP `intl`;
  a built-in fallback for shared hosting without `intl`).
- **Ramadan really starts with the moon sighting** and can differ by a day from the calculation, so
  the system **suggests** the dates two weeks ahead and asks the admin to confirm or adjust.

### Eid

Suggest Eid al-Fitr and Eid al-Adha days in the company holiday calendar (`ews_company_calendar`,
already used by attendance and payroll); the admin confirms.

### Payroll and reports

Expected hours are lower during the period: 6 worked hours is not a shortfall, and overtime starts
after the reduced hours, not the normal ones.

---

# Shorter ideas (2026-10-09)

One paragraph each; expand into a full entry above when one gets closer.

### Group: New segments

## Guard patrol tours

Security companies (large market in Egypt and the Gulf). A guard walks a route of checkpoints and records each one with a cheap **NFC tag** (Web NFC works in Chrome on Android) or a QR sticker; time, GPS and integrity rules as for Sign In. The supervisor sees missed or late checkpoints live and per shift. Sold per site.

## Expense claims

Reps and technicians photograph a receipt (fuel, transport, client lunch), pick a category and amount; the manager approves through the existing Approvals engine; approved amounts are added to the month's Payroll. Pairs with Field visits. Camera-only photo, duplicate-receipt check (same amount + date + photo hash).

## Health and safety

Incident and hazard reports with photo and location, severity, follow-up actions and owner; optional pre-shift PPE confirmation ("I am wearing helmet, vest, shoes"). For construction and factories; monthly safety report.

## Certificates and training expiry

Per employee: licences, safety certificates, health cards (restaurants), courses, with expiry dates and file; alerts to the employee and HR 30/7 days before; optional block on scheduling someone whose required certificate expired. Today "Training Course" exists only as a schedule type.

### Group: Owner value

## Cost of lateness and absence

Turn attendance into money: "Lateness cost you EGP 18,400 this month", per department and employee, from Payroll's day rate and the report engine's late / absent figures. A dashboard card and a monthly line in the owner's summary. Sells the product and supports renewals. Data already exists.

## Manager's morning summary

Every working day at a set time (e.g. 09:30): absent, late, on leave, requests waiting for the manager, by web push (free) and optionally email. Brings managers into the app daily. Uses the existing notification policy and push batching.

## Overtime budget per department

A monthly overtime budget (hours or money) per department; the approver sees what is left when approving and is warned near the limit; report of budget vs actual.

### Group: Selling

## Self-service free trial

A visitor signs up on the website and gets a trial site with realistic sample data and a step-by-step setup wizard (company, locations, shifts, first employees, invite links). Built on `tools/new_company.sh` plus a sample-data seeder; trial expiry and conversion to paid. Sells without a live demo for every lead.

## Reseller programme

Small IT companies in the governorates and the Gulf sell and support Workforce One for a monthly commission per subscription: reseller portal with their customers, commission report, white-label option later.

## Company groups (holding)

A holding with several companies: one login for group management, consolidated reports across companies, transfers of employees between companies. Brings large contracts. Depends on the multi-company model (Multisite or the Laravel move).

### Group: Smart features

## Automatic shift scheduling

The manager sets the need (e.g. 5 cashiers mornings, 3 nights per day); the system drafts the roster from availability, approved leave, swap requests, contracted hours and labour-law limits; the manager adjusts and publishes. Start with a simple greedy algorithm, explain every assignment.

### Group: Team health

## Burnout warning

Alert the manager when an employee has taken no leave for a long time, or overtime is high over several months ("Ahmed: no leave for 8 months, 60 h overtime in 3 months"). Thresholds per company. Framed as care, private to the manager and HR.

## Team leave planner

Team calendar of leave; a minimum staffing per team or department per day; approving a request that breaks the minimum warns or blocks (policy choice). Uses Leave, Teams and Departments.

## Anonymous suggestion and complaint box

A safe channel to raise problems or ideas, truly anonymous (no user id stored, no IP), optional follow-up via a random case code; handled by named HR roles. A compliance requirement in large companies and the Gulf.

### Group: Factories and hospitals

## Company buses

Factories in new cities depend on company buses: routes, stops, who rides which bus, "bus arriving" notifications, and when a bus is late its riders are not marked late automatically (the driver or supervisor records the delay). Solves a daily argument in every factory.

## Shift handover notes

The outgoing shift leaves notes for the incoming one (machine 3 faulty, patient in room 5 needs follow-up), with acknowledgement by the incoming lead; searchable history per line or ward.

## Meal count for the canteen

At a set time the canteen sees how many people are actually present per site (and dietary counts if stored), to cook the right amount and cut waste.

### Group: Compliance

## Policy acknowledgement with signature

HR publishes a policy or penalty regulation; every concerned employee reads it in the app and signs (drawn signature or confirmation with time, device, IP); HR sees who has not signed and reminds them; signed PDF per employee. Protects the company in disputes.

## Official holidays per country, updated

Official holidays per country loaded into the company calendar each year, suggested for the admin to confirm (Egypt's announced holidays move every year). Shares the confirmation flow with the Ramadan / Eid idea.

### Group: Attendance experience

## Hands-free sign-in on office Wi-Fi

In the Flutter app: when the phone joins the office Wi-Fi during the sign-in window, sign in automatically and confirm with a notification; GPS still checked; the employee can turn it off. Not possible in a PWA.

## Voice sign-in

In the Flutter app: "Sign me in" through Siri Shortcuts / Google Assistant app actions, then the same rules as the button (face or passkey can still be required).

### Group: Owner value

## Owner's app

A separate, very simple screen for the owner: today's numbers for every branch, month trends, branch comparison on punctuality, cost of lateness. Read-only.

### Group: Team health

## Resignation risk signals

A quiet signal to the manager when someone's pattern changes (lateness rising, more sick days, sudden drop in punctuality) to start a conversation. **A prompt to talk, never a label**: visible only to the direct manager and HR, explained, no score shown to anyone else, easy to dismiss. Check privacy law before building.

### Group: Extra revenue

## Employee perks and discounts

Agreements with gyms, restaurants and shops; employees get discounts from the app; Workforce One takes a commission. A benefit for employees, revenue for us, and a reason for companies not to cancel.
