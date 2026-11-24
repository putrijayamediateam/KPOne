# DSP-26: the Resident Doctor sees the Dispensary pages, view only (design note)

Owner decision 24 Nov 2026, relayed by B.E.R.N.I.S.S (the owner's words: "pilih (a) lihat sahaja"): the Resident
Doctor may open the Dispensary pages at their active branch, **view only**. No prepare, edit, complete, print, return
or any other Dispensary action. The DSP-23 Doctor's check (Verify, Send back) stays exactly as it is.

Hakim's review: OK with changes (four must-fixes, folded in below as sections 2.1 to 2.4).

## 1. Permission

- New narrow permission **`dispensary.view_readonly.branch`**, granted to **`resident_doctor` only**. It is a view
  grant, so it never reuses `dispensary.update.branch`, `dispensary.start.branch` or any other write permission.
- I chose a new permission instead of giving the doctor `dispensary.view.branch`, for two reasons. First, that
  permission also opens the Returns to check list, together with other rights. Second, every page that reads it
  would quietly widen to the doctor. A separate name keeps the doctor's access to the pages listed here.
- The name mirrors the Director's `dispensary.view_readonly.organisation` (DSP-25), scoped to `.branch`.
- One additive migration, `2026_11_06_200900_grant_dispensary_doctor_view_permission`, in this team's range. It
  uses `firstOrCreate`, grants only what is missing, logs and skips a missing role, and `down()` changes nothing.
- It is not granted to `ca`, so the `medical_assistant` mirroring rule (B.E.R.N.I.S.S, 23 Nov) does not apply.
- Not a staff-authority permission (`AUTHORITY_OVER_PEOPLE_AND_ACCESS` is unchanged).

## 2. What the doctor sees, and where the gates change

All of it is at the doctor's **active branch** only. A case at another branch gives 404, as for a CA.

**One separate read gate (Hakim, must-fix 1).**
- A new private `viewableBranch($actor, $case)` in `DispensaryDirectoryService` accepts either the CA's existing
  check (`dispensary.view.branch` plus the CA, CA Supervisor or MA role) or `dispensary.view_readonly.branch`.
- It is used **only** by the read paths below.
- `authorizedBranch()` stays exactly as it is. So do `recordLabelPrint()`, the medicine and service searches,
  and every write method. A doctor can never reach a write path through the read gate.

### 2.1 Dispensary list (Registration board)
- `board()` and `boardCount()` accept the read gate, so the doctor sees the "Dispensary" tab and its rows.
  Each row's only action is "Open Dispensary".
- **Hakim, must-fix 3:** `VisitDirectoryService` gates its own Dispensary links separately, and all three use the
  same read check:
  - line 442: the open consultation case behind the chip, currently role plus `view.branch`;
  - line 477: `dispensaryUrl`;
  - line 500: the row action `openDispensary`.
  The doctor then sees "Open Dispensary" on Registration rows (All tab too), and nothing else changes on those rows.

### 2.2 A case (pending, dispensing, awaiting the doctor's check, returned to doctor)
- Route `dispensary.show` changes from `permission:dispensary.view.branch` to
  `permission:dispensary.view.branch,dispensary.view_readonly.branch` (any of, through `RequireAnyPermission`).
- `detail()` uses the read gate and returns the same payload. Every `can.*` is false: no edit fields, no Add medicine
  or service, no scan boxes, and no Start, Complete, Send for check, Return to doctor or Call patient. The OTC allergy
  form is hidden. The lines show read-only, as on the "waiting" view the CA already gets.
- `doctorCheck.canCheck` stays as it is (true only for a CA Supervisor here), so the case page shows the doctor no
  Verify or Send back. The doctor's own check stays on the consultation page (DSP-23).

### 2.3 A completed case (Hakim, must-fix 4)
- The same route, and the Completed page read-only: medicines, batches and the returns list.
- The returns prop comes from `DispensaryReturnService`'s case section builder through the read gate, with
  `canRecord` and `canCheck` false for the doctor. It does not call `mayCheck()`, and it does not require
  `dispensary.view.branch`.
- The Returns to check list (`returnsToCheck`, route permission `dispensary.view.branch`) stays closed to the doctor.

### 2.4 Drug label preview (Hakim, must-fix 2)
- The GET preview routes (`dispensary.labels`, `dispensary.items.label`) accept
  `dispensary.labels.print.branch,dispensary.view_readonly.branch` (any of).
- `DrugLabelService::preview()` gains a **viewer branch**. A holder of `dispensary.view_readonly.branch` without the
  print permission gets the same label projection:
  - **with a plain read** (Hakim's nice-to-have 5): no row locks, so the doctor never blocks a CA's save;
  - **with no print token** in the payload, and no print-log row.
- `recordPrint()` keeps requiring both `labels.print` and `view.branch` under lock, so both print POSTs stay 403.
- **Statuses the doctor may preview:**
  - completed;
  - awaiting the doctor's check.
  A case still being prepared (pending or dispensing) is **not** previewable by the doctor (404, as today): the CA is
  still changing the lines, and the label isn't final.
- On the case page, a **"View labels"** link is driven by a new server flag, `canPreviewLabels` (Hakim's
  nice-to-have 6). `canPrintLabels` stays false for the doctor. The label page shows no Print button when the page
  says the viewer cannot print.

Out of scope (still 403 for the doctor): Returns to check, Dispensary settings, the CA Supervisor's
"Doctor's checks" list, the scan lookup (needs `inventory.view.branch`) and every search endpoint.

## 2.5 Screen design (Daniel's note-level review: OK with changes; the full look comes on the build shots)

1. **View-only banner.**
   - The case page and the Completed page start with the shared read-only banner (the one the Director's views
     and the audit pages use): one muted line under the page header, with an eye icon and neutral tokens.
   - Wording: "View only. You cannot change this case."
   - It never says "Every view is recorded." That line is the Director's only (DSP-25), and isn't true for a doctor.
2. **No gaps or dead controls.**
   - Every action is removed, not disabled: no greyed Save, no empty action bar, and no panel left with only a
     heading.
   - A block with nothing for a viewer is removed. On the case page the right-hand column keeps its summary and
     loses the actions card, so the layout closes up.
   - Checked at 1440 (three columns) and 390 (one column).
3. **Read-only values as plain text.**
   - Fields show as plain text, as on the CA's existing "waiting" view, never as disabled-looking inputs.
   - Hidden for the viewer: Add medicine, the scan box, the services panel's add controls and the OTC allergy form.
4. **Label preview.**
   - No Print button and no printer checklist.
   - One muted line under the header: "View only. Printing is done by the CA."
   - The case page shows "View labels" only when the label can be previewed (completed, or awaiting the doctor's
     check). For a pending or dispensing case there is no link and no message.
5. **Registration board.**
   - The doctor sees the Dispensary tab.
   - "Open Dispensary" is an outline row action. At 390 it must not collide with the row's existing "Call In"
     and "Mark not ready" buttons (shots will show it).
6. **Back link** (Daniel asked what it does for a doctor):
   - The doctor's check panel on the consultation page gains a "View in Dispensary" text link while the case
     waits for the check. It opens the case page with `from=consultation`.
   - On the case page, the back link then reads "Back to consultation" and returns to that visit's consultation.
     It does so only when `from=consultation` is given and the viewer is the visit's attending clinician
     (checked on the server). Otherwise it falls back to the default.
   - In every other case, the back link goes to the Registration board's Dispensary tab, as for a CA.
   - The `from` value is checked against a fixed list (`consultation`, `registration`), so the link can't be
     pointed elsewhere.

Shots for Daniel's look, at 1440 and 390 in light and dark:
- the case page, awaiting the doctor's check;
- the case page, completed;
- the Completed page with the returns list;
- the label preview;
- the Registration row;
- the doctor's check panel with the new link.

## 3. What stays hidden

- Every write keeps its existing permission in the route middleware and in its service check, so the doctor gets
  403:
  - start, save or add or remove a line or service, medicine and service search, by-SKU lookup;
  - OTC allergy statement and records;
  - return to doctor, send for check, complete;
  - label print (both POSTs), the TV call;
  - return record and check, acknowledge partial.
- No new data leaves the server. The doctor gets the CA's existing payload with the action flags off, and the label
  preview without a print token. The doctor already sees the same patient and medicines on the consultation page.
- **Audit:** a doctor opening a page at their own branch is not recorded, the same as a CA opening it. (DSP-25
  records every open by the Director, because that view crosses branches.) If the owner wants each doctor open
  recorded, it is one audit row per page open; tell me.

## 4. Tests

- **Grant:** exactly `resident_doctor` holds `dispensary.view_readonly.branch`; the migration is additive and
  replay-safe; no other role's permissions change.
- **Registration board:**
  - the doctor gets the Dispensary tab and count, with rows whose only action is `openDispensary`;
  - on the board rows (All tab), `dispensaryUrl` and `openDispensary` are set for an open case;
  - no other row action changes.
- **Case page:** the doctor opens a case in each status, each 200, with every `can.*` false.
- **Completed page:** 200, the returns rows present, `canRecord` and `canCheck` false. The Returns to check list
  stays 403.
- **Label preview:**
  - 200 for completed and awaiting check, 404 for pending and dispensing;
  - no print token, `canPrintLabels` false and `canPreviewLabels` true;
  - no row locks taken (a CA save during a doctor preview does not wait);
  - no print-log row.
- **The doctor gets 403 on every Dispensary write route:**
  - start, items add/edit/remove, services add/edit/remove, medicine and service search, by-SKU lookup;
  - OTC allergy (both), return to doctor, send for check, complete;
  - label print (both POSTs), the TV call;
  - return record, return check, acknowledge.
  A data test shows that no row changes and no print-log row is written.
- **Other:**
  - a case at another branch gives 404;
  - the DSP-23 tests stay green (the doctor's Verify and Send back from the consultation page);
  - `authorizedBranch()` callers still refuse the doctor (for example `recordLabelPrint()` called directly).
- **Back link:** "Back to consultation" only for the attending clinician with `from=consultation`, otherwise the
  Registration Dispensary tab; an unknown `from` value falls back.
- **Frontend:**
  - the read-only banner wording, without "Every view is recorded.";
  - the label line "View only. Printing is done by the CA.";
  - the check panel's "View in Dispensary" link;
  - the case and label pages hide every action for a viewer;
  - "View labels" follows `canPreviewLabels`;
  - the Print button follows `canPrintLabels`;
  - both flags are pinned.

## 5. Existing assertions that change (need the owner's approval)

- **Approved by the owner (24 Nov).** `tests/Feature/Clinical/DrugLabelTest.php:54` lists `resident_doctor` among the roles that get 403 on the label
  preview (`GET dispensary.labels`). With DSP-26 the doctor gets 200 there for a completed case, so `resident_doctor`
  comes off that list. Every other role stays 403, and a new test pins the doctor's view-only preview (no print
  button, no token, POST still 403).
- Scanned `DispensaryReviewTest`, `DispensaryDoctorCheckTest`, `PharmacySpaceTest`, `RoleOneRolesTest` and the
  OTC route tests. No other assertion pins "the doctor cannot open a Dispensary page". The write-route 403
  assertions (for example `TreatmentPlanTest` lines 1715 to 1716 and 1918 to 1919) stay as they are.
- If the build finds any other such assertion, I'll stop and send it before changing it.

## 6. Questions for the owner

Owner's answers, 24 Nov 2026, relayed by B.E.R.N.I.S.S ("approve the test change, 2 no, 3 no"): the
`DrugLabelTest.php:54` change is approved; (a) no; (b) no. Question (c), from the revised note: "(c) no, ship it for
DSP-26 for aisyah".

a. Should each doctor open be recorded in the audit log? **Owner: no.** No audit row per doctor open.
b. Should the doctor also see the "Returns to check" list? **Owner: no.**
c. Label preview while the CA is still preparing (pending or dispensing)? Recommended: no, only completed and awaiting
   the doctor's check, because until then the label isn't final. **Owner: no.** Only completed and awaiting the check.
