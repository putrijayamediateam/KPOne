# Dispensary & OTC builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is
built, what the owner decided, the rules, and what to build next. The team reads it before every task and
updates it in the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Aisyah** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Hakim** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |

Both are Claude builder sessions working for the owner through the coordinator session. Names chosen by the
coordinator at the owner's request (2026-11-05).

## 2. What this area covers

**In scope (the team builds and maintains):**
- Dispensary cases for consultation visits: the handoff from the doctor's Treatment Plan, the CA's
  edits (edit, add, remove, restore medicines and services), CA verification and Complete.
- OTC (medicine-only) visits: Dispense from Registration, the CA's allergy statement, the CA-built
  medicine list.
- The OTC waiting list (B numbers), Call to Dispensary, and the dispensary call on the TV.
- QR "Buy medicine only" acceptance into an OTC visit.
- Drug labels (live printing from the CA-final lines).
- The Dispensary pages: `Dispensary/Show.vue`, `Dispensary/Completed.vue`, `Dispensary/Labels.vue`, the
  Registration board's Dispense and Open Dispensary actions, and the doctor's Dispensary attention panel.

**Shared with other teams (change only through a small agreed change):**
- Stock eligibility, batch holds, FEFO and stock debits: **Inventory** team (`InventoryBatchEligibility`,
  `InventoryMovementService`).
- Invoices built from the dispensed lines: **Billing** team (`BillingSourceService`, `BillingBuilderService`).
- Treatment Plan and Send to Dispensary: **Consultation** team.
- Queue numbers, the TV feed and call cooldowns: **Queue & TV** team.
- Permissions catalogue: changed only by an owner-approved migration.

## 3. Where the code lives

| Kind | Files |
|---|---|
| Domain services | `app/Domain/Clinical/Dispensary/Services/` — `DispensaryService`, `OtcDispensaryService`, `DispensaryHandoffService`, `DispensaryDirectoryService`, `DispensarySafetyValidator`, `DispensaryAuthorityService`, `DoctorDispensaryAttentionService` |
| Models | `app/Domain/Clinical/Dispensary/Models/` — case, handoff, item, item batch allocation, item exception, service line |
| Labels | `app/Domain/Clinical/Dispensary/Labels/` — `DrugLabelService`, `DrugLabelLayout` |
| OTC queue | `app/Domain/Queue/Services/OtcQueueService.php`, `app/Domain/Queue/Models/OtcQueueEntry.php` |
| Controllers | `DispensaryController`, `RoomCallController::dispensary` |
| Pages | `resources/js/pages/Dispensary/*.vue`, `Clinical/Partials/DispensaryAttentionPanel.vue` |
| Routes | `routes/web.php`, names `dispensary.*`, `visits.dispense` |
| Design record | `docs/DS-01_DISPENSE_EDIT_AND_OTC.md` |
| Tests | `OtcDispensaryTest`, `OtcDispensaryRoutesTest`, `OtcBillingTest`, `OtcQueueTest`, `DispensaryRouteBindingTest`, `PostgresDispensaryInventoryRegressionTest`, the dispensary cases in `TreatmentPlanTest`; frontend `ds-01a-*`, `ds-01b-3-*`, `ds-01c-*`, `ds-01d-*`, `ds-02b-*`, `ds-02c-*`, `drug-labels` |

## 4. Permissions (who can do what today)

| Permission | Holders | Use |
|---|---|---|
| `dispensary.view.branch` | CA, CA Supervisor | Open a dispensary case |
| `dispensary.start.branch` | CA, CA Supervisor | Start dispensing, Call to Dispensary |
| `dispensary.update.branch` | CA, CA Supervisor | Edit, add, remove lines; OTC allergy statement |
| `dispensary.complete.branch` | CA, CA Supervisor | Complete the case |
| `dispensary.return_to_doctor.branch` | CA, CA Supervisor | Return the case to the doctor |
| `dispensary.otc.create.branch` | CA, CA Supervisor | Open an OTC case from Registration |
| `dispensary.labels.print.branch` | CA, CA Supervisor | Print drug labels |
| `dispensary.acknowledge_partial.own` | Resident Doctor | Acknowledge legacy partial lines |
| `treatment_plans.send_to_dispensary.own` | Resident Doctor | Send the plan to Dispensary |

`app/Domain/Access/PermissionCatalogue.php` is the source of truth; check it before relying on this table.

## 5. What is built (history)

| Slice | What it did | PR |
|---|---|---|
| 3A-Core | Version-bound doctor-to-Dispensary handoff, branch fulfilment, safe stock allocation, immutable movements | #5 |
| DS-01a | CA edits, adds, removes medicines; doctor's original kept beside "Edited by CA" / "Added by CA"; Complete is the CA's verification; no CA allergy tick (in part superseded by DSP-1) | #77 |
| DS-01a-services | CA edits, adds, removes and confirms services; billing uses the CA-confirmed lines | #78 |
| DS-01b-1/2/3 | OTC dispensary case with the CA's allergy statement, OTC billing anchor, Dispense from Registration | #80, #81, #82 |
| DS-01c | Invoices page shows ordered beside dispensed | #83 |
| DS-01d | Dispensary page in the three-column Invoices layout | #89 |
| DS-02a/b/c | OTC waiting list with B numbers and Call to Dispensary; call by number or full name; QR "Buy medicine only" | #85, #86, #87 |
| NAV-02 | Refused Dispensary actions stay on their page | #74 |
| DOC-03 | Live drug labels from saved CA-final lines, 100 × 150 mm, blocked when over budget, no stored label | #139 |
| STK-2 | One batch eligibility rule at Save, Complete and pickers; controlled FEFO exception reasons | #182 |
| STK-1 | Dispensing refused when a SKU's dispensing unit differs from its stock unit | #180 |
| FLOW-FIXES-1 | Call to Dispensary keeps the Registration filter; Open Dispensary on the All tab | #201 |
| DARK-1 | OTC allergy panel readable in dark mode | #207 |
| DSP-1 | Allergy warning: a CA add, edit or put-back of a medicine that matches a patient's active allergy record by text shows a warning and needs "I have checked with the patient/doctor"; enforced in `DispensaryService`; append-only `dispensary_allergy_confirmations` and a text-free audit; text matching can miss and the screen says so | #214 (merged `bf4126a`) |
| DSP-2 | Drug label prints "No. Batch" and "Tarikh luput" when exactly one batch was allocated to the line; otherwise nothing extra and the preview says why; batch number within the 48-character label budget or the label is blocked; read only | #218 (merged `dad3b49`) |
| DSP-3 | Controlled-medicine flag on the Medicine Catalogue (mark or unmark, confirmed, stale change refused, audited with field and value only) and a bold "Ubat Terkawal / Controlled Medicine" line under the medicine name on the drug label | #223 (merged `975b93b`) |
| DSP-4 | "OTC (no doctor)" row, always last, in the Insights Today and Sales Provider tables when no doctor is selected; the rows now add up to headline sales; nothing else changes | #227 (merged `3c74daa`) |
| DSP-1b | Catalogue editors tag medicines with drug groups ("Drug groups" on the Medicine Catalogue; up to 10, stale change refused, audited by count); the Dispensary allergy warning also matches allergies against those groups | pending (branch `feature/dsp-1b-allergy-drug-groups`) |

## 6. Owner decisions already made (never ask again)

1. The doctor's original order is kept unchanged; every CA change is audited (2026-10-09).
2. No CA allergy tick: the doctor's allergy confirmation stands and the allergy list stays visible (2026-10-09).
   *Superseded in part by item 11 (2026-11-05): the doctor's confirmation still stands, but a CA add or edit
   that matches a recorded allergy by text now needs the CA's recorded tick.*
3. A CA removing or reducing a line needs no doctor acknowledgement (2026-10-09).
4. The CA, not the doctor, confirms services at Dispensary (2026-10-09).
5. The completed-visit page is called **Invoices** (2026-10-09).
6. OTC has its own B-number series; numbers never reused the same day; restart at 001 daily (2026-10-09).
7. Per-branch setting to call by number (default) or full registered name; name mode is a privacy risk to
   review before go-live (2026-10-09, 2026-10-26).
8. QR offers "Jumpa doktor" or "Beli ubat sahaja" (2026-10-09).
9. Drug label size 100 × 150 mm confirmed; a real printer test is still outstanding (2026-10-26, 2026-10-30).
10. During the pilot the CA Supervisor accepts the label and direction-limit policy (2026-10-30).
11. Allergy match when a CA adds or edits a medicine: warn, and the CA must confirm to save; recorded; no
    block (2026-11-05).
12. The controlled-medicine flag is set by Medicine Catalogue editors (Director, CA Supervisor), audited
    (2026-11-05). Administrator, which also holds `medicines.manage.organisation` (ADM-01), keeps it too
    (owner decision 2026-11-05).
13. DSP-4 scope is only the OTC row in the Insights Provider tables, labelled "OTC (no doctor)", always last,
    shown only without a doctor filter; waiting/serving times and an Insights visit-type filter are out of
    scope (2026-11-05).
14. DSP-1b: better allergy matching through **drug groups that catalogue editors tag on each medicine**
    (`medicines.manage.organisation`); KPOne ships no clinical drug-group list. The OTC allergy step stays
    yes/no for now (no typed allergy) (2026-11-05).
15. A "ship it" or other owner decision relayed by the coordinator (LUCY) counts as the owner's own; confirmed
    by the owner in this team's session and in LUCY's (2026-11-05). Unchanged: never merge, never edit
    BASELINE, and stop and ask if a relayed message conflicts with something the owner said directly.

## 7. Open items and known limits

- **Controlled-medicine flag (DSP-3):** Director, CA Supervisor and Administrator set it (decision 12). The flag is read live: reprints of
  past dispenses follow the current flag. It adds one bold line, which counts toward the line limit, so a
  controlled medicine with long directions may now be blocked (include one in the printer test). Medicines have
  no `lock_version`; the change uses an expected-value guard on the one boolean instead (a quick A→B→A
  sequence is not detected; every change is audited).
- **Real printer and sticker test** for drug labels at the pilot branch (owner to do by hand).
- **Pharmacist review**: the 384 and 192 character direction limits, and the controlled-medicine marker wording
  "Ubat Terkawal / Controlled Medicine" (DSP-3). Yezza also prints the indication; KPOne still omits it. Which
  medicines are controlled is set by catalogue editors; KPOne does not decide it.
- **Labels:** batch and expiry print only for a line with exactly one allocated batch (DSP-2); a line split
  across batches, or a batch with the old `9999-12-31` placeholder expiry, prints neither. No barcode, no label
  history (STK-9 planned, not built). The two or three extra lines count toward the 35-line limit (33 with a
  logo), so a label with long directions may now be blocked; include one in the real printer test. A batch
  number over 48 characters blocks the label. "No. Batch" / "Tarikh luput" wording joins the pharmacist review.
- **Allergy matching is text only (DSP-1, DSP-1b):** a CA add or edit that matches an active allergy record
  needs a recorded confirmation. Drug groups are matched only when a catalogue editor has tagged the medicine
  (DSP-1b), so an untagged medicine still misses penicillin vs amoxicillin; brand names and misspellings are
  still missed, and allergy text with extra words not split by a comma may miss a name with a strength. It is a
  warning, not a block (owner decision 11); the visible allergy list stays the main safeguard. Simple wrapper
  words ("allergic to", "allergy", "alahan") are ignored, so "Allergic to aspirin" matches "Aspirin 100mg".
  The OTC allergy statement (none / has an allergy) is not text and is not matched. The doctor's Treatment
  Plan has no such check. A very broad drug group name (for example "Tablets") causes false alarms, so
  editors should use drug-class names only.
- **Insights:** OTC sales show as one "OTC (no doctor)" Provider row (DSP-4). Waiting and serving times still
  read only the consultation queue, so OTC visits are absent from them; Insights has no visit-type filter.
- **Name mode on the TV** shows full names to the waiting room; privacy review before go-live.
- **SMOKE-1** findings touching this area: none open (S1-007 fixed in DARK-1).

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only.
- Every state change: backend permission and branch scope, `DB::transaction`, row locks, `lock_version`,
  idempotency where retried, and a structural audit row with **no medicine or clinical text**.
- Never weaken a safety check, the allergy display, stock eligibility or the label budgets without an
  explicit owner decision recorded in section 6.
- Do not change any existing test assertion without the owner's approval.
- Full gate before hand-off, on a task-owned PostgreSQL 18 database: backend tests, Pint, frontend types,
  lint, format, build, frontend tests, `migrate:fresh --seed`, `git diff --check`; screenshots at 1440 and
  390 px, light and dark, for any screen change.
- Commit, push and PR only after the owner says "ship it" (directly, or relayed by the coordinator; decision
  15); never merge.
- **Do not edit `docs/BASELINE.md`** in a new PR or in unpushed work (owner rule 2026-11-05: parallel team rows
  made every PR conflict). Record each finished piece only in this file (section 5 with the PR number, section 6
  for new owner decisions). If a piece changes an authorisation boundary (for example a BASELINE section 4
  "not authorised" item), say so clearly in the PR description; the coordinator records all BASELINE updates in
  one docs-only PR after each batch of merges.
- Record every new owner decision in section 6 and every finished piece in section 5, in the same PR.

## 9. Backlog (next pieces, in order)

Chosen by the owner on 2026-11-05; built one by one in this order, each its own PR. Choosing them is the owner's
authorisation to build them; the coordinator records it in `docs/BASELINE.md`.

1. **DSP-1 Allergy warning — merged in #214.** When a CA adds or edits a medicine (consultation or OTC), warn if it matches a
   recorded active allergy for the patient and require the CA to confirm before saving; the confirmation is
   recorded. Allergies are stored as free text (`patient_allergy_records.allergen_text`), so matching is by
   text against the medicine's name and generic name and can miss; the screen must say so, and the visible
   allergy list stays. **Owner decision 2026-11-05: warn, and the CA must confirm** ("I have checked with the
   patient/doctor") to save; the confirmation is recorded with who and when; no block.
2. **DSP-2 Batch and expiry on the label — merged in #218.** Print the batch number and expiry date when exactly one batch was
   allocated to the line; otherwise print nothing extra and say why on screen. Shared with the Inventory team.
   Lifts the "batch and expiry printing" item from the not-authorised list for this case only.
3. **DSP-3 Controlled medicine marker — merged in #223.** A "controlled medicine" flag on the medicine catalogue (none exists
   today) and the marker on the drug label. Wording "Ubat Terkawal / Controlled Medicine" to confirm with a
   pharmacist; changes a shared catalogue screen. **Owner decision 2026-11-05: whoever can already edit the
   Medicine Catalogue (`medicines.manage.organisation`: Director, CA Supervisor) sets the flag; every change
   audited.**
4. **DSP-4 OTC in doctor reports — merged in #227.** Include OTC sales where Insights and the daily report count only
   consultation invoices today. Shared with the Reports team; numbers in existing reports must not change
   unexpectedly, so each changed figure is listed for the owner first.
5. **DSP-1b Better allergy matching — built 2026-11-05, awaiting owner review (branch
   `feature/dsp-1b-allergy-drug-groups`).** Drug groups tagged on each medicine by catalogue editors, matched
   by the Dispensary allergy warning (decision 14).
