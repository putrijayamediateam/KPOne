# BIL-29: mark foreign (passport) patients for SST

Owner decision 12 (questionnaire of 20-21 Nov 2026, renumbered BIL-29):
"Mark foreign (passport) patients now; calculate SST only after the accountant confirms the rate and rules."
Owner round 2 (21 Nov 2026): **17 = A**, foreign means a passport issued by a non-MY country and no IC (section 2 as
written); **28 = A**, screens only (Option A in section 4; the invoice print is unchanged).
Design note for review (Kumar). Synthetic data only; nothing here is production approval.

## 1. What changes

- Billing shows a plain marker for a foreign patient, with "SST is not charged yet." (owner, 22 Nov 2026). Places:
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

- **Option A: screens only for now. Chosen (owner 28 = A).**
  - The marker shows on staff screens; the print is unchanged.
  - When SST is confirmed, the print gains the SST line together with the real amount, in the SST slice.
  - This avoids printing "SST: to be confirmed" for patients to read, and avoids any REVISED reprints now.
- **Option B: also on the invoice print.** Not chosen.
  - Adds a line "Foreign patient (passport) · SST: to be confirmed" under the patient name, for foreign patients only.
    The field is added to the print content only when true, the same way BIL-10's refund credit is added, so every
    other patient's printed copies keep their content and hash.
  - The effect: reprinting an already issued invoice for a foreign patient creates a new version marked REVISED.
  - Receipts are unchanged either way.

## 5. Screens (Daniel's look)

- A neutral chip, "Foreign patient (passport)", beside the patient name.
- Under it, a muted line: "SST is not charged yet." (owner "BIL-29: A, ship it", 22 Nov 2026, Daniel's wording; first drafted as "SST: to be confirmed (no SST is charged yet).")
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
- **If Option B** (not chosen; kept for the record):
  - a print snapshot for a non-foreign patient is unchanged, with the same content hash;
  - a foreign patient's print shows the line.
- **Frontend tests:** the chip and line wording, and that it shows only when `foreign` is true.
- **Existing assertions:**
  - I expect two exact-key-list assertions on the billing and invoice patient props to need `foreign` added. These
    would be changed assertions, listed for the owner's OK before any edit and left failing until then.
  - Option B may also touch a print-content assertion; same rule.

## 7. Owner answers and review

- 17 = A: the rule in section 2 stands; nationality is not used.
- 28 = A: screens only. Issued prints keep their content and hash (the frozen snapshot copies only the patient name
  and number from the screen props; the flag is not in it), so reprinting a foreign patient's issued invoice is a COPY
  with the same hash. A change to the patient's identifiers still makes a REVISED copy, as before BIL-29, because the
  print shows the current IC/passport line (BIL-9).
- As built, no existing assertion needed changing (the expected exact key lists in section 6 did not pin these
  props).
- Accepted (section 3): Billing summary holders without `patients.view.organisation` see this yes or no fact, which
  is derived from identity data; they never see the identifier, its type value or its country.
- Kumar's review (22 Nov 2026): approve with changes; the docs, the skipped computation when printing and the extra
  tests were added.
- Wording (owner 22 Nov 2026, "BIL-29: A"): the chip stays and its line reads "SST is not charged yet." (Daniel: "to be
  confirmed" is a note for Finance, not the front desk). Daniel: look OK.
