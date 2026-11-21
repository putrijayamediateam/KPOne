# BIL-29: mark foreign (passport) patients for SST

Owner decision 12 (questionnaire of 20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`):
"Mark foreign (passport) patients now; calculate SST only after the accountant confirms the rate and rules."
Design note for review (Kumar). Synthetic data only; nothing here is production approval.

## 1. What changes

- Billing shows a plain marker for a foreign patient, with "SST: to be confirmed". Places:
  - the Patient Billing page;
  - Manage payments;
  - the completed-visit Invoice;
  - optionally the invoice print (section 4).
- No amount, rate or calculation anywhere. Totals, due now, receipts, reports, CSVs and Panel claims stay exactly as
  today. SST stays out of the money model until the accountant confirms the rate and the rules, and that will be its
  own slice with the owner's decision.

## 2. Who counts as foreign (rule for the owner to confirm)

The patient master already records each identifier's type and issuing country (`patient_identifiers`:
`identifier_type` nric or passport, `issuing_country_code`, `retired_at`). A Malaysian IC is always issued by MY (a
PostgreSQL check).

**Proposed rule:** a patient is foreign when **both** of these hold:

- they have **no current Malaysian IC**;
- they have **a current passport issued by a country other than Malaysia**.

So a Malaysian with a Malaysian passport is not foreign, and neither is a person holding both an IC and a foreign
passport.

The optional `nationality_code` field is not used, because it is often blank. That is a question for the owner or the
accountant: should a blank IC with a nationality other than Malaysian also count?

The rule lives in one place, `app/Domain/Patient/Services/ForeignPatientRule.php` (read only), so the future SST slice
uses the same answer.

## 3. Data and access

- **Read only.** No migration, table, column or permission change is expected.
- The marker shows to the people who already see that page; it adds no new data. "Foreign patient (passport)" is a
  yes/no flag. The passport number and the country are not added to any billing payload, page or audit row.
- The flag is computed on the server and sent as `billing.patient.foreign` (true or false) and
  `invoice.patient.foreign`. The page does not work it out.
- **Snapshot of the decision:** the flag reflects the patient's identifiers at the time the page loads. If the
  identifiers are corrected later, the marker follows the correction. That is acceptable because nothing is charged on
  it.

## 4. The invoice print: owner choice

Issued invoice prints are frozen at first print (BIL-5). A reprint whose content differs becomes a new version marked
REVISED.

- **Option A (recommended): screens only for now.**
  - The marker shows on staff screens; the print is unchanged.
  - When SST is confirmed, the print gains the SST line together with the real amount, in the SST slice.
  - This avoids printing "SST: to be confirmed" for patients to read, and avoids any REVISED reprints now.
- **Option B: also on the invoice print.**
  - Adds a line "Foreign patient (passport) · SST: to be confirmed" under the patient name, for foreign patients only.
    The field is added to the print content only when true, the same way BIL-10's refund credit is added, so every
    other patient's printed copies keep their content and hash.
  - The effect: reprinting an already issued invoice for a foreign patient creates a new version marked REVISED.
  - Receipts are unchanged either way.

## 5. Screens (Daniel's look)

- A neutral chip, "Foreign patient (passport)", beside the patient name.
- Under it, a muted line: "SST: to be confirmed (no SST is charged yet)."
- No colour that suggests an error. 44 px is not affected (it is not a control).
- Classic only (the V2 billing pages were removed in CO-5).

## 6. Tests

- **Rule unit test.** Cases:
  - IC only → no;
  - passport MY → no;
  - passport TH → yes;
  - IC plus passport TH → no;
  - a retired IC with a current passport TH → yes;
  - no identifiers → no.
- **Feature tests:**
  - the Billing, Manage payments and completed Invoice props carry `foreign` correctly;
  - no passport number or country appears in those props;
  - totals are byte-identical to before for a foreign patient.
- **If Option B:**
  - a print snapshot for a non-foreign patient is unchanged, with the same content hash;
  - a foreign patient's print shows the line.
- **Frontend tests:** the chip and line wording, and that it shows only when `foreign` is true.
- **Existing assertions:**
  - I expect two exact-key-list assertions on the billing and invoice patient props to need `foreign` added. These
    would be changed assertions, listed for the owner's OK before any edit and left failing until then.
  - Option B may also touch a print-content assertion; same rule.

## 7. Questions for the owner

1. Is the rule in section 2 right? Should a nationality other than Malaysian also count when there is no IC?
2. Print: Option A (screens only, recommended) or Option B (also on the invoice print)?
