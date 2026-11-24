# TRI-1e design note: the doctor's read-only Triage view

Status: **DESIGN NOTE, NOT BUILT.** Lead: Syazwan. Reviewer: Farhana. Classic only. Synthetic data only.

Owner decision (24 Nov 2026, relayed by B.E.R.N.I.S.S): "Doktor di Triage dan Billing... adakan ia, backend sedang
built kan?" The doctor gets a read-only Triage view. The Billing part of that sentence belongs to the Billing team
and is not in this note.

## 1. What the doctor sees today (main, after TRI-1c and TRI-1d)

- **Zone chip** ("Red zone", "Yellow zone", "Green zone" or "Triaged"): shown to the patient's own doctor on the Queue
  board, the Call next card and the consultation page, while the patient is Waiting or Serving (`TriageZoneProjection`).
- **Vitals:** copied from triage into the encounter when the consultation starts, labelled "From triage, last taken by
  <CA> at <time>", or "(edited)" while unsaved.
- **Nothing else.** The triage page (`GET visits/{visit}/triage`) sits behind `visits.triage.record.branch`, which the
  doctor does not hold, so a doctor gets 403 there. The doctor never sees:
  - the red flags and yellow signs the CA ticked;
  - the zone the guideline suggested next to the zone the CA chose, and the reason for any change;
  - earlier saves (a retake, or a lowered zone);
  - who saved each one and when;
  - which guideline version was used;
  - a triage save whose readings were not copied (no encounter yet, or the doctor changed the vitals).

So the answer to question 1 is **no**: the doctor has no read-only Triage view today.

## 2. Proposal (the smallest view)

**One read-only page, `GET visits/{visit}/triage/view`** (route `visits.triage.view`, Classic `Triage/View.vue`). It
opens from a "View triage" link beside the zone chip on the consultation page, and from the doctor's Queue board row
menu.

**Who may open it:**
- the new permission (section 3), and
- the patient's own doctor: the visit's assigned doctor, or the attending clinician once the consultation has started.
  This is the same rule as the zone chip.
- The `{visit}` binding keeps it inside the active branch (branch-scoped), with a fresh check that the doctor is
  assigned to that branch.

Another doctor, the Director and every other role get 403. The Director's existing read-only consultation review is
unchanged.

**What it shows, every save, newest first:**
- the time (branch time zone) and who saved it;
- the seven readings;
- the red flags and yellow signs ticked, in the wording of the guideline version that save used, with that version
  number;
- the zone the guideline suggested;
- the zone chosen, with the reason in words when it differs.

A heading line names the zone in force now. If there is no triage, the page says "No triage yet". No values are
filled into anything.

**View only:**
- no form, no Save, no retake, no lowering, no "tell the doctor" action;
- the page sends no lock version, idempotency key or expected-id fields, so nothing on it can be posted back;
- the existing `POST visits/{visit}/triage` stays behind `visits.triage.record.branch` (unchanged).

**Audit, like `ReadOnlyReviewAudit`:** the page records the open first (event `triage.viewed`, ids `visit` and
`patient` only, never a reading, sign or reason). Only then is the page data built. If the audit row cannot be
written, nothing is shown. This needs `triage.viewed` added to `ReadOnlyReviewAudit::EVENTS`, a shared file used by
the Dispensary and Queue & TV reviews; I will tell their Leads before touching it.

**When:**
- while the visit is open (registered, Waiting or Serving, or held);
- after completion, from the doctor's own visit history, so a doctor can look back at what triage said.

**Not in TRI-1e:** any change to the triage form, the CA's rules, the zone projection, the TV or the QR page. No new
table or migration apart from the permission grant.

## 3. Permission (for the owner to confirm)

- **`visits.triage.view.own`**, granted to `resident_doctor` only.
- "own" means the patient's own doctor, checked in the service as above.
- It is not administrative authority and it gives no recording.
- Added to `TriagePermissions::grants()` and `PermissionCatalogue`, through one additive migration in the Triage range
  (from `2026_11_20_500700`): `firstOrCreate`, give only what is missing, never revoke, `down()` a no-op.
- Existing assertions that pin the triage permission list (`TriageServiceTest`) would gain this permission. Those
  changes go to the owner first.

## 4. Questions for the owner

1. **Permission:** `visits.triage.view.own` for the Resident Doctor only? *Recommended: yes.*
2. **Which doctor:** only the patient's own doctor (assigned, or attending once started)? *Recommended: yes.* The
   alternative is any doctor at the branch, which is wider than the zone chip.
3. **When:** open visits and the doctor's own completed visits? *Recommended: yes.* The alternative is open visits
   only.
4. **Where:** a separate read-only page opened from "View triage"? *Recommended: yes.* The alternative is a drawer on
   the consultation page, which crowds the page Daniel just reviewed.
5. **Audit every open** (like the Director's review)? *Recommended: yes.* The alternative is not auditing the doctor's
   own patient, which the clinical record already allows.

## 5. Tests (planned)

- **Gating:** the own doctor (assigned, then attending) gets 200; another doctor, the CA, the CA Supervisor, the
  Director, Finance, Administrator, HR Manager and the TV account get 403. A doctor from another branch gets 404.
- **Content:** every save in order, the signs in the wording of their guideline version, suggested and chosen zones,
  and the reason; no lock or idempotency fields in the payload.
- **Audit:**
  - one `triage.viewed` row per open, holding ids only;
  - when the audit write fails, nothing is shown.
- **No writes:** every triage, visit and queue table is identical before and after an open.
- **Frontend:** a source test that the page has no form or POST, and the "View triage" link on the consultation page.

## 6. Path

Owner answers section 4, then Farhana reviews this note, then the build. Then Daniel's look at the screenshots
(1440 and 390, light and dark), then the owner's ship-it, then the PR.
