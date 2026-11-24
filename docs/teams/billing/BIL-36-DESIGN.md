# BIL-36: Finance Officer and Director may print invoices and receipts (design note)

Owner decision, 24 Nov 2026, relayed by the coordinator: "Cetak invois... Perlukah Finance dan Director juga boleh? --
tambah backend bolehkan ia." This approves granting the existing `billing.print.branch` to `finance_officer` and
`director`. Today only `ca`, `ca_supervisor` and `medical_assistant` hold it.

Classic only. Synthetic data only. No money rule changes: printing only freezes and shows what is already recorded.

## 1. What `billing.print.branch` opens today

One permission covers every invoice and receipt print:

- `billing.print`, `billing.receipt` and `billing.letterhead-logo` (GET, the print pages);
- `billing.issue` and `billing.receipt.issue` (POST, freeze an issued copy, BIL-5 and BIL-9);
- `billing.issued.reprint` and `billing.issued.show` (POST and GET, copies);
- the Print buttons and the "Issued copies" list on the Billing page (`BillingDirectoryService`), the receipt Print
  buttons on the Manage payments page (both from `BillingWorkspace.vue`), and the Print links on the Invoices page
  (`VisitHistoryService`).

The Invoices page also needs `visits.history.view.branch`. The Director holds it, but the Finance Officer does not
(`CompletedInvoiceTest` pins Finance at 403 there), so **Finance prints from the Billing and Manage payments pages
only**. This note does not open the Invoices page to Finance. (Kumar F2)

The Panel rule (PCA-1, `PanelInvoicePrinting::blocked`) applies to every holder: a Panel patient never gets a printed
invoice, but a receipt for cash they paid still prints. Finance and the Director get exactly the same rule.

## 2. Can they issue? "See every printed part"

Issuing (`IssuedBillingDocumentService`) refuses anyone who cannot see every part of the paper. There are three checks:

| Check | Why | Finance Officer | Director |
|---|---|---|---|
| `billing.bill_to.view.branch` | the Bill to block | holds it | holds it |
| `billing.view.branch` or `billing.lines.view.branch` | the invoice lines | holds `lines.view` (FIN-01) | holds `lines.view` (FIN-01) |
| `patients.view.organisation` (BIL-9) | the paper names the patient by IC/passport and phone | **does not hold it** | holds it |

- **The Director needs nothing more.** They already hold `billing.lines.view.branch` from FIN-01 (2026-10-08), as well as
  Bill to view and patient view. With `billing.print.branch` they can print, issue and reprint.
- **The Finance Officer would get the Print button but every issue would be refused** with "You cannot see every part
  of this document, so you cannot issue it." BIL-14 retired the old preview prints, so there is no print without
  issuing. The owner needs to choose one of these (question 1):
  - **A. Also give Finance `patients.view.organisation`.** This opens the whole patient directory and patient pages
    (names, IC/passport, phone, address) to Finance. It is a much wider privacy change than printing.
  - **B. Relax the BIL-9 rule so anyone who may print may issue**, even without patient view. This changes an existing
    rule and two existing assertions (`InvoiceReceiptLayoutTest`: a printer without patient view cannot issue, and
    cannot reopen or reprint a BIL-9 copy). It would also let a CA without patient view issue.
  - **C. (Recommended)** A new narrow permission **`billing.print.patient_identity.branch`**, for `finance_officer`
    only. The three BIL-9 identity checks (issue, open a copy, reprint) accept it as well as `patients.view.organisation`.
    It shows the IC/passport and phone on the paper and nothing else: no patient directory or patient pages. It mirrors
    `panel.claims.patient_identity.view.branch`, which Finance already holds for Panel claims. The existing assertions
    stay as they are, because a CA without patient view still has neither permission. The exact change is one helper,
    `canSeePrintedIdentity()` = `patients.view.organisation` or `billing.print.patient_identity.branch`, used at the
    issue check (`contents()`) and at `assertCanSeeIdentity()` (reprint and open). Nothing else reads it, so Finance
    still gets 403 on `/patients`, and `patient.profileUrl` stays null for them on the Billing page. (Kumar F4)
  - **Audit with C (Kumar F3).** Issue and reprint are already audited (`billing.document_printed`). Opening an issued
    copy (`show()`) writes no audit row, for anyone. Panel claims audit every identity view. Question 3 asks whether
    Finance's reopen of a copy should also be audited.
- **Recipients of a reprint.** Each view of a BIL-9 copy already passes the same identity check, so nothing new opens.

## 3. Changes

- `BillingPermissions::roles()` adds `billing.print.branch` to `finance_officer` and `director` (and, with answer C,
  `billing.print.patient_identity.branch` to `finance_officer`).
- One additive migration in my range, `2026_11_25_200000_grant_billing_print_to_finance_and_director`: `firstOrCreate`
  each permission, `givePermissionTo` only what is missing, a missing role logged and skipped, `down()` a no-op.
  Nothing goes to `ca`, so the `medical_assistant` rule does not apply.
- With answer C: `IssuedBillingDocumentService` gets one private `canSeePrintedIdentity()` used by its BIL-9 checks.
- Neither permission is people-or-access authority, so `AUTHORITY_OVER_PEOPLE_AND_ACCESS` and staff-authority rules are
  unchanged.
- No new screen. The existing Print buttons and the Issued copies list appear for them on the Billing page (which they
  open through `billing.summary.branch`) and on the Manage payments page; the Director also on the Invoices page.
  Daniel gets before/after shots of the Billing and Manage payments pages for both roles, and the Invoices page for the
  Director.

## 4. Existing assertions that change (owner approval needed one by one)

- `tests/Feature/Billing/IssuedBillingDocumentTest.php`, about line 101: "The Billing page lists both versions for
  those who can print, and nothing for Finance." Finance would now see the Issued copies list (2 versions), so the
  assertion `billing.issuedCopies` null for Finance becomes "2 copies listed for Finance, as for the CA".
- `tests/Feature/Billing/IssuedBillingDocumentTest.php:29`: the `roles()` data provider expects `finance_officer` and
  `director` to get 403 on `billing.issue`. Both become a redirect (302) after a successful issue.
- `tests/Feature/Billing/DocumentLetterheadTest.php:46-50`: `director` and `finance_officer` get 403 on `billing.print`
  and on the letterhead logo URL. Both become allowed.
- `tests/Feature/Billing/InvoiceBillToTest.php:213-215`: Finance gets 403 on `billing.print`. It becomes the redirect to
  the issued copies.
- Unchanged: the `administrator` denials in the report tests; `MedicalAssistantRoleTest`; the Panel print tests (they
  print as the CA); `CompletedInvoiceTest` and `VisitHistoryTest` for Finance (the Invoices page stays closed to them).
  `tests/Frontend/fixtures/access-control-roles.json` is a static, already stale snapshot not compared with the catalogue,
  so it needs no change. The full run will confirm; anything further goes to the owner first and stays failing until
  approved.

## 5. Tests

- Finance Officer and Director: print, issue, reprint and open copies of a self-pay invoice and a receipt. With C,
  Finance's issue works only through the new permission, and Finance still gets 403 on `/patients`.
- Panel visit: no invoice print for either role; receipt for cash paid still prints.
- Holder pin: exactly `ca`, `ca_supervisor`, `medical_assistant`, `finance_officer` and `director` hold
  `billing.print.branch`.
- Migration: grants to a legacy database, idempotent, never revokes, up then down then up.
- Branch scope: Finance at another branch gets 404, as a CA does.
- Contention: two first prints at once is already proven for the CA (`PostgresBillingRegressionTest`). The code path is
  the same, so I add a Finance-and-CA pair to that test rather than a new harness.

## 6. Questions for the owner

1. Finance and patient identity on the paper: **A**, **B** or **C (recommended)**?
2. Receipts too? `billing.print.branch` covers receipts as well as invoices, so Finance and the Director would print
   both. **A (recommended):** yes, both. **B:** invoices only, which would need a separate receipt permission.
3. With C, record each time Finance opens an issued copy? **A (recommended):** yes, one `billing.document_viewed` audit
   row (ids only) when the opener holds the new permission but not patient view, matching the Panel identity rule.
   **B:** no, as for every other opener today.
