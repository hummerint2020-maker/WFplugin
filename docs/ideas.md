# Ideas bank

Future features the owner wants to keep, not scheduled yet. When one is picked up, turn it into a
brief in `docs/tasks/` (like `docs/tasks/multisite.md`) and mark it here as **Scheduled**.

| Idea | Status | Added |
|---|---|---|
| [Field visits and missions](#field-visits-and-missions) | Idea | 2026-10-09 |
| [Attendance fraud detection](#attendance-fraud-detection) | Idea | 2026-10-09 |
| [WhatsApp assistant](#whatsapp-assistant) | Idea | 2026-10-09 |

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
