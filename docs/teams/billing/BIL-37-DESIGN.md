# BIL-37: the Resident Doctor sees a visit's bill, view only (design note)

Owner decision, 24 Nov 2026, relayed by the coordinator: "Doktor di Triage dan Billing... adakan ia, backend sedang
built kan?" This approves in principle a read-only Billing view for `resident_doctor`, in the style of the
consultation and Dispensary read-only views. The new permission's name is for the owner to confirm. (Triage belongs to
another team; this note covers Billing only.)

Classic only. Synthetic data only. View only: no money action, no print.

## 1. What exists today

- **Nothing is built and nothing is in progress.** There is no Billing team row, branch or remote branch for a doctor's
  Billing view.
- The doctor already opens the Invoices page for a completed visit (`visits.history.view.branch`, VISIT-01). The
  invoice part is empty, however, because it needs `billing.view.branch` or `billing.summary.branch`. So today a doctor
  sees no money anywhere.
- The read-only patterns to follow:
  - DSP-25 / consultation review: the Director, `*.view_readonly.organisation`, every open recorded by
    `ReadOnlyReviewAudit` before any content is built;
  - DSP-26: the doctor, `dispensary.view_readonly.branch`, active branch only, with the shared read-only banner. Its
    opens are not audited, the same as a CA opening the page.

## 2. Proposal (the smallest one)

- **Permission:** a new **`billing.view_readonly.branch`**, for `resident_doctor` only (owner to confirm the name). It is
  a view grant, so it never reuses `billing.view.branch`, `billing.summary.branch` or any write permission. It is not
  people-or-access authority.
- **Migration:** one additive migration in my range, `2026_11_25_200100_grant_doctor_billing_view_permission`, mirrored
  in `BillingPermissions`. Nothing goes to `ca`, so the `medical_assistant` rule does not apply.
- **Service:** `BillingReviewService::open(User, Visit)`. It runs these steps:
  1. it checks the actor is active, holds the permission, and the visit is at the viewer's active branch and in the
     organisation (404 otherwise);
  2. if the open is audited (question 3), it records it first through `ReadOnlyReviewAudit`. A new allowlisted event
     `billing.reviewed` with keys `visit` and `invoice` is added there deliberately, a small change to that shared file;
  3. it builds an explicit projection (section 3).
- **Page:**
  - a new route `GET visits/{visit}/billing/review` (`permission:billing.view_readonly.branch`, `sensitive.no-store`,
    `throttle:60,1`) and a page `Billing/Review.vue`;
  - the layout follows the Invoice page and starts with the shared read-only banner;
  - entry points: a "View bill" link on the doctor's consultation page for their visit, and a "View bill" link on the
    Invoices page. The Invoices page itself shows no money to the doctor, as today (section 6).
- **Stays closed:** every write route keeps its own permission in the middleware and in its service, so the doctor gets
  403 on build, finalise, payments, coverage, adjustments, notes, Bill to, refunds, void, print and issued copies.

## 3. What the doctor sees, and what stays hidden

| Shown | Hidden |
|---|---|
| Invoice number, status (Draft, Finalised, Void) and date | Internal billing notes |
| Lines: name, quantity, unit price, total | Bill to |
| Adjustment (discount or surcharge) amount, not its reason | Adjustment reason |
| Total, Panel or pay-later cover, paid, due now | Who recorded each payment (staff names) |
| A plain status: Paid, Due, Covered by Panel | Refund, correction and void details |
| | Panel claim link, member reference, IC/passport and phone |
| | Issued copies and every Print button |

## 4. Questions for the owner

1. **Which pages?** **A (recommended):** both the open visit's bill and the completed visit's invoice. **B:** only
   completed invoices.
2. **Which patients?** **A (recommended):** any visit at the doctor's active branch, as DSP-26. **B:** only visits where
   they are the attending doctor.
3. **Record each open?** **A (recommended, as asked):** yes, one audit row per open through `ReadOnlyReviewAudit`
   (ids only, no amounts). **B:** no, as DSP-26 and a CA opening the page.
4. **Payment detail?** **A (recommended):** totals only (paid, cover, due). **B:** also one row per payment (date,
   method, amount, without staff name).
5. **The permission name:** `billing.view_readonly.branch`. Confirm, or name another.

## 5. Tests

- Doctor: opens an open visit and a completed visit at their branch, sees exactly the section 3 fields, and no notes,
  Bill to, recorder or identity anywhere in the props.
- Doctor gets 403 on every Billing write and print route, and 404 at another branch.
- Audit (answer 3A): one `billing.reviewed` row per open with ids only. If the audit write fails, nothing is shown.
- Other roles: unchanged, and the holder pin is `resident_doctor` only.
- Migration: grants, idempotent, never revokes, up then down then up.
- No contention test is needed: the page is read only and takes no lock.

## 6. Existing assertions

- The `ReadOnlyReviewAudit` allowlists (`EVENTS`, `ID_KEYS`), if pinned by a test, gain `billing.reviewed` and
  `invoice`.
- `tests/Feature/Visit/VisitHistoryTest.php::test_a_doctor_sees_the_clinical_history_but_no_financial_content`: it pins
  that the doctor's Invoices page has no financial content. If the invoice section opens for the doctor (answer 1A
  or 1B), its financial part becomes the read-only section 3 fields for a holder of the new permission. There is a
  way to keep that assertion as it is: the Invoices page stays empty and only links to the new review page. Owner to
  choose; I recommend the link-only way, so the existing assertion stays as it is.
- The full run will list the exact ones. Each goes to the owner first and stays failing until approved.
