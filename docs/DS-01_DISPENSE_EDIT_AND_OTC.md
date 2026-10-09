# DS-01 — CA edits at Dispensary, OTC dispensing, and the visit record (design for approval)

Status: **design approved by the owner on 2026-10-09; DS-01a (medicines on the consultation Dispensary page)
built on `feature/ds-01-ca-dispense-edit`.** Not production-approved; synthetic data only.

Owner decisions, 2026-10-09: the doctor's original order is kept and every CA change audited (I1); the doctor's
allergy confirmation stands, so there is **no** CA allergy tick (I2); a CA removing or reducing a line needs no
doctor acknowledgement (I3); the CA confirms services at Dispensary instead of the doctor (I4, next slice);
the completed-visit page is called **Invoices**.

## 1. What the owner asked for

1. A CA can edit everything about the medicines (and services) on the Dispensary page, as in Yezza:
   edit, add and delete lines. No Pending / Partial / Not dispensed states for the CA to manage.
2. Two verifications: the doctor's (existing, at send to Dispensary) and the CA's (new, at Dispensary).
3. The doctor's original order is **kept**; the CA's final list is stored separately; every change is audited.
4. OTC visits get a **Dispense** action in Registration. The CA builds the medicine list (add, edit,
   delete), then goes to Billing and completes the visit. OTC has no doctor and no queue.
5. Completed visits (consultation and OTC) open a read-only record, using the same design as the new
   Dispensary page. The doctor's "Previous consultation" full page uses it too.
6. The Consultation page does not change.

## 2. What exists today, and why this is not a small change

- `DispensaryService` runs every action through `caTransaction`, which locks the visit, **queue entry**,
  **encounter**, allergy profile, **treatment plan** and its medicine orders. An OTC visit has none of the
  queue entry, encounter or plan, so it cannot enter this path.
- `DispensarySafetyValidator::assertCurrent` requires every dispensary item to carry the Allergy Profile
  version the doctor validated, and the plan to still be the one the doctor sent.
- `BillingSourceService::locked` rejects any visit that is not `visit_type = consultation`, and requires the
  dispensary items to match the plan's medicine orders **one to one** (`treatment_plan_medicine_order_id`),
  plus the doctor's service confirmations (`ServiceDelivery`, fingerprint-checked against the plan).
- Partial or not-dispensed lines need a patient-declined reason, and the attending doctor must acknowledge
  them (`DispensaryItemException`) before Complete. Stock is debited at Complete from batch allocations.
- Ten PostgreSQL contention tests exercise these locks and orderings.

So each of the three asks changes a safety rule the earlier phases enforce on purpose.

## 3. Proposed model (keeps the original, adds the CA's version)

- **Doctor's order** = `treatment_plan_medicine_orders` / `treatment_plan_service_orders`. Untouched, immutable.
- **Dispensed list** = `dispensary_items`, already a snapshot per line. The CA edits these rows.
  - New columns: `source` (`doctor` | `ca`), `change_state` (`unchanged` | `edited` | `added` | `removed`),
    `edited_by_user_id`, `edited_at`. `treatment_plan_medicine_order_id` becomes nullable for CA-added lines.
  - A removed line stays as a row with `change_state = removed` and quantity 0, so history can show it.
- The dispensed list drives stock, labels and the invoice. The plan drives the "Ordered" side of the record.
- **CA verification** at Complete: pressing Complete after reviewing the final list is the CA's own
  verification, stamped on the handoff (`ca_verified_at`) and audited. The allergy list stays visible on the
  page; there is no separate allergy tick (the doctor's confirmation stands).
- Audit stays structural (who, when, which item, which kind of change) with **no medicine text**, so Audit
  Logs never carry clinical content for Director or Technical Admin.

## 4. Invariants that change (owner sign-off needed)

| # | Today | Proposed |
|---|---|---|
| I1 | CA cannot change what the doctor ordered | CA may edit, add and remove lines; original kept; changes audited |
| I2 | Every line carries the doctor's validated Allergy Profile version | Unchanged: CA-added lines carry the current profile version when added; an unknown profile blocks adding a medicine; no extra CA tick |
| I3 | Partial / not-dispensed needs doctor acknowledgement | Removed or reduced lines are the CA's decision and need no doctor acknowledgement; "Return to Doctor" stays for real clarification |
| I4 | Doctor confirms each performed service at checkout | CA may edit the service list at Dispensary and confirms performance there (Yezza style) — **owner to confirm** |
| I5 | Billing accepts consultation visits only, items 1:1 with the plan | Billing accepts the dispensed list (and OTC) as the source; the plan is the comparison only |
| I6 | OTC has no workflow after Registration | OTC: Registration -> Dispense (CA) -> Billing -> Complete |

Not changed: branch scope, permissions per action, row locks, `lock_version`, idempotency, stock debit only at
Complete, audit on every mutation, Technical Admin never sees clinical content.

## 5. OTC specifics

- New `dispensary_cases` for an OTC visit with no encounter, plan, handoff or queue entry (those FKs become
  nullable for OTC, with a database check that OTC cases have none and consultation cases have all).
- CA picks medicines from the catalogue; price comes from the existing price books; stock allocation and the
  debit at Complete are unchanged.
- The patient's allergy profile is shown; the CA must confirm "allergy checked with patient" before Complete.
  If the profile is unknown the CA records what the patient said; no automatic drug-allergy blocking (not
  built or authorised).
- A new permission for the OTC Dispense action, granted to `ca` and `ca_supervisor`.

## 6. The page called "Visit History" today

Already built on `feature/vh-01-visit-history` (read only, branch scoped, `visits.history.view.branch`).
It is extended to show **Ordered vs Dispensed** side by side where a line was edited, and to cover OTC visits.
The doctor's "Previous consultation" full page links to it. Name: owner's choice (Visit Record / Invoices /
Visit History); it is a one-line change.

## 7. Delivery plan (separate branches, each with red-green tests and a PostgreSQL 18 run)

1. **DS-01a** *(built)* Dispensed-list columns + CA edit/add/remove of **medicines** + CA verification on the
   existing consultation Dispensary page; Billing reads the dispensed list. **DS-01a-services** *(built)*: the CA
   edits, adds and removes services and confirms performance (I4) in `dispensary_service_lines`, one set per handoff,
   started from the doctor's confirmation; the doctor's order and `service_deliveries` evidence are untouched;
   billing reads the CA's lines for visits that go through Dispensary (`invoice_lines.dispensary_service_line_id`,
   PostgreSQL reconciliation updated). A visit that bypasses Dispensary still bills the doctor's confirmation.
2. **DS-01b** OTC (owner decisions 2026-10-09: three PRs, medicines only).
   - **DS-01b-1** *(built)*: an OTC visit gets a Dispensary case of its own (`case_type = otc`, no encounter, plan,
     queue entry or services), opened by `OtcDispensaryService` under the new `dispensary.otc.create.branch`
     (`ca`, `ca_supervisor`). It reuses items, batch allocation, labels, CA edit/add/remove and the stock debit at
     Complete. The CA records what the patient said about allergies (`none` / `has_allergy`, an enum, no free text)
     and must confirm it before Complete; there is no automatic drug-allergy blocking. At least one dispensed
     medicine is required. Doctor-only actions (return to doctor, services, partial/not-dispensed) are refused.
     No billing and no UI yet.
   - **DS-01b-2** *(built)*: an invoice is anchored to exactly one source, a consultation checkout or an OTC
     Dispensary case (`invoices.dispensary_case_id`, PostgreSQL `invoice_anchor_check`). `BillingSourceService`
     bills a completed OTC case: the CA's dispensed medicines only, no consultation line, no service, no doctor.
     The PostgreSQL reconciliation function proves each OTC line against a dispensed item of that case, and the
     completed-visit trigger accepts an OTC visit only with a finalized invoice and a completed OTC case. Both
     functions are patched in place by exact-match replacement (the migration refuses to run if a replacement
     does not match exactly once). Known limit: Insights reports that join invoices to consultation checkouts
     (doctor-based tables) still count consultation invoices only. No routes or screens yet.
   - **DS-01b-3** *(built)*: the **Dispense** action on an OTC row of the Registration board (`visits.dispense`,
     `dispensary.otc.create.branch`) opens the OTC case and lands the CA on the Dispensary page, which for OTC shows
     "OTC · no doctor", an "Ask the patient about allergies" step (`dispensary.otc-allergy`) that must be recorded
     before Complete, and no services panel, Return to Doctor or TV call. After Complete the Registration board
     offers Open Billing for the OTC visit. Flow: Registration -> Dispense -> Billing -> Complete.
3. **DS-01c** Visit Record: Ordered vs Dispensed, OTC, doctor's previous-consultation link.
4. **DS-01d** New Dispensary page design (the editable layout) and removal of the Pending/Partial/Not
   dispensed controls.

## 8. Risks

- Rewriting the stock/billing source rules is the highest-risk change in the project so far; 1:1 matching
  between the plan and the dispensary items is currently a safety check, and it is replaced by "the CA's
  confirmed list" plus a stored comparison.
- Without drug-allergy matching, a CA can add a medicine a patient is allergic to. The mitigation is the
  allergy display and recorded confirmation, not a block.
- Services edited by a CA are a change of clinical authority (I4).

## 9. Open decisions

- None. (I4 and the name were settled on 2026-10-09: the CA confirms services; the page is called Invoices.)
- Whether a doctor may see the CA's changes on the Consultation side after completion (proposed: yes, read only
  on the visit record, not on the Consultation page).
