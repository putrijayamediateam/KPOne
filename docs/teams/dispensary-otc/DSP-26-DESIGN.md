# DSP-26: the Resident Doctor sees the Dispensary pages, view only (design note)

Owner decision 24 Nov 2026, relayed by B.E.R.N.I.S.S (the owner's words: "pilih (a) lihat sahaja"): the Resident
Doctor may open the Dispensary pages at their active branch, **view only**. No prepare, edit, complete, print, return
or any other Dispensary action. The DSP-23 Doctor's check (Verify, Send back) stays exactly as it is.

## 1. Permission

- New narrow permission **`dispensary.view_readonly.branch`**, granted to **`resident_doctor` only**. It is a view
  grant, so it never reuses `dispensary.update.branch`, `dispensary.start.branch` or any other write permission.
- I chose a new permission instead of giving the doctor `dispensary.view.branch`, for two reasons. First, that
  permission also opens the Returns to check list, together with other rights. Second, every page that reads it
  would quietly widen to the doctor. A separate name keeps the doctor's access to the pages listed here, and lets
  the label preview accept a viewer without its print permission.
- The name mirrors the Director's `dispensary.view_readonly.organisation` (DSP-25), scoped to `.branch`.
- One additive migration, `2026_11_06_200900_grant_dispensary_doctor_view_permission`, in this team's range. It
  uses `firstOrCreate`, grants only what is missing, logs and skips a missing role, and `down()` changes nothing.
- It is not granted to `ca`, so the `medical_assistant` mirroring rule (B.E.R.N.I.S.S, 23 Nov) does not apply.
- Not a staff-authority permission (`AUTHORITY_OVER_PEOPLE_AND_ACCESS` is unchanged).

## 2. What the doctor sees

All of it is at the doctor's **active branch** only. A case at another branch gives 404, as for a CA.

| Page | How the doctor reaches it | What changes for the doctor |
|---|---|---|
| Dispensary list | Registration board, "Dispensary" tab (`board_status=dispensary`); the doctor already opens Registration with `visits.view.branch` | `board()` and `boardCount()` accept the new permission besides the CA, CA Supervisor and MA roles. The rows are the same as the CA's, with every row action off except "Open Dispensary" |
| A case (pending, dispensing, awaiting the doctor's check, returned to doctor) | Open Dispensary, or `/dispensary/{case}` | Same page and same `detail()` payload. Every `can.*` is false, so no edit fields, no Add medicine or service, no scan boxes, no Start, Complete, Send for check or Return to doctor buttons, no Call patient, and no OTC allergy form. The lines show read-only, as on the "waiting" view the CA already gets |
| A completed case | `/dispensary/{case}` | The Completed page read-only: medicines, batches and the returns list. The Record return and Check return forms are hidden |
| Drug label preview | A "View labels" link on the case page (only for a case the CA could print) | The label preview pages (`GET …/labels` and `…/items/{item}/label`) render for the doctor. **No "Print current labels" button**, and no print-log row: the print POST routes stay CA-only |

Out of scope (still 403 for the doctor): Returns to check, Dispensary settings, the CA Supervisor's
"Doctor's checks" list, and any search endpoint.

## 3. What stays hidden

- Every write: start, save or add or remove a line or service, medicine and service search, scan-to-add lookup,
  OTC allergy statement and records, return to doctor, send for check, complete, label print (POST), the TV call,
  return record and check, acknowledge partial. Each stays behind its existing permission, so the doctor gets 403.
- No new data leaves the server. The doctor gets the CA's existing payload with the action flags off. The doctor
  already sees the same patient and medicines on the consultation page.
- **Audit:** a doctor opening a page at their own branch is not recorded, the same as a CA opening it. (DSP-25
  records every open by the Director, because that view crosses branches.) If the owner wants each doctor open
  recorded, it is one audit row per page open; tell me.

## 4. Tests

- Grant: exactly `resident_doctor` holds `dispensary.view_readonly.branch`; the migration is additive and
  replay-safe; no other role's permissions change.
- The doctor opens the Dispensary tab and gets the rows, each with `openDispensary` only. The doctor opens a case in
  each status, a completed case and the label preview, each 200, with every `can.*` false and no print button.
- **The doctor gets 403 on every Dispensary write route**: start, items add/edit/remove, services
  add/edit/remove, medicine and service search, by-sku lookup, OTC allergy (both), return to doctor, send for
  check, complete, label print (both POSTs), the TV call, return record, return check and acknowledge. A data test
  shows that no row changes and no print-log row is written.
- A case at another branch gives 404. The doctor's own Verify and Send back still work (the DSP-23 tests stay green).
- Frontend: the case and label pages hide every action for a viewer. The label page shows no Print button when the
  page says the viewer cannot print.

## 5. Existing assertions that change (need the owner's approval)

- `tests/Feature/Clinical/DrugLabelTest.php:54` lists `resident_doctor` among the roles that get 403 on the label
  preview (`GET dispensary.labels`). With DSP-26 the doctor gets 200 there, so `resident_doctor` comes off that list.
  Every other role stays 403, and a new test pins the doctor's view-only preview (no print button, POST still 403).
- If the build finds any other assertion pinning "the doctor cannot open a Dispensary page", I'll stop and send it
  before changing it. Write-route 403 assertions (for example `TreatmentPlanTest` lines 1715 to 1716 and 1918 to
  1919) stay as they are.

## 6. Questions for the owner

a. Should each doctor open be recorded in the audit log? Recommended: no, as for CA opens at their own branch.
b. Should the doctor also see the "Returns to check" list? Recommended: no, not asked for.
