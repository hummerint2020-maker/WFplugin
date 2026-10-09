# Ideas bank

Future features the owner wants to keep, not scheduled yet. When one is picked up, turn it into a
brief in `docs/tasks/` (like `docs/tasks/multisite.md`) and mark it here as **Scheduled**.

| Idea | Status | Added |
|---|---|---|
| [Field visits and missions](#field-visits-and-missions) | Idea | 2026-10-09 |

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
