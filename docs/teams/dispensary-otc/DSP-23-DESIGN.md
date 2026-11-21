# DSP-23: the doctor checks prepared medicine before payment (design note)

Status: **draft for Hakim's review.** Not built. Classic only (V2 retired 19 Nov). Written on the recommended answers to section 9, which are with the owner. Owner decision 14 of 20-21 Nov 2026
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
    attending clinician, and that a `verified` row comes from a case in `awaiting_doctor_check`.
- Migrations from `2026_11_06_200500` up (the team range; `200400` is the DSP-13 index).

## 4. When stock moves

**Stock moves when the doctor verifies, not when the CA sends the case** (owner question b).

- Today the batch allocations are saved before Complete, and Complete writes the dispense movements. Under the
  check, "Send to doctor" runs every Complete check except the movements and the status change to completed.
- Verify then runs them under the same locks: the case, the handoff, the items, the allocations, then the stock
  rows in the existing order.
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
  question c). This is also new, so it needs approval too. Alternatively it could reuse an existing branch settings
  permission if the owner prefers.
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
  the CA. It is refused while the case waits for the check; the doctor uses Send back instead.

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
- **Registration board:** the case's chip reads "Waiting for doctor's check", so the CA at the counter knows why
  billing is not ready.
- **Settings:** one switch per branch, with a sentence saying what it does. Placement is to be agreed with Adlina
  (Catalogue & settings) and Daniel.

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
  - a two-process race between two Verify presses, and between Verify and Send back. Both join the serial Contention
    suite.
- Frontend tests for both Classic pages.
- Existing assertions are expected to stay unchanged, because the switch defaults to off. Any that do change will be
  listed and left failing for the owner.

## 9. Questions for the owner (recommended answer first)

a. Consultation cases only, not OTC? (Yes.)
b. Stock moves when the doctor verifies? (Yes.)
c. Who flips the switch? (Director and CA Supervisor at that branch, audited.)
d. Approve `dispensary.doctor_check.own` for the Resident Doctor, attending clinician only? (Yes.)
e. If the attending doctor is unavailable, the case waits, with no override? Or may a CA Supervisor verify instead?
   (Recommend: wait, with no override, because the point is the doctor's own check. The doctor's session can still
   act while the consultation is held.)
