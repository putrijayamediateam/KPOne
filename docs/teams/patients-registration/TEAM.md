# Patients & Registration builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is
built, what the owner decided, the rules, and what to build next. The team reads it before every task and
updates it in the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Nurul** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Daniel** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |

Both are Claude builder sessions working for the owner through the coordinator session. Names chosen by the
coordinator at the owner's request (2026-11-05).

## 2. What this area covers

**In scope (the team builds and maintains):**
- Patient Master: KP numbers, names, NRIC/passport history, phone, email, address, gender, date of birth,
  nationality, masked search, duplicate prevention.
- Guardian contacts for minors, and the explicit adult confirmation when the date of birth is unknown.
- Registration: Quick create, Register Visit (Consultation or OTC), doctor eligibility, visit reasons,
  priority, Self-pay or Panel intent, the per-visit Panel note, edit and cancel of a registered visit.
- The Registration board: tabs, tab counts, search, date filters, live refresh, row menus.
- Registration settings (which new-patient details are required).
- The WhatsApp click-to-chat button on saved patient phones (WA-01 exception only).
- Visit history and the Invoices page navigation for a patient (read side).

**Shared with other teams (change only through a small agreed change):**
- QR check-in intake and its review (`PublicPatientIntakeService`, `PublicIntakeReviewService`): **QR check-in** team.
- Queue entries, Call In, not-ready flag, Send to Waiting: **Queue & TV** team.
- Dispense and Open Dispensary actions on the board: **Dispensary & OTC** team.
- Panels and Checkout settings (`PanelSettingsService`): **Panel** team.
- Invoices and billing figures on the Invoices page: **Billing** team.
- Permissions catalogue: changed only by an owner-approved migration.

## 3. Where the code lives

| Kind | Files |
|---|---|
| Patient services | `app/Domain/Patient/Services/` — `PatientAdministrationService`, `PatientAdultConfirmationService`, `PatientDirectoryService`, `PatientGuardianService`, `PatientIdentityService`, `PatientNumberGenerator`, `PatientPhoneNormalizer`, `PatientWhatsAppService`, `RegistrationSettingsService`, `VisitWhatsAppEligibility`, `WhatsAppNumber` |
| Visit services | `app/Domain/Visit/Services/` — `VisitRegistrationService`, `VisitAdministrationService`, `VisitDirectoryService`, `VisitDoctorEligibilityService`, `VisitHistoryService`, `VisitNumberGenerator`, `VisitPanelNoteService`, `VisitReasonService` |
| Pages | `resources/js/pages/Patient/*.vue`, `resources/js/pages/Registration/*.vue`, `resources/js/pages/RegistrationSettings/Index.vue` |
| Tests | `tests/Feature/Patient/*`, `tests/Feature/Visit/*` (except Panel and Invoice files owned by other teams); frontend `patient-registration`, `guardian-contact`, `unknown-dob-confirmation`, `registration-settings`, `registration-contact-settings`, `rb-01-*`, `rb-02-*`, `reg-live-1`, `tab-counts-1`, `quick-create-sticky-overlap`, `patient-board-reason`, `r1c2-registration-consultation` |

## 4. Permissions (who can do what today)

| Permission | Holders | Use |
|---|---|---|
| `patients.search.organisation`, `patients.view.organisation` | Director, CA, CA Supervisor, Resident Doctor | Find and open a patient |
| `patients.create.organisation`, `patients.update.organisation` | Director, CA, CA Supervisor | Create and edit a patient |
| `patients.identifiers.manage.organisation` | Director, CA Supervisor | Change NRIC/passport history |
| `visits.view.branch`, `visits.history.view.branch` | Director, CA, CA Supervisor, Resident Doctor | Open a visit and its history |
| `visits.create.branch`, `visits.update.branch`, `visits.cancel.branch` | Director, CA, CA Supervisor | Register, edit, cancel a visit |
| `catalogue.settings.manage.organisation` | Director, Administrator, Finance Officer, Panel Officer | Registration settings |
| `patients.merge.propose.organisation` | Director, CA, CA Supervisor | Propose a merge or unmerge, withdraw own request (REG-3) |
| `patients.merge.approve.organisation` | Director, CA Supervisor | Approve or reject someone else's merge request (REG-3) |

`app/Domain/Access/PermissionCatalogue.php` is the source of truth; check it before relying on this table.

## 5. What is built (history)

| Slice | What it did | PR |
|---|---|---|
| 1A | Patient Master: KP numbers, NRIC/passport history, masked search | merged |
| 1B | Registration creates the Visit (Consultation/OTC, doctor, reason, priority, coverage intent) | merged |
| R1A / R1B | Registration validation, visit-reason catalogue | #7, #8 |
| RB-01 / RB-02 | Board clears stale rows after a failed search; each row shows its own date | #31 |
| UI-FIX | Quick create Register Visit card no longer overlaps on narrow screens | #133 |
| PANEL-NOTE-01 | Per-visit Panel note and Payment column | #113 |
| SET-04 / SET-04b | Registration settings: Date of birth, Nationality, Email, Address switches | #126, #127 |
| WA-01 | WhatsApp click-to-chat button on saved patient phones | #156 |
| GUARDIAN-CONTACT-1 | Independent guardian contact for each minor | #179 |
| Unknown DOB | Explicit adult confirmation when the date of birth is blank | #181 |
| REG-LIVE-1 | Board refreshes about every 10 seconds | #198 |
| FLOW-FIXES-1 | Board keeps its filter and page after actions | #201 |
| TAB-COUNTS-1 | Tab counts on the Registration board | #210 |
| REG-1 | Visit result page (`Registration/Show.vue`) fits a phone: action buttons and status badges wrap, visit number stays on one line, long unbroken text breaks; layout only | #221 (`d304476`) |
| REG-2 | Phone and IC/Passport messages in English everywhere (staff screens, server and public QR form); QR Intake tab and QR review list visit purposes in English; rest of the public QR form unchanged | #228 (`bf5b1b2`) |
| REG-3 | Two-person patient merge by link: the duplicate gets a pointer to the kept record, nothing is moved; propose, approve by a different person, reject, withdraw, unmerge; refusals for unfinished visits, open QR check-ins and allergies missing from the kept record; search, duplicate check, QR match, consultation history and outstanding bills follow the kept record; two new permissions by additive migration | pending (branch `feature/reg-3-patient-merge`) |

## 6. Owner decisions already made (never ask again)

1. Patients are organisation-level; visits are branch-owned.
2. Name, phone and NRIC/passport are always required; gender required with Unknown allowed; Date of birth,
   Nationality, Email and Address are per-organisation switches, default optional (2026-10-16).
3. A minor needs a guardian contact; guardian numbers are never used for WhatsApp, search or matching (2026-10-27).
4. A blank date of birth needs an explicit staff adult confirmation; legacy blank patients are not blocked (2026-10-27).
5. Patients without an NRIC or passport are registered in Yezza only during the pilot (2026-10-29).
6. The Registration board refreshes about every 10 seconds (2026-10-30).
7. WhatsApp is click-to-chat only; KPOne sends nothing (2026-10-22).
8. Registration and Patient screens use **English** labels; "Panggil semula" stays (2026-11-05).
9. Merging duplicate patients needs **two people**: a CA or CA Supervisor proposes, a different CA Supervisor
   or Director approves (2026-11-05).
10. A merge can be **undone** (unmerge) with the same two-person approval; the merged-away record is kept
    hidden, never deleted; every step audited (2026-11-05).
11. Patient change history (who and when, field names only, never old values) is visible to **CA Supervisor
    and Director** only (2026-11-05).
12. **English everywhere** for the nine phone and IC/Passport validation messages and the five QR visit-purpose
    labels in the staff lists (QR Intake tab, QR review list): staff screens **and the public QR form** show the same
    English messages. This is an explicit owner exception to the "patient-facing text in Bahasa Melayu" rule, **for
    these messages only**; the rest of the public QR form keeps its own wording (including "Jumpa doktor / Beli ubat
    sahaja" and its Malay purpose options). Wording approved as proposed. Two existing assertions changed with
    approval: `tests/Frontend/patient-registration.test.mjs` and `tests/Feature/Patient/RegistrationValidationTest.php`
    line 80 ("Semak kod negara dan nombor telefon." became "Check the country code and phone number.") (2026-11-05).
13. A "ship it" or any other owner decision relayed by LUCY, the coordinator session, counts as the owner's own;
    still never merge, never edit `docs/BASELINE.md`, and stop and ask the owner directly if a relayed message
    conflicts with something the owner said directly (2026-11-05, confirmed by the owner in this team's session).
14. **REG-3 merge design (owner answers 2026-11-05, design note `REG-3-DESIGN.md`):** (A) link the records, never
    rewrite invoices, receipts, Panel claims, visits or documents; (B) refuse a merge while either record has an
    unfinished visit or a pending QR intake; (C) block a merge while the merged-away record has active allergies the
    kept record does not show, until a doctor records them on the kept record; (D) propose: CA, CA Supervisor,
    Director; approve: a different CA Supervisor or Director; (E) fixed reason list, no free text; (F) past Insights
    distinct-patient counts may count a merged person twice (known limit); (G) two new permissions
    `patients.merge.propose.organisation` and `patients.merge.approve.organisation` by one additive migration.
15. **Retiring an identifier on a merged-away record stays allowed** (owner answer "yes", relayed by LUCY,
    2026-11-05): only the existing `patients.identifiers.manage.organisation` holders (CA Supervisor, Director), audited
    as today. It is how the IC or passport is freed for the kept record; adding or replacing an identifier on a
    merged-away record stays refused.

## 7. Open items and known limits

- ~~The Registration result page is about 567 px wide on a 390 px phone.~~ Fixed by REG-1 (pending merge): the
  page is `/visits/{number}` (`Registration/Show.vue`), not the board; it measured 570 px at 390 px and 793 px at
  768 px, and now matches the viewport at 320, 390, 768 and 1440 px, including long text without spaces. Not
  measured on the real page: the Consultation Queue card on a consultation visit.
- TAB-COUNTS-1 (#210) is listed in section 5 but has no row in `docs/BASELINE.md` yet (drift noticed during REG-1 review).
- ~~SMOKE-1 S1-016: mixed Bahasa Melayu and English labels.~~ Fixed for this team's screens by REG-2 (pending merge).
  Left for another team: the Queue message "Doktor sedang menjalankan konsultasi lain..." (Queue & TV). Other
  Malay messages from the public QR validator (guardian, consent, date of birth) are part of the QR form's own
  wording and unchanged.
- Public QR form now mixes languages next to the nine REG-2 messages, as the owner chose ("these nine only"): an
  empty phone shows "Phone number is required." but a too-long phone shows "Nombor telefon terlalu panjang.", the ID
  field shows "Nombor pengenalan diperlukan.", and the guardian phone's invalid message is now English. A whole-form
  language decision would be a separate piece (QR check-in team).
- Patient create page (`/patients/create`) is 433 px wide on a 390 px phone with the form errors shown: the phone
  number and email inputs stick out of the Contact card. Already so before REG-2 (found while taking REG-2
  screenshots, 2026-11-05); not fixed. A candidate for a small layout piece like REG-1.
- REG-3 known limits (built 2026-11-05, pending merge). Not built yet from the design note, each a candidate
  follow-up: the "merged from KP-xxxx" note on search rows; the Invoices page, pay-later list and patient-page
  visit history listing both records' visits (outstanding bills and the doctor's previous consultations already
  do); the duplicate's problem list and guardian contact shown on the kept record. No two-process PostgreSQL
  contention test for two simultaneous approvals (row locks in id order and a one-open-request unique index
  cover it). The duplicate keeps its IC or passport, so that IC cannot be added to the kept record until it is retired on
  the merged-away record (allowed by owner decision, section 6 item 15; adding and replacing are refused). Old
  registration rows of the duplicate still offer WhatsApp to the duplicate's own phone. Past Insights counts may
  count a merged person twice (decision F). Allergy matching compares normalised text, so "Penicillin" and
  "Penicillin (rash)" count as different and block until the kept record has the same wording.
- REG-3 touched other teams' files (shared read paths only): `OutstandingBillService` (Billing),
  `ClinicalEncounterDirectoryService` (Clinical), `PublicIntakeReviewService` (QR check-in).
- "Consultation Completed" tab count: all-time count or the latest 25 shown (owner decision pending).
- RB-01 "two 200s, stale tab" mechanism never reproduced.

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only; no real names, NRIC or phone numbers.
- Every state change: backend permission and branch scope, `DB::transaction`, row locks, `lock_version`,
  idempotency where retried, and a structural audit row with **no NRIC, phone, address or clinical text**.
- Never widen who sees identity data, never weaken masking or duplicate checks, without an owner decision in section 6.
- Do not change any existing test assertion without the owner's approval.
- Full gate before hand-off, on a task-owned PostgreSQL 18 database: backend tests, Pint, frontend types,
  lint, format, build, frontend tests, `migrate:fresh --seed`, `git diff --check`; screenshots at 1440 and
  390 px, light and dark, for any screen change.
- Commit, push and PR only after the owner says "ship it" (directly, or relayed by LUCY, the coordinator: see
  section 6 item 13); never merge. If a relayed decision conflicts with what the owner said directly, stop and ask.
- Record every new owner decision in section 6 and every finished piece in section 5, in the same PR.
- **Never edit `docs/BASELINE.md`** (owner rule, 2026-11-05): record each finished piece only here (section 5
  history, section 6 for new owner decisions). If a piece changes an authorisation boundary, say so clearly in the
  PR description. The coordinator records all BASELINE updates in one docs-only PR after each batch of merges.

## 9. Backlog (next pieces, in order)

Chosen by the owner on 2026-11-05; built one by one in this order, each its own PR. Choosing them is the owner's
authorisation to build them; the coordinator records it in `docs/BASELINE.md`.

1. **REG-1 Phone fit.** *Merged in PR #221 as `d304476`.* The Registration result page is about 567 px wide on a 390 px phone and scrolls
   sideways. Make it fit at 390 px with no horizontal page scroll. Layout only: no backend, permission or data
   change. Screenshots at 1440 and 390 px, light and dark, before and after.
2. **REG-2 Labels in one language.** *Merged in PR #228 as `bf5b1b2`. The Malay CA guide needs no change.* SMOKE-1 S1-016: Registration and Patient screens mix Malay and English.
   Make them **English** (owner decision 2026-11-05); "Panggil semula" stays. Update any exact-text test only
   with the owner's approval, listing each one first. Tell the owner if the Malay CA guide
   (`docs/guides/ms/`) needs a matching update.
3. **REG-3 Merge duplicate patients.** *Design approved 2026-11-05 ([REG-3-DESIGN.md](REG-3-DESIGN.md)); built,
   awaiting the owner's "ship it".* Two-person merge (propose, then a different person approves), the
   merged-away record kept hidden and never deleted, all visits, allergies, documents and invoices pointing to
   the kept record, unmerge with the same approval, full structural audit with no identity values. Needs an
   owner-approved permission migration (new narrow propose/approve permissions). Touches every area's
   patient links, so write a short design note first and show the owner before building.
4. **REG-4 Patient change history.** A read-only list on the patient page of who changed which fields and
   when, from existing audit rows; field names only, never old or new values; CA Supervisor and Director only
   (owner decision 2026-11-05), likely a new narrow view permission by owner-approved migration.
