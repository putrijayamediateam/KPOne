# HR-11 Clinic compliance: design note

Written 2026-11-10 by the Catalogue & settings team (Adlina, Ravi; designer Nadia) for the HR team to agree (Liyana,
Suresh), then the owner's ship-it. **Status: draft. Nothing is built.** HR-11 comes from HR-PLAN section 3 and the
owner-approved backlog (`docs/teams/hr/TEAM.md` section 9, item 11). The owner asked to start it on 2026-11-10
("i want to start all that", relayed by B.E.R.N.I.S.S). It is clinic compliance, not HR data: no patient data and no
staff personal data anywhere in it. Synthetic data only; it approves no real use.

## 1. What the clinic gets

Three small tools on one page, **Clinic compliance**, for the people who run the clinic:

1. **Clinic licences.** The clinic's own documents with an expiry (clinic registration, X-ray, clinical waste,
   calibration): a record per licence with its dates and a scan or photo, a renewal adding a new version, and
   "Expires in 21 days" or "Expired 3 days ago" shown when someone opens the page.
2. **Monthly audit checklist.** The clinic's own checklist, filled in once a month per branch by a supervisor, with
   photos. Each failed item becomes a **supervisor task** that stays open until someone does it.
3. **Vaccine fridge temperature log.** A reading per fridge each day, an out-of-range reading shown as an **alert**
   until someone records what was done, and a day with no reading shown as missed.

**Reminders and alerts stay inside KPOne:** a strip at the top of the page and an Overview tab. Nobody is messaged
(no WhatsApp, SMS or email, even though HR-14 exists). A reminder is only seen when someone opens the page; the page
says so.

## 2. Not in HR-11

- Any message, push notification or sensor or logger link. KPOne reads no thermometer; a person types the reading.
- A shipped legal checklist, licence list, temperature range or regulator content. The clinic enters its own (the
  form may suggest, as HR-5 does). KPOne makes no statement that the clinic complies with anything.
- A cold-chain decision (whether to discard vaccines). That belongs to the clinic's pharmacist and the vaccine
  supplier's guidance; KPOne records what was done.
- Any gate on clinical work (HR decision 4 stands): a missed reading or an expired licence blocks nothing.
- Staff licences (APC, BLS, Hep B): they stay in HR-5. Clinic documents here are the clinic's, not a person's.
- Exports, PDFs, the KKM audit file button, incident reports (HR-10) and any link to the outside HR product.
- Patient names, identity documents or faces in photos (the form says so; nothing can check it, see 9).

## 3. Rules (HR-11 as a whole)

1. **One page, `/clinic-compliance`**, tabs Overview, Licences, Fridge log, Audits and Settings; a tab shows only to
   someone who may use it. One Clinic management card, "Clinic compliance", with the usual one-line description.
2. **Statuses are worked out when a page opens and never stored** (the HR-5a rule): licence Valid, Expiring in N
   days or Expired; fridge reading In range or Out of range; a day or month Done or Missed.
3. **Records are append-only.** A licence renewal, an audit, a reading, a task action and a fridge review each add a
   row; nothing is edited or deleted. A wrong reading or licence entry is marked entered in error with a fixed reason
   and the original stays readable.
4. **Files and photos** go on the private disk, encrypted, under a random name; the original file name is never
   stored ("Photo 1", "Scan 1"). They are checked by content, not by name: JPG or PNG are decoded and re-encoded
   (which drops EXIF, so no camera or GPS data), scaled to at most 2,400 px, at most 5 MB, refused above 24 million
   pixels. A licence scan may also be a PDF (downloaded, never rendered). Served with `nosniff`, a sandbox CSP and
   `no-store`. This reuses HR-2b's `LeaveEvidenceFiles::prepare()` unchanged (it already takes a size limit and a
   field name); HR owns it, so the HR team agrees this one read-only reuse. Opening a file is audited.
5. **Every write:** a fresh permission check, branch authority where it is branch-scoped, `DB::transaction`, row
   locks, `lock_version`, an idempotency key and an audit row holding ids and codes only (never a reference number,
   a note, a temperature or a file name). PostgreSQL repeats the append-only, one-way and shape rules.
6. **Dates and times** are stored in UTC and shown in the branch time zone; "today" and "this month" are the
   branch-local day and month (the CLOCK-1 and CLOCK-2 lesson).
7. **Staff names** shown next to a record follow the BIL-2 staff-visibility rule ("Staff member" otherwise).
8. **Nothing ships with a number or a list.** No licence types, no warning days, no temperature range, no checklist
   items, no retention. Until the clinic enters them the page says what is not set up yet. The forms suggest typical
   values as hints only (60, 30 and 7 days; 2 to 8 °C), marked "confirm with your pharmacist or supplier".

## 4. Slices (each its own PR, Ravi, the gate, Daniel for screens, ship-it)

| Slice | What | Why this order |
|---|---|---|
| HR-11a | The page shell, Overview, Settings (licence types), **Licences** | Smallest, and it sets up the page, the permissions, the hub card and the file handling the others reuse |
| HR-11b | **Fridge log** | A daily task, so the most use; no files |
| HR-11c | **Audits and supervisor tasks** | The largest; it reuses HR-11a's photos and HR-11b's patterns |

The owner may reorder them. Migration range for the whole of HR-11: `2026_11_13_300100` onward (the first slice
takes `300100` and `300200`).

## 5. Licences (HR-11a)

- **Types** (Settings tab): a name, whether it expires, and the "warn from N days" figure; archived, never deleted.
  The clinic enters them (the form suggests the four named above as examples).
- **Records:** per type and **branch** (or "whole clinic"): reference (encrypted, optional), issued on, expires on,
  up to 3 files, a short note (encrypted). Renewing adds a new version; the page shows the version in force and the
  history. Entering an earlier expiry than the version in force is refused with a hint (the renewal date wins; HR-5
  has the same rule).
- **Status:** worked out from the version in force: Valid, Expiring in N days (from the type's warn days; today
  counts), Expired, or Not recorded for a type with no record yet.
- **Not in HR-11a:** reminders by message; a link to staff or patients; any regulator lookup.

## 6. Fridge log (HR-11b)

- **Fridges** (Settings tab): a name, a branch, the allowed range in tenths of a degree (for example 20 to 80),
  and how many readings a day are expected (clinic-entered; the form suggests 1). Archived, never deleted.
- **A reading** (any holder of the record permission at that branch, on the fridge's page): temperature now, and
  optionally the lowest and highest since the last reading (most fridge loggers show these), each in °C with one
  decimal, between -30.0 and 30.0, plus an optional fixed reason (door left open, defrost, power cut, delivery,
  other). The time is the server's; a reading cannot be dated. Stored as whole tenths, never a float.
- **Out of range:** any of the three numbers outside the fridge's range. The page shows a red alert with the fridge,
  the time and the numbers, until a **review** is recorded: what was done (rechecked and now in range, moved stock,
  called the supplier, discarded stock, other with a short note) by someone at that branch with the review
  permission. The reviewer may be the person who took the reading (a small clinic; the reading and the review are
  both on record), unlike the two-person rules elsewhere. A reading is never changed by a review.
- **Missed:** a day with fewer readings than expected, shown from the end of the branch-local day, and for today
  only after a clinic-entered "expect the reading by" time (none shipped). Never stored; shown when the page opens.
- **Entered in error:** a wrong reading is marked by someone with the manage permission with a fixed reason; it
  leaves the alert and the daily count but stays readable.
- A month table per fridge on screen (day by day, with the alerts); no chart and no export in v1.

## 7. Audits and supervisor tasks (HR-11c)

- **Checklist** (Settings tab, versioned): sections and items the clinic types (a title, an optional help line, and
  whether a photo is required when an item fails). A new version never changes an audit already submitted.
- **An audit** is one checklist version, one branch and one branch-local month. A supervisor starts it, answers each
  item (Pass, Fail or Not applicable), adds a short note and up to 3 photos per item, and **submits** it. A failed
  item needs a note (and a photo where the item says so). Submitting is final (append-only); a second audit for the
  same branch and month is refused while one is open or submitted (an amendment is a new line on the submitted
  audit, never an edit).
- **Supervisor tasks:** submitting writes **one task per failed item**, in the same transaction. A task is Open, then
  Done (a note and up to 3 photos of the fix), then optionally **Verified** by someone other than the person who
  marked it done (switch in Settings; the form suggests on; default below). A task can be cancelled with a fixed
  reason. Tasks belong to the audited branch and show to everyone with the perform permission there.
- **Missed month:** no submitted audit for a branch's previous month is shown on Overview from the 1st (never
  stored, no scheduler).
- Photos can be kept for a clinic-entered number of days and then purged by a daily `compliance-photos:purge` run
  (like `hr-leave-evidence:purge`); with no number set nothing is purged and the setting says so.

## 8. Permissions (narrow, additive, for the owner to confirm)

Names follow `AGENTS.md`. Each is a new permission given by one additive migration (`firstOrCreate`, give only what
is missing, never revoke, `down()` a no-op); none is people or access authority. Technical Admin gets none (no
implied access). The TV account and Marketing get none.

| Permission | Lets you | Default holders [TO CONFIRM] |
|---|---|---|
| `compliance.licences.manage.organisation` | add and renew licences and their files; manage licence types; see Overview | Director, Administrator, HR Manager |
| `compliance.fridge.manage.organisation` | manage fridges and ranges, the reading settings, mark a reading entered in error | Director, Administrator |
| `compliance.fridge.record.branch` | enter a reading at an assigned branch | CA, CA Supervisor, Director, Administrator |
| `compliance.fridge.review.branch` | record the action taken on an out-of-range reading at an assigned branch | CA Supervisor, Director, Administrator |
| `compliance.checklists.manage.organisation` | manage the checklist and its versions; see every audit | Director, Administrator |
| `compliance.audits.perform.branch` | run and submit an audit, and work or verify its tasks, at an assigned branch | CA Supervisor, Director, Administrator |

The Resident Doctor, Finance Officer and Panel Officer get none. Whether the CA may take readings is a real
question for the clinic (default yes, since it is a two-minute daily job). Everyone with any of these sees only the
tabs and branches they hold a permission for; a request for another is refused in the service and by the route.

## 9. Tables (sketch; the build slices fix the columns)

`compliance_licence_types`, `compliance_licences` (versioned), `compliance_licence_files`, `compliance_fridges`,
`compliance_fridge_readings` (append-only), `compliance_fridge_reviews`, `compliance_checklists` and
`compliance_checklist_items` (versioned), `compliance_audits`, `compliance_audit_answers`,
`compliance_audit_photos`, `compliance_tasks`, `compliance_task_events`, `compliance_settings` (the warn defaults,
reading cut-off time, photo retention, task verification) and a retry table per writing screen, as other settings
pages do. Domain code in `app/Domain/Organisation/Compliance` (clinic data, so not under `app/Domain/HR`).

PostgreSQL enforces: no update, delete or truncate of readings, reviews, answers, photos and events; a reading's
shape and range; one open or submitted audit per branch and month; the audit's checklist version and branch match;
a task only for a failed answer of its own audit; one-way task states; at most 3 files or photos per owner; and
that the verifier is not the person who marked a task done.

## 10. Screens (Nadia, with Daniel's look before each build)

Built from the shared parts (PageHeader, tabs, the shared table from 768 px and cards below, StatusBadge,
`FileChooseButton` for files and photos, OperationalSelect). A status is never colour alone (words and an icon);
fridge alerts use the danger tokens and licence warnings the warning tokens; 44 px targets below 1024 px; dark mode
checked; 320 px fit. A fridge reading form works one-handed on a phone. English wording, like the other HR pages;
the clinic's own checklist and licence names stay as typed. The hub card sits in the Clinic group and changes the
exact Clinic management destination map (see 12).

## 11. Tests (planned)

Permission and branch refusals for each action (all roles); append-only and one-way rules in the service and in
PostgreSQL; the expiry, in-range, missed-day and missed-month calculations at branch midnight; file checks (wrong
type, too large, oversized pixels, EXIF dropped, nothing stored under the original name); a failed item raises
exactly one task in the same transaction; a double submit and two supervisors submitting one month (real
two-process PostgreSQL races in the Contention suite); two verifiers and a verifier-is-not-the-doer rule; a source
test that nothing here sends a message; a frontend test per screen for names, errors and 44 px targets.

## 12. Existing assertions this will touch (to list for the owner before each build)

- `ClinicManagementTest`: the exact destination map gains `/clinic-compliance` (HR-11a). One line, as for CAT-12.
- Permission-catalogue pins (the exact permission list and per-role counts) gain the new names. To be found and
  listed at build time, not assumed.
- `AGENTS.md` names KPOne HR and does not name clinic compliance. The owner confirmed the HR-11 slice and the
  clause on 2026-11-06 (BASELINE, KPOne HR row); whether one more phrase is wanted is the coordinator's call.

## 13. Questions for the owner [TO CONFIRM], with the defaults used

1. Order of the slices (default: licences, fridge log, audits).
2. Which roles hold each permission (the table in 8).
3. Licence types to start with, and the warn days (default: none shipped; hints 60, 30 and 7).
4. Fridge range (default: none shipped; hint 2 to 8 °C, to confirm with the clinic's pharmacist) and readings a day
   (default: 1; hint 2) and an "expect it by" time (default: none).
5. The monthly checklist's items (the clinic enters them; where the clinic's own checklist comes from).
6. Whether a done task needs verifying by someone else (default: on).
7. Photo retention (default: kept until the clinic sets a number) and the longest to keep fridge readings (default:
   never purged).
8. Whether the reading and its review may be by the same person (default: yes).

## 14. Known risks and limits

- It is a record and a reminder, not a certification. KPOne cannot know a fridge was really in range; it shows what
  a person typed.
- A reminder is seen only when the page is opened. If nobody opens it, nothing happens (no message by design).
- Photos can show something they should not (a patient, a document). The form says not to; nothing can check.
- A fridge with two readings a day shows "missed" only at the end of the day, and an early-morning check can only
  warn if the clinic sets the cut-off time.
- A checklist version cannot be changed after audits use it; a mistake means a new version.
- Not load-tested: Overview reads every licence, fridge month and open task of the branches a person may see.
