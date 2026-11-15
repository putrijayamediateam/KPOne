# Panel pilot setup checklist

**Status:** draft for the Panel team's Lead and the clinic. It authorises nothing and records no result. Panel
claiming is inside the pilot (owner decision, relayed 2026-11-15). The pilot is one branch beside Yezza, with
synthetic data until the go-live gates close, so every Panel below is entered as a **test record** until the owner
decides otherwise.

**Who does it:** the roles named in each row. **Where:** KPOne screens, on the test system first.
**Kinds:** SETUP (data entry), PEOPLE (someone outside KPOne signs off), DECISION (owner), TEST (a check by hand).

## 1. Accounts (SETUP, Director)

| Done | Item | Where and how | Check |
|---|---|---|---|
| [ ] | One Panel Officer account per person, each with their own login (never shared, so audit rows name the person) | Staff create; assign the Panel Officer role and the pilot branch | The person signs in and sees Panel Claims in the top bar |
| [ ] | A second Panel Officer, or a Finance Officer, for the days the first is away | Same | Both can open the Panel Claims list |
| [ ] | A Finance Officer (and the Director) who can reverse a wrongly recorded Panel payment | Staff create | They can open Correct a Panel payment from Clinic management |
| [ ] | Panel approval limit for each person who approves Panel responsibility at the counter | Billing Approval Limits (Director only), capability "Panel", amount in sen with the ringgit echo | A proposed Panel amount within the limit is approved; one above it waits |

To confirm: who may create Panel Officer accounts at this clinic (the access rules may keep role assignment with
the Director).

## 2. Business details (SETUP, Director or Administrator)

The claim pack and every invoice print use the branch letterhead. A missing part is left out, never copied from
another branch.

| Done | Item | Check |
|---|---|---|
| [ ] | Clinic name, address, phone (and the second phone if the clinic has one), email | Document readiness card on Business details shows the branch as Ready |
| [ ] | Branch footer text (the owner supplies the wording) | The claim pack and invoice print show it |
| [ ] | Logo, if used (JPG or PNG, up to 2 MB) | Preview shows it on the claim pack |

## 3. Each Panel (SETUP, Panel Officer, Finance Officer or Director)

Checkout settings, Add panel. Do one row per Panel; a Panel is archived, never deleted, and its code never changes.

| Done | Field | Rule in KPOne | Check |
|---|---|---|---|
| [ ] | Panel name, company name, person in charge, phone, email, address | Phone digits, plus, brackets, dashes and spaces only | Saved without an error |
| [ ] | Panel type | Third Party Administrator or Direct company | Shown on the list |
| [ ] | Company registration (SSM) number | Exactly 12 digits, optional | The error "exactly 12 digits" appears for anything else |
| [ ] | Income Tax Number (TIN) for e-invoice | Letters, digits and dashes, up to 20; stored and shown only | Shown on the Panel |
| [ ] | Verification | A secure link starting with https://, or a manual remark (up to 500 characters) | The claim page shows the link or the remark |
| [ ] | Claim due rule | One of: N days after the visit (0 to 365); daily (by a time, same day to 3 days after the visit); weekly (by a time on the next named weekday); monthly (by a time on day 1 to 28 of the next month, or the last day of the visit month) | The Pending and Overdue lists and the Create claim page show "This Panel's rule: …" with the right due date |
| [ ] | Follow-up days | 1 to 365, default 30: days with no action before a claim shows on the Follow-up list | A claim older than the days appears on the Follow-up list |
| [ ] | Status switch on | Active | The Panel can be picked on Billing |

Price tier is not edited here: a Panel uses the default Panel price book unless it has its own (Pricing).
**Check with Finance:** the default Panel price exists for every consultation, medicine and service the Panels will
be billed for (Check price list shows "No default Panel price" otherwise).

## 4. Claim message template (SETUP, Panel Officer, Finance Officer or Director)

Checkout settings, Claim message template. Plain text, up to 2,000 characters, one per organisation. Smart fields:
`{panel_name}`, `{patient_name}`, `{patient_nric}`, `{staff_id}` (the member reference), `{claim_number}`,
`{claimed_amount}`, `{submission_date}`, `{claim_link}`, `{clinic_name}`.

| Done | Item | Check |
|---|---|---|
| [ ] | The template wording agreed with the Panel Officer | An unknown field is refused on save |
| [ ] | Copy message on a test claim | The fields are filled; `{patient_nric}` reads "(not available)" for someone who may not see identity |

KPOne sends nothing: the officer pastes the message into the Panel's own channel.

## 5. Payment methods (SETUP, Finance Officer, Administrator or Director)

| Done | Item | Check |
|---|---|---|
| [ ] | Every payment method the patient can use at the counter, each marked Cash or Non-cash | Daily summary classifies receipts the same way the clinic counts them |

## 6. Test walk with synthetic patients (TEST, Panel Officer, with a Finance Officer)

Use made-up patients only. Tick each when it works on the real screens.

| Done | Step | Expected |
|---|---|---|
| [ ] | Register a Panel visit, complete it, finalise the invoice, propose and approve the Panel amount | Invoice appears under Pending on Panel Claims |
| [ ] | Create claim (date, remarks, photos) | Claim is Submitted; the photos are listed |
| [ ] | Record a Panel query, then a decision (accepted, partly accepted or rejected) | Status follows; amounts match |
| [ ] | Panel payments: record a payment and split it across claims; try the statement helper with a test CSV | The split equals the payment; nothing records from the file by itself |
| [ ] | Correct a Panel payment (Finance Officer or Director, not the person who recorded it) | The claim steps back; totals follow |
| [ ] | Panel summary and Panel invoices for the branch | Figures agree with the claim pages |
| [ ] | Follow-up list | An old open claim shows, with its last action |

## 7. A4 claim pack printer test (TEST and SETUP, Panel Officer)

Real printer and paper at the pilot branch, synthetic claims only. Print from Chrome with scale 100%, headers and
footers off.

| Done | Check | Expected |
|---|---|---|
| [ ] | One claim, a few items | Letterhead, claim number, status, amounts and "Amount claimed (Panel share)" on one A4 page |
| [ ] | A batch of three claims of one Panel | A cover page, then one page per claim |
| [ ] | A claim with many items, long remarks and several photo names | Table headings repeat on the next page; note whether the closing block fits |
| [ ] | The IC or passport line | Shown only for someone with the identity permission; others see "Not shown for your role" |
| [ ] | Dark mode on screen, then print | The paper is white with dark text |

Known limits to note while testing: the pack is a live rendering today (a reprint shows the current status and
letterhead; a frozen copy is planned); photo file names print as uploaded and could carry clinical text; a claim
longer than one page repeats the table headings but not the letterhead or claim number.

## 8. Sign-offs outside KPOne (PEOPLE and DECISION)

| Item | Who |
|---|---|
| Whether the claim pack, its figures and the e-invoice details are acceptable | The clinic's accountant or tax adviser (KPOne makes no tax claim) |
| Footer wording on prints | Owner |
| Whether real Panels and real contact details may be entered, and when | Owner (until then, test records only) |
| Whether Panel statement files (patient names and Panel references, encrypted, deleted after 30 days) may be used with real data | Owner, after the privacy (PDPA) review |
