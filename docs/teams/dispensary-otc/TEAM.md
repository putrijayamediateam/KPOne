# Dispensary & OTC builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is
built, what the owner decided, the rules, and what to build next. The team reads it before every task and
updates it in the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Aisyah** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Hakim** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |
| UI/UX designer | **Iman** | Sketches each screen change before it is built and checks the screenshots before hand-off; part of the Design team under the Lead UI/UX Designer, Daniel ([Design TEAM.md](../design/TEAM.md)) |

All three are Claude sessions working for the owner through the coordinator session. Names chosen by the
coordinator at the owner's request (2026-11-05); the designer was added by owner decision the same day.

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
| `dispensary.labels.print.branch` | CA, CA Supervisor | Print drug labels; each print is logged per line (DSP-6) |
| `allergies.record_otc.branch` | CA, CA Supervisor | Add (never edit or remove) an allergy for the patient of an OTC case they handle (DSP-5, decision 18) |
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
| DSP-1b | Catalogue editors tag medicines with drug groups ("Drug groups" on the Medicine Catalogue; up to 10, stale change refused, audited by count); the Dispensary allergy warning also matches allergies against those groups | #234 (merged `3a876b9`) |
| DSP-9 | Pharmacist review pack (documents only): a printer-test checklist with worst-case labels and a pharmacist review sheet with sample labels made by the real label layout, under `docs/pilot/drug-label/` | #243 (merged `16fa1ee`) |
| DSP-5 | OTC allergy details: when an OTC walk-in has an allergy, the CA records it on the patient's allergy profile (add only), so the DSP-1 warning matches it; "has an allergy" needs a recorded allergy; a new allergy reopens the statement; Complete refuses a line that matches an allergy recorded after the line was saved until it is ticked and saved again; new permission `allergies.record_otc.branch` (migration `2026_11_06_200000`); Clinical allergy service change agreed with the coordinator | #250 (merged `bb604bc`) |
| DSP-6 | Label print log: each press of "Print current labels" that passes the fresh check logs who printed which line's label and when (append-only `dispensary_label_prints`, per-line print number, audit with counts only, no label content); a line printed before carries a small boxed REPRINT at the end of the last row (outside the line budget) and the preview says so; label pages throttled 30 a minute; migration `2026_11_06_200100` | #261 (merged `0df2ed8`) |
| DSP-7 | Medicine returns after dispensing: record (CA, CA Supervisor; quantity, fixed reason, encrypted note for Other, condition; no stock change) and check (a CA Supervisor who did not record it: Dispose, Quarantine to a patient returns location through STK-11's `dispensary_return` movement, or Refuse with a fixed reason); controlled medicines never quarantined; append-only `dispensary_returns` and `dispensary_return_checks` (migrations `2026_11_06_200200`, `2026_11_07_000500`); Completed page gets PageHeader and a Medicine returns section | #288 (merged `88c2ae1`) |
| DSP-8 | Dispensary pages on phones and tablets: at 768 px the medicine and service line grids (2 columns there) had rows spanning 3 or 4 columns, so the browser added near-zero-width columns and Quantity, Dosage, Route and Precaution became 18 px slivers with overlapping labels; rows now span the full width (or 2, then 3 on wide screens). On phones (below 640 px) every button, text field and dropdown on the Dispensary, Completed and label preview pages is at least 44 px tall; wide screens unchanged. Also the "1 of these labels was printed before" wording, and the OTC allergy card now says the check also uses catalogue drug groups | #273 (merged `b078be4`) |
| DD-24 to DD-27 | Design debt from DSP-8: 44 px controls below 1024 px too (tablets); the case page uses PageHeader "Dispensary" and only the patient card names the patient (h2, labelled KP number, Visit, Doctor); batch options lead with "N unit on hand · exp 30 Oct 2027" plus a "Selected:" line under the dropdown; the Added/Edited by CA chips are `StatusBadge` accent | #308 (`4c95234`) |
| DSP-6b | The label print check that logs a print is now a POST (`dispensary.labels.print`, `dispensary.items.label.print`, the page's address plus `/print`, token and branch in the body, CSRF header); the label page no longer accepts `?verify=1`. New tests: a consultation-case print and reprint, and a PostgreSQL race of two CAs printing one case at once (print numbers 1 and 2) | #317 |
| DSP-1c | Other names for allergy matching: catalogue editors add up to 10 brand names or misspellings per medicine ("Other names" in the Medicine Catalogue "…" menu); the Dispensary allergy warning compares allergies with them too, through one `MedicineCatalogueItem::allergyMatchTerms()` list; never printed; audited by count | pending (branch `feature/dsp-1c-allergy-aliases`) |
| DSP-10 | The clinic's landscape drug label: 100 × 62 mm (provisional), Courier New 9 pt, 48 cells × at most 13 rows inside a rounded border. Clinic name (bold capitals, 40 characters), address, bold "Tel: (phone)" and a rule; patient name only; medicine display name with "QTY: n" (no unit); directions as one capitalised "dosage, frequency, instruction" block (3 rows); Precaution, Duration and the catalogue Indication (72 characters); date and "EXP: dd/mm/yyyy" (or "EXP: -") on the bottom row. No route, batch number, logo, email, patient or visit number. "Ubat Terkawal/Controlled Medicine" below the border; REPRINT top right inside it. Over a limit still blocks, never cuts | pending (branch `feature/dsp-10-landscape-label`) |

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
    yes/no for now (no typed allergy) (2026-11-05). *The OTC part is replaced by decision 16.*
15. A "ship it" or other owner decision relayed by the coordinator (B.E.R.N.I.S.S, formerly LUCY) counts as the owner's own; confirmed
    by the owner in this team's session and in the coordinator's (2026-11-05). Unchanged: never merge, never edit
    BASELINE, and stop and ask if a relayed message conflicts with something the owner said directly.
16. Next backlog (owner choice 2026-11-05, relayed by the coordinator and confirmed by the owner in this team's
    session): DSP-5 OTC allergy details, DSP-6 label print log, DSP-7 medicine returns after dispensing,
    DSP-8 Dispensary pages on phones and tablets, each its own PR. This **replaces the OTC part of decision 14**:
    when an OTC patient says they have an allergy, the CA now records what it is. The pharmacist review pack,
    started earlier the same day, ships first as DSP-9.
17. Every builder team has a UI/UX designer under the Lead UI/UX Designer, Daniel (Design team); this team's is
    **Iman**, who sketches screen changes before building and checks screenshots before hand-off (section 8)
    (2026-11-05, relayed by the coordinator).
18. DSP-5: CA and CA Supervisor may **add** (never edit or remove) an allergy for the patient of an OTC case they
    handle, through the new permission `allergies.record_otc.branch` (2026-11-05). Editing and entered-in-error stay
    with the doctor. The owner approved set-up-only lines in two existing tests (`OtcDispensaryTest`,
    `OtcDispensaryRoutesTest`) that now record an allergy before confirming "has an allergy"; no assertion changed.
19. DSP-6: a press of "Print current labels" that passes the fresh check counts as a print (KPOne cannot see the
    printer, so a cancelled dialog counts). The mark reads plain **REPRINT** (2026-11-05). The owner approved one
    changed assertion in `DrugLabelTest` (a completed-case print may add exactly its print rows and one audit row;
    every other table must stay identical) and a set-up-only change to the `drug-labels.test.mjs` harness.
20. DSP-7 medicine returns (2026-11-05, "go with your recommendations", relayed by B.E.R.N.I.S.S): **Dispose** is
    the default outcome; quarantine goes to a separate non-dispensing **returns location** (Option B, never an STK-2
    hold on the whole batch); v1 **records the return only**, with no refund in KPOne. **Who records:** reuse
    `dispensary.update.branch` (CA, CA Supervisor), no new permission; the control is the independent check
    (`inventory.variances.approve.branch`, never the recorder). **Controlled medicines:** the checker must be a CA
    Supervisor or Director (not a Finance Officer) and the outcome is always Dispose, enforced in the domain and in
    PostgreSQL; added to the pharmacist review list. The Inventory piece is **STK-11** (Inventory team: the
    `dispensary_returns` location type, the `dispensary_return` movement and the `recordDispensaryReturn` writer,
    migration `2026_11_07_000400`); DSP-7 migrations that depend on it use `2026_11_07_000500` to `000599` (a one-off
    exception to this team's range). Iman sketches the return section first and it goes to Daniel; the DSP-7 PR
    merges only after STK-11.
21. DSP-7 details (2026-11-05). Relayed by B.E.R.N.I.S.S, "go with recommendations for the DSP-7 questions":
    refusing a return uses a fixed list (Wrong case or patient, Wrong medicine line, Wrong quantity, Recorded twice,
    Other with a short note); a controlled-medicine return recorded by mistake may be **Refused**, otherwise it is
    always Disposed, never quarantined; recording has no confirm step; checkers find waiting returns on the case page
    only in v1 (a branch list is DSP-7b); the "Other" notes are shown to anyone who can open the case and never go
    into audit, reports or prints (hint: no diagnosis or identity details). In this team's session: **only a CA
    Supervisor checks in v1** (`inventory.variances.approve.branch` plus the CA Supervisor role, never the
    recorder), because only CAs and CA Supervisors can open a Dispensary case page; nobody gains page access, so a
    return recorded by a CA Supervisor needs a second CA Supervisor. Daniel's design verdict "OK with changes":
    the "Controlled" outline badge, PageHeader and `max-w-5xl` on the Completed page, a confirm per outcome
    (Dispose destructive with the amount), StatusBadge tones (waiting_check warning, outcomes neutral), warning
    tokens for the "No refund in KPOne" note, and the Record button hidden while its form is open.
22. DD-24 to DD-27 (2026-11-06): the owner chose these over DSP-7b ("go with the design debt fixes for aisyah", relayed by
    B.E.R.N.I.S.S in the owner's words); DSP-7b stays parked as an access decision. The owner approved changing the
    pinned DSP-8 strings in `dsp-8-dispensary-phone-tablet.test.mjs` from `max-sm:` / 639.98px to `max-lg:` /
    1023.98px (in this team's session). Daniel: title "Dispensary" with the patient as an h2 in the card; quantity
    without trailing zeros and the expiry as "30 Oct 2027"; the Selected line at every width; StatusBadge accent.
    The same pink chip on the Invoices page is DD-33 (Billing's).
23. DSP-6b (2026-11-06): the owner chose items 1 and 2 of the follow-ups ("go with 1 and 2"): the print check becomes
    a POST, and the two missing DSP-6 tests (consultation case, two prints at once). The owner approved changing the
    existing tests that sent the check as a GET (`drug-labels.test.mjs` line 33, `TreatmentPlanTest` line 391, and the
    `getJson(...?verify=1...)` calls in `DrugLabelTest` and `DrugLabelPrintLogTest`) to the POST; every expected
    result is unchanged. No screen change, so no design review.
25. DSP-1c allergy other names (2026-11-06): the owner chose it ("go with allergy aliases"). Same editors, limits and
    stale-list guard as drug groups (decision 14): `medicines.manage.organisation`, up to 10, 3 to 60 characters,
    audited by count only; used for matching only and never printed. Iman's sketch: its own "Other names" menu entry
    and dialog. The Dispensary warning wording now names "catalogue other names". Daniel: OK; keep the label
    "Other names"; the Drug groups dialog losing typed groups on an error is design debt DD-37 (a later small piece). (Decision 24 is
    the "Print label" fix, on its own branch; decision 26 is DD-37, on its own branch.)
27. DSP-10 landscape label (2026-11-06): the owner chose the recommendations ("go with your recommendations for the
    label", then "start building DSP-10 now"): provisional 100 × 62 mm in one constant; directions as one line
    "dosage, frequency, instruction"; route not printed; capitals for print only; medicine display name and QTY;
    "EXP: -" unless exactly one batch with a real expiry; no batch number; REPRINT top right inside the border;
    indication from the catalogue's `default_indication` (pharmacist review recommended). Approved by
    B.E.R.N.I.S.S for the owner: QTY with no unit; phone only (no email); medicine name budget 72 (2 rows) and
    clinic name 40; the controlled wording "Ubat Terkawal/Controlled Medicine" with no spaces; dates stay
    dd/mm/yyyy; the label test changes in principle (the exact list goes to the owner before "ship it"). Daniel: OK;
    9 pt; always keep the controlled strip; Print button at least 44 px at 390 px; keep the "(provisional)" size line
    until the real sticker test; flag "QTY: n" for the pharmacist. Adlina (Catalogue) adds a 72-character hint under
    the catalogue Indication field.
    CAT-9's optional second clinic phone stays off the label (approved by B.E.R.N.I.S.S for the owner, 2026-11-06):
    one "Tel: (phone)" row only; adding it later is a small change after CAT-9.

## 7. Open items and known limits

- **Controlled-medicine flag (DSP-3):** Director, CA Supervisor and Administrator set it (decision 12). The flag is read live: reprints of
  past dispenses follow the current flag. Since DSP-10 its line prints below the border and uses none of the 13 rows. Medicines have
  no `lock_version`; the change uses an expected-value guard on the one boolean instead (a quick A→B→A
  sequence is not detected; every change is audited).
- **Real printer and sticker test** for drug labels at the pilot branch (owner to do by hand; checklist in
  [printer-test.md](../../pilot/drug-label/printer-test.md)).
- **Pharmacist review**: the DSP-10 limits (directions 3 rows, instruction 144, precaution 72, indication 72),
  "QTY: n" with no unit, the indication at medicine level, "EXP: -" for more than one batch, no route or batch
  number, and the marker wording "Ubat Terkawal/Controlled Medicine" (DSP-3, DSP-10). Which
  medicines are controlled is set by catalogue editors; KPOne does not decide it. Questions and sample labels
  for the pharmacist: [pharmacist-review.md](../../pilot/drug-label/pharmacist-review.md) (DSP-9).
- **Labels (DSP-10):** 100 × 62 mm is provisional until the real sticker test; a different size changes every
  limit. The expiry prints only for a line with exactly one allocated batch with a real expiry; otherwise "EXP: -".
  No batch number, barcode or label snapshot; prints are logged per line (DSP-6). The 13-row limit is tighter than
  the old 35, so a long clinic header plus long directions is blocked sooner. The phone prints as Business details
  stores it (international form, for example "+60321234567"). The CA and CA Supervisor guides in
  `docs/guides/ms/` still describe the controlled line under the medicine name (Staff Experience told).
- **Label print log (DSP-6):** a print is logged when the fresh check passes, so a cancelled dialog, or a page that
  goes stale during the check, still counts. Printing from the browser's own menu or Ctrl+P is not logged and does
  not add the mark (the printer test says to use the button). Since DSP-10 the REPRINT mark sits top right inside the border, in the 8 cells the clinic name
  never uses (a test pins it). The fresh check writes a print row and an audit row, so it is a POST since DSP-6b (throttled, same-origin, CSRF-checked). A consultation-case print and two prints of the same case at the same moment are tested (DSP-6b).
- **Medicine returns (DSP-7):** no refund in KPOne; only a CA Supervisor checks in v1, so a return a CA
  Supervisor records needs a second CA Supervisor on shift; checkers see waiting returns only on the case page
  (DSP-7b adds a branch list). A quarantine credits one batch, so a return larger than any single batch of the
  line cannot be quarantined (dispose it, or refuse it and record one return per batch). Returned stock leaves a
  returns location only by an STK-5 count or write-off, or a transfer to another returns location (STK-11).
- **CA guide:** `docs/guides/ms/` should say the OTC allergy step now starts with no choice made (DSP-5).
- **Allergy matching is text only (DSP-1, DSP-1b, DSP-1c):** a CA add or edit that matches an active allergy record
  needs a recorded confirmation. Drug groups and other names (brand names, misspellings) are matched only when a
  catalogue editor has added them (DSP-1b, DSP-1c), so an untagged medicine still misses penicillin vs amoxicillin,
  and a brand name or misspelling nobody listed is still missed, and allergy text with extra words not split by a comma may miss a name with a strength. It is a
  warning, not a block (owner decision 11); the visible allergy list stays the main safeguard. Simple wrapper
  words ("allergic to", "allergy", "alahan") are ignored, so "Allergic to aspirin" matches "Aspirin 100mg".
  For OTC the CA now records the allergy itself (DSP-5), so it is matched like any other record; the OTC step
  starts with no choice made (the CA guide in `docs/guides/ms/` should say so). An allergy added at OTC raises the
  profile version, so a doctor's earlier review of that profile is no longer current. The doctor's Treatment
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
- **Design (owner decision 2026-11-05):** for any piece that changes a screen, (1) before building, run Iman
  as a subagent to sketch the layout (components, phone behaviour at 390 px, empty, loading and error states,
  wording) from the existing patterns and `docs/design/GUIDE.md` once it exists; (2) before hand-off, Iman checks
  the 390 and 1440 px light and dark screenshots against `docs/design/CHECKLIST.md`. A new page or a page layout
  change also goes to Daniel for a look, through the coordinator. Pieces with no screen change skip this.
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
5. **DSP-1b Better allergy matching — merged in #234.** Drug groups tagged on each medicine by catalogue
   editors, matched by the Dispensary allergy warning (decision 14).
6. **DSP-9 Pharmacist review pack — merged in #243.** Chosen by the owner on 2026-11-05; ships before DSP-5. Documents only, in
   `docs/pilot/drug-label/`: the printer test (T1 to T4 worst-case labels and what to check) and the pharmacist
   sheet (what prints, sample labels from `DrugLabelLayout`, ten questions, sign-off). Answers come back to the
   owner; any label change is a separate piece of work.

Second backlog, chosen by the owner on 2026-11-05 (decision 16); choosing it authorises building it, in this
order, each its own PR:

7. **DSP-5 OTC allergy details — merged in #250.** When an OTC walk-in says "has allergies", the CA records what the allergy is,
   structured like a normal allergy record and saved to the patient's allergy profile through the existing
   allergy service and versioning, so the DSP-1/DSP-1b warning also works for OTC. Closes the "OTC yes/no
   statement is not matched" limit. Agree the small change to the Clinical allergy services with the
   coordinator first (there is no clinical team).
8. **DSP-6 Label print log — merged in #261.** Each drug label print or reprint records who printed which line's label and when
   (no label content, append-only, structural audit); a reprint shows a small "REPRINT" mark. Lifts the
   section 4 "no print counter or label history" limit for this log only; no label snapshot.
9. **DSP-7 Medicine returns after dispensing — built 2026-11-05 (branch `feature/dsp-7-medicine-returns`).** A CA or
   CA Supervisor records a returned quantity, reason and condition on a completed case; a CA Supervisor who did not
   record it checks it: Dispose, Quarantine to a patient returns location (STK-11) or Refuse. Decisions 20 and 21.
   Merges only after STK-11.
   - **DSP-7b (later, its own piece):** a branch list of returns waiting for a check, which also adds Finance
     Officer and Director as checkers, and the Director for controlled medicines.
10. **DSP-8 Dispensary pages on phones and tablets — built 2026-11-05 (branch `feature/dsp-8-dispensary-mobile`).**
    Checked at 320, 390, 768 and 1440 px, light and dark.
11. **DSP-10 The clinic's landscape label — built 2026-11-06 (branch `feature/dsp-10-landscape-label`).** Decision 27.

Team rules for this backlog: run the PHPStan stopgap before each push; migration timestamps from
`2026_11_06_200000` up.
