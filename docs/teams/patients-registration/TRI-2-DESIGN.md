# TRI-2 design note: the emergency lane

Status: **DESIGN NOTE, NOT BUILT, NOT AUTHORISED.** Nothing is built until the owner answers section 10 and says
to start TRI-2a. It depends on TRI-1 (`TRI-1-DESIGN.md`), which must be built first. Synthetic data only.

Owners: Queue & TV (the lane, the boards, Call next and the alert), with Patients & Registration (the triage
record that raises the alert). The Lead UI/UX Designer reviews every new or changed screen.

Source: the 19 Nov 2026 meeting on the patient flow (Register, Triage, Waiting with an emergency lane, Serving,
On hold, Plan, Billing, Dispensary, Completed) and TRI-1 section 3, step 5.

## 1. What the meeting asked for

A patient whom triage puts in the **red zone** goes to the top of the queue, in a row of their own. An alert
appears for **every doctor at the branch** and stays, blinking, until one doctor acknowledges it.

Owner decisions already given (19 Nov 2026): the triage guideline is signed by **Dr Fatin Adilah**; KPOne calls no
AI service; staging uses synthetic data only.

**KPOne's alert is an aid, not the clinic's emergency procedure.** A browser can be closed, muted, asleep or on
another branch. The CA who finds a red-zone patient still tells a doctor in person at once. The screens say so
(section 6.4), and the clinic SOP must say so too.

## 2. What KPOne has today

- **Urgent order.** `visits.priority` is `normal` or `urgent`. The Queue board (`QueueDirectoryService`) and Call
  next (`CallNextService::next()`) order today's entries before carry-over, then Urgent first, then by arrival.
  TRI-1 sets `priority = urgent` for yellow and red, so a red patient already goes first, but in the same table
  as everyone else and only in **their assigned doctor's** queue.
- **Each patient belongs to one doctor.** A visit has `assigned_doctor_user_id`. A Resident Doctor's board
  (`queue.view.own`) lists only their own patients, Call next picks only from their own queue, and Call In by a
  doctor (`queue.call.own`) is allowed only on their own patient (`QueueEntryService`). A CA Supervisor or
  Director (`queue.call.branch`) can call for any doctor. **Today no other doctor sees a red patient who is
  assigned to someone else.**
- **The doctor busy rule** (PR #175): a doctor serving a patient who is not held, at any branch, cannot Call In
  another. At most three held consultations per doctor (HC-01).
- **Not ready to call** (PR #177): Call In is refused while the flag is active; Call next skips flagged entries;
  only the attending doctor may override, with a reason.
- **Polling, no push.** The Queue board polls every 3 seconds (backing off to 30 s after failures), the
  Registration board every 10 seconds, the consultation page's "patients waiting" button every 15 seconds. The
  not-ready "ready" toast and the Q-2 "treatment done" pop-up use cursor endpoints (`/queue/readiness-updates`,
  `/queue/treatment-updates`) where the first poll only sets the cursor, so a refresh never repeats an alert.
  Sound is opt-in per device (`useCallReadinessAlerts`, remembered in browser storage) and needs a user gesture.
- **The TV** (`/queue-display/feed`, every 5 seconds) shows called numbers, rooms and times only, never priority.
- **The public QR status page** polls every 10 seconds and counts "patients ahead" by arrival only (TRI-1
  decision 6), so it never hints that someone is urgent.

## 3. The flow

1. The CA saves a triage (TRI-1) whose chosen zone is **red**. In the same transaction, `TriageService` raises an
   **emergency alert** for that queue entry (section 4). Nobody presses a separate button.
2. The **emergency lane** appears above the normal table on the Queue board and the Registration board: one row
   per open alert, red border, the word **"Emergency"** with an icon, the queue number, the patient's name, how
   long ago the alert was raised, the assigned doctor, and "Not acknowledged" or "Acknowledged by Dr X at 10:42".
3. Every **Resident Doctor whose active branch is this branch** sees an **alert banner** on every staff page,
   including the consultation page in focus mode: "Emergency: A-014, red zone, waiting 1 min". It stays,
   pulsing, until one doctor presses **Acknowledge**.
4. The first doctor to acknowledge stops the alert for everyone. The lane row stays, showing who acknowledged.
   (What Acknowledge also does to the assignment is owner question 1; the recommendation is section 3.1.)
5. The patient is seen through the ordinary Call In. The lane row leaves when the queue entry leaves Waiting
   (called in, removed or the visit cancelled), or when a later triage lowers the zone (TRI-1 section 5.3).

### 3.1 Recommended meaning of Acknowledge (owner question 1)

Acknowledge has two buttons in the banner:

- **"I'll see this patient"**: records the acknowledgement and, in the same transaction, makes the acknowledging
  doctor the visit's assigned doctor through `VisitAdministrationService` (audited as today), so their Call In
  and Call next work at once. Refused if the patient is no longer Waiting.
- **"Seen, not taking"**: records that this doctor has seen the alert, but **does not stop it**. The alert keeps
  going until someone presses "I'll see this patient". This avoids an alert being silenced by a doctor who then
  does nothing.

The banner explains that the busy rule still applies: a doctor serving another patient must **hold** that
consultation first (HC-01's limit of three holds still applies), and the banner offers a link to do so.

## 4. Data

### 4.1 `queue_emergency_alerts` (new)

One row per alert. Columns:

- Organisation, branch, visit and queue entry.
- The triage assessment row that raised it (`visit_triage_assessments.id`, TRI-1).
- Raised at (server time, UTC).
- Taken by (doctor) and taken at, once "I'll see this patient" is pressed.
- Closed at and a close code: `taken`, `left_waiting`, `zone_lowered` or `visit_cancelled`.
- `lock_version`.

PostgreSQL: **at most one open alert per queue entry** (a unique partial index where `closed_at` is null); an
insert trigger checks that the entry is a Waiting consultation of the same organisation and branch and that the
raising triage row belongs to that visit and chose red; a closed alert never changes; delete and truncate are
refused.

### 4.2 `queue_emergency_alert_events` (new, append-only)

`raised`, `seen` (one per doctor who pressed "Seen, not taking"), `taken` and `system_closed`, with the actor
(null for a system close), the time, and the close code. PostgreSQL refuses update, delete and truncate. This is
the "who acknowledged and when" record. Audit `queue.emergency.raised`, `queue.emergency.seen`,
`queue.emergency.taken` and `queue.emergency.closed` hold ids and codes only, never readings or names.

### 4.3 Nothing else changes

The queue number, `queued_at`, the TV call records, the Visit's `priority` (already Urgent from TRI-1) and the
QR intake are unchanged. A red patient who is triaged again and stays red keeps the same open alert (no second
alert, no second sound).

## 5. Order and Call next

- **Board order:** the lane sits above the table and is ordered by raised time, oldest first. Below it, the table
  keeps today's rule. The red patient does not appear twice: the lane row replaces their table row.
- **Carry-over:** a red patient from a previous day still goes in the lane (red outranks "today first").
- **Call next:** for the doctor who holds the patient (assigned, or taken under 3.1), an open alert comes before
  every other entry, including today's Urgent. Other doctors' Call next is unchanged: they reach the patient by
  "I'll see this patient", never by Call next picking another doctor's patient.
- **Not ready to call:** recommended (owner question 4): the flag **cannot be set** on an entry with an open alert
  (refused in the service), and if the patient already had the flag when triage turned red, the alert still
  fires and the lane row shows "Not ready: <reason>" so the doctor can override with a reason, as today.

## 6. The alert without any messaging service

### 6.1 Polling

- A new read-only endpoint, `GET /queue/emergency-alerts` (`no-store`, about 60 a minute), returns for the active
  branch: the open alerts (alert id, queue number, patient name, raised at, assigned doctor's display name,
  whether this doctor pressed "Seen") and a cursor.
- The shared clinic layout polls it **every 5 seconds** for doctors on every staff page, and the Queue board's
  existing 3-second poll carries the lane. The first poll only sets the cursor, as in the readiness alerts, but
  **an alert that is still open is always shown again after a refresh**: unlike "ready" and "treatment done",
  an emergency is a state, not an event, so refreshing must never hide it.
- **No push, no WebSocket, no email, SMS, WhatsApp or phone notification.** Patient messaging stays unauthorised
  (`AGENTS.md`), and the HR messaging exception covers staff HR only.
- **Known limit:** browsers slow timers in hidden tabs (often to once a minute) and stop them on a sleeping
  device. The banner can therefore be late on a doctor's laptop. This is why section 1 keeps the in-person rule.
  The tab title also changes to "(!) Emergency · KPOne" while an alert is open.

### 6.2 Sound

- A distinct tone (not the call chime, the ready chime or the treatment two-beep), repeated every 10 seconds
  while the alert is open and not taken.
- Browsers allow sound only after a user gesture on the page. The banner shows "Sound is off on this device. Tap
  to turn on" until it can play. Recommended (owner question 5): emergency sound is **on by default** for
  doctors and cannot be switched off with the existing chime switch; it can be silenced on one device for 2
  minutes only.

### 6.3 Blinking and accessibility

- "Blinking" is a **slow pulse of the border and background, once a second at most**, well under the WCAG limit
  of three flashes a second. With `prefers-reduced-motion`, there is no animation: a thick static border and the
  icon.
- Never colour alone: the word "Emergency", an icon and the number.
- The banner is a non-modal region pinned at the top of the page (it must not trap a doctor mid-consultation),
  announced once by screen readers (`role="alert"`), with the buttons reachable by keyboard and 44 px tall below
  1024 px. It does not cover the consultation toolbar (UX-5 toast rule).
- Dark mode and 390 px checked; staff screens in English.

### 6.4 Wording on screen

The lane and the banner both carry one muted line: "Also tell a doctor in person. This alert can be missed."

## 7. Access

- **Raising** needs no new permission: it happens inside `TriageService` under `visits.triage.record.branch`
  (TRI-1, CA and CA Supervisor; the MA only if the MA role is approved).
- **New permission `queue.emergency.respond.branch`** for `resident_doctor` only: see the banner and the open
  alerts at their active branch (including patients assigned to another doctor), press "Seen" and "I'll see this
  patient". This widens what a doctor sees beyond their own queue, for red-zone patients only, so it needs the
  owner's explicit role decision. The banner shows the queue number and name only, never readings or red flags;
  the readings open once the doctor takes the patient (`encounters.view.own`, existing).
- **CA, CA Supervisor and Director** see the lane through `queue.view.branch` (existing), including who
  acknowledged. They cannot acknowledge for a doctor (owner question 3).
- **None for** Administrator, Technical Admin, Finance Officer, Panel Officer, HR Manager, Marketing, Business
  Development, locums without a clinical role, or the TV account.
- Every action: a fresh permission and **effective branch** check under lock, as `AGENTS.md` requires.

## 8. What the TV and the QR page must never show

- **TV:** no lane, no "Emergency", no zone, no colour change, no reordering of the list and no spoken word about
  urgency. A red patient's call looks and sounds like any other call. The TV feed payload must not gain any
  field from this slice (a test pins the exact feed keys, as TV-1 and Q-2 do).
- **QR status page:** no zone, no alert, no change to "patients ahead" (still by arrival, TRI-1 decision 6), and
  no change to the wait range. The red patient's own QR page looks the same as anyone's. A test pins the payload.
- **Insights, reports and `ai_read`:** nothing in this slice. A later count of emergencies needs its own decision.

## 9. Rules for the build

- **Domain:** `EmergencyAlertService` in `app/Domain/Queue/Emergency/`, mirroring `Readiness/`: `DB::transaction`,
  locks in the `ConsultationLocks` order (users, then visit, queue entry, alert), `lock_version` on the alert, an
  idempotency key on "Seen" and "I'll see this patient", and structural audit.
- **System closes** happen in the same transaction that moves the entry out of Waiting (Call In, removal, visit
  cancel) and in the triage transaction that lowers the zone, recorded as `system_closed` events.
- **Races (serial Contention suite):**
  - two doctors pressing "I'll see this patient" at once: one wins, one assignment change, the other gets "Dr X
    is taking this patient";
  - "I'll see this patient" against the assigned doctor's Call In: either the take wins and the Call In is
    refused because the assignment changed, or the Call In wins and the take is refused because the patient is
    no longer Waiting; never both;
  - "I'll see this patient" against a triage that lowers the zone;
  - two CAs saving red triage at once: one alert;
  - "I'll see this patient" against visit cancellation.
- **Tests:** permission and branch denial, a doctor at another active branch sees nothing, refresh shows an open
  alert again, closed alerts never return, TV and QR payloads pinned, frontend tests, screenshots at 1440 and
  390 px in light and dark, checked by the Lead UI/UX Designer.

## 10. Open owner questions, with recommendations

1. **What does Acknowledge mean?** Recommended: two buttons, "I'll see this patient" (stops the alert and takes
   the patient) and "Seen, not taking" (recorded, alert continues), section 3.1.
2. **Which doctors are alerted?** Recommended: Resident Doctors whose **active branch** in KPOne is this branch.
   KPOne has no "on shift" signal that the queue trusts; the HR roster is not used for this.
3. **May a CA Supervisor or Director acknowledge for a doctor** (for example when the doctor was told in person
   and is already walking over)? Recommended: no in v1. The lane shows "Not acknowledged for 3 min" in bold so
   the CA fetches a doctor; a doctor presses the button when they arrive.
4. **Not-ready flag on a red patient:** recommended as section 5 (cannot be set while an alert is open; an
   existing flag stays visible and the doctor overrides as today).
5. **Sound:** recommended on by default for doctors, repeated every 10 seconds, silenced on one device for 2
   minutes at most.
6. **The new permission** `queue.emergency.respond.branch` for Resident Doctors: please approve or change. It lets
   every doctor at the branch see a red-zone patient's number and name, which they cannot today.
7. **Escalation when nobody takes the patient:** recommended: none in KPOne beyond the CA's lane counter ("Not
   acknowledged for N min"); the clinic SOP says what the CA does after, for example, 2 minutes.
8. **Locum doctors:** a locum without a clinical role sees nothing. If locum doctors ever see patients in KPOne,
   that is a separate role decision (`AGENTS.md`).

## 11. Slices

- **TRI-2a:** the two tables, `EmergencyAlertService`, raising from `TriageService`, the permission, the
  endpoint and tests (needs TRI-1a).
- **TRI-2b:** the lane on the Queue and Registration boards and Call next ordering.
- **TRI-2c:** the banner on every staff page, sound and accessibility.
