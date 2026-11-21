# DSP-23: the doctor checks prepared medicine before payment (design note)

Status: **design approved (Hakim OK, Daniel OK with changes); owner answers 21 Nov: P3 yes, 10 = A, 11 = A, 12 = A,
13 = B.** Not built. Classic only (V2 retired 19 Nov). Written on the recommended answers to section 9, which are with the owner. Owner decision 14 of 20-21 Nov 2026
(`docs/decisions/2026-11-20-finance-inventory-proposals.md`): "Doctor checks prepared medicine before payment:
a per-branch switch (on or off)." Synthetic data only. Open questions for the owner are in section 9.

## 1. What changes for people

- **Switch off (the default):** nothing changes. The CA presses Complete, the case is completed, and the visit goes
  to billing.
- **Switch on:**
  1. The CA prepares the medicines as today: lines, batches and labels.
  2. Instead of Complete, the CA presses **"Send to doctor for check"**. The case shows "Waiting for doctor's check".
     The CA can no longer edit it.
  3. The attending Resident Doctor sees the case in the Dispensary attention panel on the consultation page. The
     panel shows each medicine, its quantity, its batch and expiry, and anything the CA changed from the order.
  4. The doctor presses **Verify** or **Send back**.
     - **Verify** finishes the case exactly as Complete does today (section 4), and the visit goes to billing.
     - **Send back** needs a reason (wrong medicine, wrong quantity, wrong batch, label problem, or Other with a short
       note). The case returns to the CA as Dispensing, with the doctor's reason shown at the top. The CA corrects it
       and sends it again.
  5. Billing cannot start until the doctor verifies. No billing code changes, because billing already requires a
     completed case.

## 2. Scope

- Consultation cases only. An OTC walk-in has no doctor, so an OTC case completes as today (owner question a).
- One switch per branch, saved with a version. Each case records whether the check applies when the CA sends it
  (`doctor_check_required`), so flipping the switch mid-day neither strands a waiting case nor skips the check for
  it.

## 3. Data

- New table `branch_dispensary_settings`: organisation, branch (unique), `doctor_check_required` boolean (default
  false), `lock_version`, `updated_by_user_id` and timestamps. A missing row counts as off.
- `dispensary_cases`:
  - new status `awaiting_doctor_check`;
  - new nullable columns `doctor_check_required` (frozen when the case is sent), `sent_for_check_at` and
    `sent_for_check_by_user_id`.
  - The PostgreSQL status check gains the new value. A case may be `awaiting_doctor_check` only when
    `doctor_check_required` is true and the case is a consultation case.
- New append-only table `dispensary_doctor_checks`, one row per decision:
  - case, handoff, the case lock version that was checked, the doctor, `outcome` (`verified` or `sent_back`), a
    reason code and an encrypted note;
  - PostgreSQL refuses update, delete and truncate;
  - an insert trigger checks that the case, branch and organisation agree, that the doctor is the encounter's
    attending clinician, and that both a `verified` and a `sent_back` row come from a case in
    `awaiting_doctor_check`.
- Migrations from `2026_11_06_200500` up (the team range; `200400` is the DSP-13 index).

## 4. When stock moves

**Stock moves when the doctor verifies, not when the CA sends the case** (owner question b).

- Today the batch allocations are saved before Complete, and Complete writes the dispense movements. Under the
  check, "Send to doctor" runs every Complete check except the movements and the status change to completed.
- **Lock order (Hakim).** Verify takes the doctor's user lock first (`ConsultationLocks::users([doctor])`), as
  Return to Doctor and hold/resume do. It then reuses `DispensaryService::caTransaction`'s sequence unchanged:
  1. patient, visit, queue entry, encounter;
  2. the allergy profile, its records and the encounter allergy review;
  3. the treatment plan, its medicine orders and the consultation checkouts;
  4. the case, handoff and items;
  5. the allocations, locations, SKUs, mappings and batches;
  6. the stock balances inside `debitForDispense`.

  Nothing is ever locked case-first, so Verify cannot deadlock against a CA write.
- **Verify re-runs every Complete check**, not only stock:
  - the state guard: encounter in progress, queue entry removed as `sent_to_dispensary`, handoff open, visit
    registered;
  - `safety->assertCurrent`, so a new allergy or a changed allergy review while the case waits stops Verify;
  - every item finalised, and patient-declined lines acknowledged at their current version;
  - allocation totals, and location, SKU, mapping and batch validity.
- One guard changes. Complete requires `current_handler_user_id === actor`, which is the CA. Verify instead
  requires the actor to be the encounter's attending clinician, and the case to be in `awaiting_doctor_check` at
  the version the doctor saw.
- **After Verify:**
  - `ca_verified_at` holds the time the CA sent the case;
  - `completed_by_user_id` and `completed_at` hold the doctor and the time of Verify;
  - the handoff is completed exactly as Complete does today.
- Send back writes no stock row, so nothing needs reversing. The CA returns the boxes to the shelf physically.
- **Stock can change while the case waits.** Another case may use the batch, or it may be held or expire. Verify
  re-runs the shared batch-eligibility rule and the no-negative-stock check. If either fails, Verify is refused with a
  plain message ("This batch can no longer be dispensed. Send the case back so the CA can choose another.") and
  nothing changes.

## 5. Permissions

- **New:** `dispensary.doctor_check.own` for `resident_doctor` only. Only the encounter's attending clinician may
  use it, checked at the active branch. This is a clinical permission, so it needs the owner's explicit approval
  (owner question d).
- **Switch:** `dispensary.settings.manage.branch` for `director` and `ca_supervisor` at their branches (owner
  question c). This is also new, so it needs approval too. No existing permission fits (Hakim):
  - `catalogue.settings.manage.organisation` would also let the Finance Officer, Panel Officer and Administrator
    change a clinical safety switch;
  - `queue_display.manage.branch` is about the TV.
- Sending for check uses the CA's existing `dispensary.complete.branch`.

## 6. Every write

- A fresh permission and branch check under lock, `DB::transaction` and row locks in the existing Dispensary order.
- `lock_version` on the case: the doctor checks the exact version the CA sent, and a stale page is refused.
- An idempotency key on Send to doctor, Verify and Send back, and on the switch.
- Audit with ids and codes only, never the note:
  - `dispensary.sent_for_doctor_check`
  - `dispensary.doctor_verified`
  - `dispensary.doctor_sent_back`
  - `dispensary.settings_changed`
- Return to Doctor (the existing route for stock problems and clarification) keeps working while the case is with
  the CA. It is refused while the case waits for the check; the doctor uses Send back instead. The same guard covers
  the CA's button (hidden) and the route (refused).
- **The switch is read under lock at Complete (Hakim).**
  - Complete locks the branch's settings row. If the check is now on, a page opened while it was off is refused:
    "This branch now needs the doctor's check. Reload the page." A stale page cannot skip the check.
  - The reverse also holds. A page opened while the check was on, which presses "Send to doctor" after the switch was
    turned off, is refused with the same message, unless the case already needs the check from an earlier Send.
  - `doctor_check_required`, once set on Send, stays for the life of the case. After Send back, the next submit is
    again Send to doctor, even if the switch was turned off meanwhile.

## 6a. Other code that knows the case statuses (Hakim)

| Code | Today | With `awaiting_doctor_check` |
|---|---|---|
| `DrugLabelService` (completed, or dispensing for its handler) | 404 while waiting | A read-only preview is allowed for the CA and CA Supervisor. Printing stays allowed, because the doctor may ask for a reprint after a label problem; each print is logged as today. |
| `VisitDirectoryService` "Open Dispensary" (pending or dispensing) | the link disappears | The new status is added: the link opens the waiting case read-only. |
| `RoomCallService` dispensary call (pending or dispensing) | refused | The new status is added, so the CA may call the patient while the case waits. The waiting banner says "Do not hand over the medicine until the doctor has checked it", and the call button's help line repeats it. Wording is for Daniel. |
| `ReopenConsultationCheckoutService`, `CompleteConsultationService` (need `returned_to_doctor`) | refused while waiting | Intended. The doctor acts through Verify or Send back. |
| `DispensaryDirectoryService::detail` `can` flags | dispensing or completed | A waiting case shows the open-case page read-only: no edit, no Complete, the waiting banner, and Print and Call still allowed. |
| `DoctorDispensaryAttentionService::forEncounter`, `hasPendingForVisit`, `pendingForVisits` | pending or dispensing, plus a patient-declined exception | A waiting case counts too, so the Queue board hint shows "Medicine ready for your check" on the doctor's board. The doctor has usually moved on to the next patient by then, and this is how they find the case. |
| Billing, returns, refunds | need `completed` | No change. |

## 7. Screens (Daniel's look before the build)

- **Dispensary case page (Classic only; V2 was retired on 19 Nov):**
  - the Complete button reads "Send to doctor for check" when the case needs it;
  - a waiting case shows a read-only banner, "Waiting for Dr …'s check since 10:42";
  - a sent-back case shows the doctor's reason at the top.
- **Doctor's Dispensary attention panel (Classic):**
  - a new row kind, "Medicine ready for your check", with the lines, quantities, batches and expiry, and the CA's
    changes beside the order;
  - an outline Verify button, and a Send back button that opens a reason picker;
  - the row says what each button does.
- **Doctor's Queue board:** the existing Dispensary hint on the patient's row reads "Medicine ready for your check"
  and opens that consultation at the panel.
- **Registration board:** the case's chip reads "Waiting for doctor's check", so the CA at the counter knows why
  billing is not ready.
- **Settings:** one switch per branch, with a sentence saying what it does. Placement is to be agreed with Adlina
  (Catalogue & settings) and Daniel.

### 7a. Daniel's notes on the design (OK with changes, 21 Nov; to check again on shots)

1. **Verify** is an outline button that opens a confirm sheet: "Verify and release to billing? The medicine is
   dispensed when you confirm." Red is used only in that sheet.
2. **Send back** is an outline button, not red. It opens a reason picker that starts empty ("Pick a reason to
   continue.", with the confirm button off). Other needs the short note.
3. **The doctor's row shows the patient's allergy status line** beside the lines. CA changes show as chips with a
   dot and a word ("Added by CA", "Edited by CA"), not colour alone.
4. **The sent-back reason at the top of the CA page** is a warning box with the reason and the note, not red.
5. **Waiting banner:** "Waiting for Dr X's check since 10:42", with the date when it is not today. The hand-over
   instruction, "Do not hand over the medicine until the doctor has checked it", appears in a warning box with an
   icon in the banner, and as the call button's help line. Print and Call stay available.
6. **Registration chip:** a dot and the words "Waiting for doctor's check". The Queue board hint reads "Medicine
   ready for your check".
7. **Stale or refused messages** show as the usual toast, and the page stays where it is.
8. **Settings switch:** a 44 px target with the word "On" or "Off" beside it and one sentence saying what it changes.
   It saves with a confirm toast. Placement is to be agreed with Adlina and Daniel; Daniel suggests beside Business
   details, under Dispensary.
9. **At 390 px:** the doctor's row becomes a card, with the lines stacked and both buttons full width at 44 px.

## 8. Tests

- Feature tests:
  - switch off, the flow is unchanged;
  - switch on, send, verify and billing;
  - send back, correct, send again and verify;
  - Verify refused for another doctor, for another branch, on a stale version, and when a batch became ineligible;
  - OTC unaffected;
  - a switch flip mid-case;
  - no stock movement before Verify;
  - every audit row.
- PostgreSQL:
  - the status check;
  - append-only check rows;
  - a two-process race between two Verify presses, and between Verify and Send back;
  - a two-process race between Verify and another case dispensing from the same batch, which must not take stock
    below zero.
  - All three join the serial Contention suite.
  - Trigger tests: a `verified` or `sent_back` row is refused for a case that isn't waiting, and for a doctor who
    isn't the attending clinician.
- More feature tests (Hakim):
  - Complete refused on a stale page after the switch is turned on;
  - Verify refused after a new allergy is recorded, and after the encounter or queue state changes;
  - a replayed Verify with the same key writes no second dispense movement;
  - the encrypted note never reaches audit or logs;
  - the switch's idempotency key and audit row;
  - the additive permission migrations.
- Frontend and feature tests for the doctor's Queue board hint, the label preview while waiting, the Registration
  chip and the "Open Dispensary" link.
- Frontend tests for both Classic pages.
- Existing assertions are expected to stay unchanged, because the switch defaults to off, apart from the
  role-permission pins that list each role's permissions exactly. The two new permissions change them; these are
  listed and left failing for the owner.

## 8a. Owner answers (21 Nov), which replace section 9

- 10 = A: consultation cases only.
- 11 = A: stock moves when the doctor verifies.
- 12 = A: the Director and that branch's CA Supervisor turn the switch on or off, and each change is recorded. The
  Branch Manager joins them once that role exists (ROLE-1).
- P3: `dispensary.doctor_check.own` for the Resident Doctor is approved.
- **13 = B: if the doctor is unavailable, a CA Supervisor at that branch may check instead, giving a reason, and it is
  recorded.**
  - This uses a second permission, `dispensary.doctor_check.override.branch`, for `ca_supervisor` only. It is the
    implementation of answer 13 and is listed for the owner.
  - The CA Supervisor uses the same Verify and Send back. Verify additionally needs a reason from a fixed list
    (doctor off shift; doctor in an emergency; Other, with a short note).
  - The check row records `checked_as = 'supervisor'` and the reason.
  - A CA Supervisor never checks a case they prepared themselves (service and trigger).
  - The case appears for them in a branch list, "Waiting for doctor's check", reachable from the Registration chip.

## 9. Questions for the owner (answered 21 Nov; see 8a)

a. Consultation cases only, not OTC? (Yes.)
b. Stock moves when the doctor verifies? (Yes.)
c. Who flips the switch? (Director and CA Supervisor at that branch, audited.)
d. Approve `dispensary.doctor_check.own` for the Resident Doctor, attending clinician only? (Yes.)
e. If the attending doctor is unavailable, the case waits, with no override? Or may a CA Supervisor verify instead?
   (Recommend: wait, with no override, because the point is the doctor's own check. The doctor's session can still
   act while the consultation is held.)
