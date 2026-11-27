# BIL-39: the family bill (design note)

Owner decision, 25 Nov 2026, by tick box in the coordinator session: the Clean Look "Family bill" (canvas FamilyBill) is
built before the Monday 30 Nov Puchong test. One combined bill for linked family members, each keeping their own invoice,
with "Remove from family bill". The family link itself belongs to Patients & Registration (Nurul,
`docs/teams/patients-registration/FAMILY-LINK-DESIGN.md`). This note covers the Billing side only. Synthetic data only.

## 1. The model agreed with Nurul (25 Nov 2026)

- Nurul owns:
  - `patient_families` and `patient_family_members` (one family per patient, no head or payer);
  - a new nullable `visits.family_group_id` (uuid). Registration stamps it on each visit when members register together.
- Billing reads `family_group_id` and never writes it. A later change to the family link never rewrites an old bill.
- **No new money model.** Each visit keeps its own invoice, its own Panel or pay-later share and its own receipts. The
  family bill is a view plus one payment action.

## 2. What the family bill is

- The open, finalised, non-void invoices at the viewer's active branch whose visits share one `family_group_id`, minus
  the excluded ones (section 4).
- Reached from:
  - a "Family bill" button on any member's Billing page, when that visit has a `family_group_id` and at least one other
    member's invoice qualifies;
  - the canvas route, `GET billing/family/{familyGroupId}`.
- **Shown:**
  - one card per member: name, KP number, coverage (self-pay or Panel), invoice lines, and that invoice's due now;
  - one total due now;
  - one payment method and amount at the bottom.
- Everything a member's own Billing page does (adjust, Panel or pay later, print, refund) stays on that member's page.

## 3. Take payment for the family

- **Input:** method, amount (at most the family total due now), reference, and one `idempotency_key` for the whole family
  payment.
- **Split:** the amount is spread oldest invoice first (by `finalized_at`, then id), up to each invoice's due now. A
  member with nothing due gets nothing.
- **One receipt per invoice**, through today's `PaymentService::add`, so every receipt keeps its own number, its own
  print and its own refund path:
  - each child receipt's idempotency key is derived from the family key and the invoice;
  - a retry returns the same receipts;
  - a changed payload is refused.
- **Locks and atomicity:**
  - one `DB::transaction`;
  - the actor and branch lock, and the BIL-32 day gate;
  - every member invoice row locked in id order before any receipt is written;
  - all-or-nothing: if one invoice changed (version), became void, or its due now shrank, the whole payment is refused
    ("A bill in this family changed. Reload.") and nothing is recorded.
- **Audit:** one `billing.family_payment_recorded` (family group id, invoice ids, amounts, no names), in addition to each
  receipt's usual audit.
- **No new table for the payment:**
  - a `family_payment_id` uuid is stored on each receipt in a new nullable `payments.family_payment_id` column, so the
    receipts can be shown together;
  - Panel and pay-later stay per invoice, as today.

## 4. Remove from family bill

- New table `family_bill_exclusions`: invoice_id, organisation_id, branch_id, `excluded_by_user_id`, `excluded_at`, and
  `restored_by_user_id` / `restored_at` (put back).
  - One open exclusion per invoice (partial unique index).
  - No delete or truncate; a restored row never changes again.
- Changes no money, invoice or visit data. Audit `billing.family_bill_member_removed` / `..._restored`.
- Permission: `billing.build.branch` (the canvas uses the same one). **No new permission.**

## 5. Permissions

- `billing.view.branch` (or `billing.summary.branch`) to see the family bill.
- `payments.add.branch` to take the family payment.
- `billing.build.branch` to remove or put back.
- No new permission or role grant.

## 6. Migrations

- `family_bill_exclusions` (new).
- `payments.family_payment_id` (nullable uuid, indexed).
- Both additive and in my range.
- They depend on Nurul's `visits.family_group_id` migration, which must merge first.

## 7. Tests

- **Grouping:** the same family group at one branch; another branch excluded; void and draft invoices excluded; an
  exclusion removes a member and putting it back restores it.
- **Split:**
  - oldest first;
  - a partial amount stops early;
  - an amount above the total is refused;
  - a member with nothing due gets nothing.
- **Atomicity:** one invoice changing or voiding refuses the whole payment, with nothing written.
- **Idempotent retry:** the same receipts; a changed payload is refused.
- **Day gate:** locked or paused days are refused, as for one payment.
- **Access:** permissions for each action; another branch gives 404.
- **Each child receipt** prints, reverses and refunds exactly as a normal receipt.
- **Contention:** a family payment racing a single payment on one member's invoice; two family payments at once.
- **Existing assertions:** none expected to change. Any that do will be listed for the owner first.

## 8. Questions for the owner (tick boxes)

1. **Split order:**
   - **A (recommended):** oldest invoice first.
   - **B:** the counter types the amount per member.
2. **Can the family pay part of the total?**
   - **A (recommended):** yes; the split stops when the money runs out.
   - **B:** the full family total only.
3. **One receipt per invoice:**
   - **A (recommended):** yes, each member's receipt as today.
   - **B:** also print one combined family receipt. This would be a new document type and needs the print-wording
     approval.

## 9. Owner answers (27 Nov 2026, in the Billing session)

1. Split order: **A**, oldest invoice first.
2. Part payment: **A**, yes; the split stops when the money runs out.
3. Receipts: **A**, one receipt per invoice; no combined family receipt.
4. Dependency: wait for Nurul's `visits.family_group_id` migration to merge before building; Kumar reviews this note
   meanwhile.
