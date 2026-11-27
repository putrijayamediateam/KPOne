# DOC-05 — Attendance confirmation (local candidate)

Owner-authorised attendance confirmation only, based on canonical main `462aad1` (through PR #152), in `/private/tmp/KPOne-doc05`, branch `feature/doc-05-attendance-confirmation`. Local candidate only; no push, PR or merge. The served tree remains unchanged.

## What changes

A date-only attendance statement, explicitly **not a Medical Certificate** and never a certification of illness, fitness or leave. It contains patient name and KPOne patient number, clinic letterhead, the Encounter's branch-local examination date as attendance date, issue date, ATT number and Visit reference, verified professional name/MMC, and a paper signature space. No DOB, identity document number, attendance times or durations, diagnosis, symptoms, reason for consultation, medicines, allergies, clinical notes, vitals or sick-leave days are sourced or rendered. The only free-text input is an optional single-line intended recipient, maximum 200 characters. Recipient controls/bidi characters are refused; output is escaped. A nonblank recipient requires explicit patient-agreement acknowledgement, retained inside encrypted evidence. The issue screen warns: “Do not enter a diagnosis, symptoms or medical details here.” It also retains the written-consent/guardian caveat. The doctor must follow the clinic's actual consent procedure; a tick does not establish compliance.

Doctor access is from a permission-filtered clinical-workspace link in a separate tab, preserving unsaved clinical work. Only saved minimal source data is loaded. Reviewed preview is required before issue. There is own history, replacement and void. Each Encounter can have multiple independent attendance chains, for example different recipients. First issue requires the assigned attending clinician's consultation to be Serving, not held and in progress, before any clinical checkout. Reopening does not restore first issue. Replace/void are original-issuer only, including after checkout; replacement gets a fresh number, and void closes that chain. Replacement begins with a blank recipient rather than silently copying prior disclosure details.

CA/CA Supervisor get a current-issued COPY link on the existing Visit details page (the smallest existing visit surface), only when at least one current issued document exists. No composer, source, preview, correction or void authority is granted. Replaced/voided documents are absent from these current entry links; an authorised direct retained-copy opening shows NOT VALID. Branch changes cancel in-flight source requests, clear drafts/previews and hide prior history/entry links.

## Permissions

- Resident Doctor: `attendance_documents.issue.own`, `attendance_documents.view.own`, `attendance_documents.print.own`.
- CA and CA Supervisor: `attendance_documents.view.branch`, `attendance_documents.print.branch`, issued documents only at their effective branch.
- Director, Administrator, Technical Admin, Finance Officer, Panel Officer, HR, Marketing, BD and queue-display roles: **none**.

Backend services re-read grants and effective branch assignments under actor locks; access is permission-based, never a runtime role-name check. Own reads and writes enforce source/attending ownership and retained original-issuer ownership. Branch copy reads load only issued attendance evidence and non-clinical Visit context, never clinical source content. No settings permission is granted. Migration grants only missing permissions to existing authorised roles, is replay-safe, and down never revokes. Reference catalogue matches.

## Migrations and shared code

- `2026_10_23_000100_create_attendance_evidence.php`: additive `attendance_template_revisions`, `attendance_chains`, `attendance_number_counters`, `attendance_documents`, `attendance_actions`. Fixed EN/BM draft revision 1. Scoped composite foreign keys, one-first-action partial index, append-only evidence/action/template protections, source ownership guard, retained metadata and monotonic versions/counters. PostgreSQL refuses UPDATE, DELETE and TRUNCATE of evidence/actions/templates; controls/counters also refuse deletion/truncation and invalid transitions. Down retains evidence.
- `2026_10_23_000200_grant_attendance_permissions.php`: firstOrCreate and missing grants only, down never revokes.
- `BranchDocumentNumberAllocator`: a small explicit allowlist for referral/REF and attendance/ATT, each with its own table and advisory-lock namespace. Referral's existing table, namespace, prefix, date logic and errors are unchanged. ATT numbering is `ATT/KP/{CHR|SGB|PCG}/{branch-local issue year}/{6 digits}`, allocated under branch/year lock only at issue, never recycled. Unsupported pairs refuse. No MC counter change.
- Reuse `DocumentEvidenceCodec`, `DocumentLetterheadCapture` and `ReferralPagination` directly, without modifying them. No document framework or MC/referral data migration. Attendance-specific source, authority, service, snapshot, fixed wording, controller, Vue page and escaped Blade views.
- Existing presentation edits: clinical new-tab link, Visit details current-copy projection/link, attendance routes, permission catalogue and this baseline/report. No other document type or stub.

## Safety, evidence and audit

Every issue/replace/void uses fresh organisation, permission and effective-branch checks, transaction, consistent patient → Visit → Queue → Encounter lock order, independent attendance-chain lock_version, source-version token, preview token, UUID retry key and payload-conflict refusal. Same retries do not allocate another number. Only attendance controls/counters/documents/actions and a values-free audit may be written. No Encounter checkout/finalisation, Visit/Queue transition, charge, billing, claim, invoice-log, Insights, dispensary or stock action.

Encrypted immutable pre-signature snapshots retain the exact escaped HTML/page lines, fixed wording/revision, renderer `attendance-a4-v1`, number, patient name/KP number, dates, verified professional identity, captured branch letterhead/logo, optional recipient and acknowledgement, source token and UTC instant. No clinical source text is loaded or stored. Missing verified credentials or incomplete branch name/address/contact blocks issue. Missing logo may be omitted; configured unavailable/corrupt logo blocks issue. No cross-branch fallback. Required letterhead validation and image integrity capture reuse DOC-04 unchanged.

Read/history/preview/source/current-copy-list/print-view openings are audited; audit failure blocks content release. Read events never claim the paper was printed, wet-signed or delivered. Metadata contains controlled field names, ids and versions only; no patient names, recipient, document body, clinical text or amounts. No file/job/email delivery or application PDF archive. No purge/delete job; replaced/voided snapshots are retained.

Full ID-ordered before/after row comparisons cover all public tables except the declared attendance records and audit. Fixtures populate an invoice, receipt/allocation, approved Panel responsibility and prepared claim, dispensary/stock evidence, an issued MC and an issued referral. Preview, first issue, retry, replacement, void, history, doctor copies and CA current-copy/copy reads leave all compared rows identical, including MC/referral evidence; earlier audit rows also remain identical.

## Wording and print

Both EN/BM revision 1 records remain **DRAFT / production_approved=false**. No production approval or approval UI. EN says: “This document confirms attendance only. It does not certify illness, fitness or leave. It is not a medical certificate.” BM has the corresponding explicit limitations. These are draft statements for doctor review, not legal or clinical approval.

A4, Arial, white page/dark text in either theme; normal EN and BM documents each occupy one page, correctly labelled 1/1, with the wet-signature block after all content. Reused deterministic pagination preserves all text if a longer saved identity/letterhead requires continuation; no truncation or shrinking. Repeated identity/reference/page numbers remain, signature only on the last page. Current copies show COPY/CURRENT; superseded or voided copies also show NOT VALID. Only pre-signature content is retained; signed paper and its duplicate remain the clinic's responsibility.

## Every changed existing assertion

**None.** No pre-existing test file/assertion or skip condition is edited, weakened, removed or skipped. Attendance tests are new; the complete existing MC/referral/label/billing-print suites run unchanged. No new hub destination or hub-map assertion change.

During implementation, new fixture assertions were corrected to match the actual immutable-trigger messages and to compare normalized text across the entire rendered HTML (line wrapping must not change the wording assertion). The middleware uses comma-separated any-permission arguments, so the new copy route was corrected accordingly. New list output is explicitly array-valued so JSON cannot become an object. An early complete backend run was stopped before completion when manual review found PHP's `empty("0")` recipient edge; final validation uses an explicit nonblank-string check and regression cases for recipient `"0"` and non-string input. No stopped/pre-fix run is counted as release evidence. No existing assertion was altered.

## Tests

New `AttendanceTest` covers exact catalogue grants/denials; doctor-only issue/source/history, CA issued-copy only; fresh revocation/replay; branch/organisation/effective-assignment isolation; other-doctor refusal; Serving/hold/checkout/reopen; original-issuer replace/void; independent chains; reviewed retries/conflicts and monotonic counters; required credential/letterhead/logo integrity; recipient bounds/control characters/conditional acknowledgement (including `"0"`); stale source/preview; no clinical/DOB/identity/unsaved browser data even when clinical records exist; immutable stable encrypted EN/BM draft snapshots; date-only and normal one-page print/last signature; read/write audit failure; values-free audit; and full-table invariance with populated MC/referral/financial/claims/dispensary sources.

`PostgresAttendanceNumberTest` uses two actual worker processes to prove locked consecutive unique allocation, without seeding global permission grants; grant rows remain identical. A separate attendance test proves referral counters remain identical and unsupported type/prefix pairs refuse. No contention test is skipped.

Three new frontend tests compile the actual composer, pin recipient-only free text/consent/saved data, branch-change cancellation/hiding, permission-filtered clinical and CA links, and escaped A4 Arial/date-only/white-paper/last-signature structure. The existing referral suite and pagination tests pass untouched alongside attendance: pre-final consent refinement focused result 32 tests / 1,057 assertions; final attendance-focused result 13 tests / 407 assertions.

## Validation and evidence

Final full-gate results and screenshot manifest are recorded below after completion. All artifacts are synthetic `.test` review files in `/private/tmp/doc05-evidence/`, not production records or an application storage feature.

Task-owned PostgreSQL **18.6**, host **127.0.0.1**, port **58428**, user **doc05**, datadir `/private/tmp/doc05-pg`. Effective and actual database host/port/name verified before requests. Backend database `kpone_doc05_testing`; separate fresh-migrate/seed/browser database `kpone_doc05_seed_testing`. No other database/port touched. No `.env` opened or created. No new dependency.

## Open questions and deferred scope

- A doctor must approve both EN/BM wording and labels; they remain DRAFT. Approval needs a deliberate future revision/migration, without rewriting prior snapshots.
- Clinic owner must approve written-consent/guardian procedures and signed-paper duplicate retention/legal holds. A recipient acknowledgement does not prove compliance. The doctor must keep recipient text free of medical details; no semantic text classifier is added.
- Safest narrow choices: no recipient directory, no automatically copied recipient on replacement; acknowledgement required only for a nonblank intended recipient; CA entry on existing Visit details rather than a new board workflow. CA current links exclude replaced/void, but retained invalid copies remain available to authorised readers by direct link.
- No medicines-supplied summary, prescription, clinical summary, employer/insurer report, quarantine/fitness/leave certification or other document. No configurable template/editor, uploads, electronic signatures/delivery, PDF archive, fee, purge, legal claim of physical attendance times or implication of production approval.

### Chrome and PDF evidence

Real Chrome with production assets and the separate synthetic seed database. Existing zoom was 80%; viewport overrides were calibrated by reading `innerWidth` and document scroll width: override 1152 → **1440 CSS px**, override 312 → **390 CSS px**, with no horizontal overflow. Temporary overrides were reset and the original dark appearance restored. Agent-created tabs were closed. Viewport captures were used instead of full-page stitching to avoid sticky-navigation screenshot artifacts.

All paths below are under `/private/tmp/doc05-evidence/`:

| Evidence | Files |
| --- | --- |
| Doctor issue form, including no-medical-details warning and recipient acknowledgement | `issue-{1440,390}-{light,dark}.jpg` |
| Reviewed EN/dark and BM/light preview | `preview-{1440,390}-{light,dark}.jpg`; `preview-390-light-end.jpg` shows the ending on the stacked mobile view |
| Own history, local times and current/replaced/void status | `history-{1440,390}-{light,dark}.jpg` |
| CA current-issued entry on Visit details | `ca-entry-{1440,390}-{light,dark}.jpg` |
| CA permitted COPY | `ca-copy-1440-{light,dark}.jpg`, `ca-copy-390-dark.jpg` |
| CA denied doctor history/composer | `ca-denied-{1440,390}-{light,dark}.jpg` |
| Director denied issued-copy access | `director-denied-{1440,390}-{light,dark}.jpg` |
| Current COPY | `copy-en-{1440,390}-light.jpg`, `copy-bm-{1440,390}-{light,dark}.jpg` |
| Invalid replaced and void copies | `replaced-{1440,390}-{light,dark}.jpg`, `void-{1440,390}-{light,dark}.jpg` |
| Native Chrome Save-as-PDF preview | `a4-en-native-preview.jpg`, `a4-bm-native-preview-dark.jpg` |
| Actual browser-saved synthetic review PDFs | `attendance-en.pdf`, `attendance-bm.pdf` |
| Every physical PDF page rendered/visually inspected | `attendance-en-1.png`, `attendance-bm-1.png` |
| PDF page/font inspection | `pdf-en-inspection.log`, `pdf-bm-inspection.log`, `pdf-fonts.log` |

Both PDFs have **one A4 page (594.96 × 841.92 pt)**, labelled 1/1, **Arial only**, white page/dark text, signature after all content, no clipping or shrinking. The BM PDF was saved after choosing dark app appearance; its print remains white. System PDFKit inspected/rendered these files without adding an application dependency. No application PDF/signed-original archive was added.

The actual browser preview → issue flow created a new BM document and refreshed the numbered history. CA links expose only current documents, with no issue/correct/void controls; current-copy opening succeeds and doctor history refuses with a content-free 403. Director copy opening also refuses with 403. Automated tests cover all catalogue roles, other-doctor access, revocation and cross-branch/organisation requests.

### Final gate

All required checks passed on the final consent-safe runtime/test code. Only this complete backend run is counted as final release evidence; the earlier stopped run is not.

| Check | Result / log under `/private/tmp/doc05-evidence/` |
| --- | --- |
| `/private/tmp/doc05-run php -d memory_limit=1G artisan test -d memory_limit=1G` | **1,236 total: 1,234 passed, 2 unchanged public-registration skips; 19,980 assertions**, 512.825 seconds. `backend-final.log`. No PostgreSQL contention test skipped. |
| Pint `--test` | Passed, `pint-final.log`. |
| PHPStan / Larastan level 7, memory 1G | Passed, zero errors, `phpstan-final.log`. |
| `npm run types:check` | Passed, `types-final.log`. |
| `npm run lint:check` | Passed, `lint-final.log`. |
| `npm run format:check` | Passed, `format-final.log`. |
| `npm run build` | Passed, `build-final.log`. |
| `node --test tests/Frontend/*.mjs` | **394 passed**, no failures/skips, `frontend-final.log`. |
| `migrate:fresh --seed`, separate `kpone_doc05_seed_testing` | Passed, `seed.log`; effective/actual connection recorded in `seed-db-verified.log`. |
| Working and staged `git diff --check` | Passed. |

Unchanged existing MC, referral, drug-label and billing-print suites are included in the complete backend/frontend gates. **Every changed existing assertion: none.** The synthetic Chrome/PDF examples use ordinary acknowledged recipients or no recipient; the final nonblank-recipient fix changes only refusal of unacknowledged `"0"` and malformed recipient input, covered by regression tests, and does not change their rendered content.

Complete diff: `/private/tmp/doc05-evidence/change.diff`; changed-file manifest: `/private/tmp/doc05-evidence/files-changed.txt`; screenshot manifest: `/private/tmp/doc05-evidence/screenshots.txt`; calibrated widths: `/private/tmp/doc05-evidence/viewport-check.json`. Evidence is local review material, not publicly hosted. No push, PR or merge. No open implementation blocker; the clinic decisions above remain pending and do not turn DRAFT wording into approved production wording.
