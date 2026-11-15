# DOC-04 — Referral letters (merged in PR #151 as `b4b81ac`)

> Status update 2026-10-21: merged into `main` as `b4b81ac`; PR and post-merge CI green. The sections below are the original local-candidate handoff, kept as written, so statements such as "no push, PR or merge" describe the state before the PR.

Owner-authorised referral only, based on canonical main `305f369`. Isolated worktree `/private/tmp/KPOne-doc04`, branch `feature/doc-04-referral-letters`. Local commit only; no push, PR or merge authorised. The served tree is unchanged. Other document types are neither implemented nor stubbed.

## Behaviour and source

A permission-filtered **Referral letters (new tab)** link from the clinical workspace preserves unsaved clinical work. Only saved source records are available; no Encounter note is copied. Doctor-entered institution (required), department and named clinician (optional), required referral reason, urgency, history, findings and management are plain text. Limits: recipient fields 200 characters each, reason 1,000, each narrative section 4,000; at most 20 selected records per clinical section. Diagnosis, active doctor-prescribed medicines and active allergy records start unselected. Medicines are explicitly prescribed orders, not evidence of CA-final supply. Unknown allergy status stays unknown; it is never converted to no-known-allergies. No NRIC/passport, patient contacts, prices or claim data.

Patient name, KP number, recorded DOB if present, Visit reference, branch-local examination/issue dates, verified professional name/MMC number and captured branch letterhead identify the document. DOB is never invented. Preview is required; an HMAC binds the validated selection/wording to saved-source versions, branch presentation and letterhead. Intervening source or selected-content changes refuse issue. A new preview is required. Unsaved browser source fields are ignored and do not enter the snapshot.

An explicit acknowledgement of patient agreement to disclosure to the named recipient is required, retained encrypted with the recipient. The unchanged issue screen states that this tick does not prove the clinic's written-consent or guardian procedure was followed. Printed text contains only the doctor's acknowledgement; the agreement and recipient remain captured in encrypted evidence.

## Authority and lifecycle

New `referrals.issue.own`, `referrals.view.own`, `referrals.print.own`: Resident Doctor only. No new grants to CA, CA Supervisor, Director, Administrator, Technical Admin, Finance Officer, Panel Officer, HR, Marketing, BD or queue-display roles. Fresh grants, active organisation/branch, effective assignment and the attending Encounter/source owner are checked in the backend. Source/preview/write also require view authority; printing requires both own view and print. No settings permission is granted.

First issue: consultation, registered Visit, Serving, not held, in-progress Encounter, assigned attending doctor, no clinical checkout ever. Reopening does not permit a new first issue. Issuing never finalises the Encounter or changes Visit/Queue state. Multiple independent referral chains are allowed. Replace/void target only the original issuer's current document; a replacement receives a fresh number and retains its predecessor. Void terminates that chain; a different genuine referral may still be issued while first-issue eligibility holds. Replacements start from a blank, reviewed current-source composition, avoiding silent copying of a prior recipient or clinical narrative. No privileged takeover is added.

Every mutation is transactional, with fresh authority, Patient → Visit → Queue → Encounter locks matching existing clinical/Dispensary order, followed by source/chain locks, an independent chain lock_version, source token and UUID idempotency key. Equal retries return the same result; changed payload reuse is refused. Preview content cannot be edited and submitted under an old review token. Void uses a separate retained-document/source token and chain version; it does not require new wording, credentials or a configured live letterhead to invalidate old paper.

## Evidence and numbering

Encrypted immutable PostgreSQL pre-signature snapshots contain exact escaped rendered HTML and page lines, selected content, recipient/consent, template wording/revision, renderer version, identity, credential version, captured letterhead/logo bytes, source token and UTC issue time. No canonical PDF or signed original is archived. Hash verification and authenticated decryption precede copy release.

`REF/KP/{CHR|SGB|PCG}/{branch-local year}/{6-digit}` uses a dedicated locked branch/year counter. A single captured issue instant supplies number year and printed issue date. Counters are separate from MC, refuse exhaustion and never decrement/reuse; void never recycles a number. Action records and snapshots reject UPDATE, DELETE and TRUNCATE. Metadata/counters reject deletion, truncation, ownership changes and non-incrementing transitions. A partial unique index permits only one first action per chain. Composite foreign keys bind document/action ownership; a source trigger checks Encounter/issuer/Visit ownership.

No purge or delete job. Rollback does not drop retained referral evidence. Retention, legal holds and signed-paper duplicate policy remain owner/professional decisions; schema rollback is forward-repair rather than evidence deletion.

## Templates, letterhead and printing

Two fixed EN/BM wording revision-1 records, always `production_approved=false` and visibly DRAFT. No template editor, raw input HTML, arbitrary expressions, uploads or approval UI. MC wording/revision/grants/storage/routes/renderers are untouched.

The document-source boundary calls the DOC-01 assembler after referral authorisation. It explicitly requires this branch's configured clinic name; address line 1, postcode, city, state, country; and at least phone or email. Address line 2 and logo are optional. A configured logo that is missing, retired or corrupt refuses issuance. No other branch supplies missing lines; no private asset path is exposed. Clinic registration/tax fields and the arbitrary billing footer are not captured.

Deterministic server pagination uses 72 display cells and at most 27 body lines per page, preserves every grapheme/space, prefers word boundaries and keeps sections that fit on a page together. Long unbroken values wrap; clinical text is never truncated or shrunk. Conservative pagination can leave whitespace. Each A4 page repeats patient identity, reference, page number and draft warning. The issuer/wet-signature block appears after all content on the last page only. A repeated status strip says COPY and CURRENT, REPLACED or VOID; replaced/void copies also say NOT VALID. Status is current metadata around immutable original content. No claim is made that paper was printed, signed, delivered or recalled.

White paper/dark text is explicit in print CSS regardless of app theme. Browser Print/Save as PDF only; no app PDF storage, email, WhatsApp, portal or electronic signature. The clinic must wet-sign paper and retain its signed duplicate.

## Small shared helpers and source invariance

Only referral-used helpers: DocumentEvidenceCodec, DocumentLetterheadCapture and BranchDocumentNumberAllocator (explicit allowlist: referral/REF). ReferralPagination is referral-specific. No generic document framework or migration of MC data. Existing MC classes/tables/counters/snapshots/routes/grants/revision 1 and invoice/receipt/label code are unchanged.

The only persisted runtime writes are referral chain/document/action/counter records and values-free audit. Reference template rows are migration-created. Audit records event context plus identifiers, versions and controlled field names only. No recipient, clinical text, patient name, selected medicine text, consent narrative or document body. Source reads, previews, history and print-view openings are audited; failed audit refuses release/action and rolls back writes. A print-view event is not proof of printing.

Full ID-ordered before/after comparisons cover every public table except the five declared referral tables (`referral_chains`, `referral_documents`, `referral_actions`, `referral_number_counters`, `referral_template_revisions`) and audit. This includes sessions/cache as well as financial, clinical, dispensary, stock and MC sources. Fixtures populate a finalised invoice, posted receipt/allocation, approved Panel responsibility/prepared claim, stock/dispensing sources and an issued MC. Every document action/read/retry preserves those full rows; prior audit rows also remain identical. No charge, billing/claim/invoice-log/Insights entry, stock movement or source lock_version update.

## Migrations and files

- `2026_10_22_000100_create_referral_evidence.php`: five new tables, fixed draft revisions, scoped constraints/indexes and immutable/source/version protections; no existing source schema changes.
- `2026_10_22_000200_grant_referral_permissions.php`: firstOrCreate, only missing grants, replay-safe; down never revokes. Reference catalogue agrees.
- New ReferralAuthority/Source/Snapshot/Service/Permissions/Wording/Pagination, three small helpers and ReferralController; referral routes, Referrals/Index.vue and fixed print Blade views.
- Existing edits: PermissionCatalogue, routes/web.php, Clinical/Show.vue and this baseline/report only.
- New backend ReferralTest, PostgresReferralNumberTest and worker, unit ReferralPaginationTest; frontend referrals.test.mjs.

## Every changed existing assertion

**None.** All new behaviour is covered by new tests. No existing test file, financial assertion, MC/label/billing-print contract or skip condition is changed. No new hub destination was added, so the strict hub map is unchanged.

During development, new fixture expectations were corrected to the existing strict 404 branch-binding refusal; allergy fixtures now supply required provenance; organisation/branch fixtures use existing model constructors (no factory exists). No existing assertion was altered. An accidental overlap between an early full run and a focused run was stopped; that disposable DB was rebuilt and those runs are not validation evidence. Pre-freeze runs were also stopped when final layout/clock/authority changes were made. One complete run found an existing Treatment Plan revocation assertion failing because the new non-transactional counter fixture seeded global role grants. The fixture now creates only synthetic organisation/branch data, never permission grants, and asserts the grant rows are identical before/after. The new counter test followed by the unchanged Treatment Plan regression suite passed together (13 tests, 87 assertions). A fixture-only guarded organisation-id omission was also corrected. No existing assertion or runtime Treatment Plan logic changed. Only the final frozen-code complete gate below counts.

## New test coverage

- ReferralTest (14 tests): exact catalogue roles/routes and migration replay; fresh permission revocation; other-doctor, branch, organisation and effective-assignment refusal; first-issue Serving/hold/checkout/reopen rules; original-issuer corrections; independent chains; retries/conflicts; required consent/recipient, bounded escaped input and exact preview; stale saved patient/credentials/letterhead/orders/Encounter; absent DOB; missing credentials/address/contact and corrupt/configured-unavailable logo; selected diagnoses/medicines/allergies, unknown versus none; encrypted stable snapshots, EN/BM draft pages; retained COPY/current/replaced/void; PostgreSQL update/delete/truncate refusal; audit-failure rollback and values-free metadata; branch-local New Year; all-table/source/MC invariance.
- PostgresReferralNumberTest (one test): real two-process counter contention, consecutive unique numbers, retained counter and unchanged global grant rows. No reference-permission seeding.
- ReferralPaginationTest (four tests): every grapheme/space retained, fixed cell/line budget, short sections kept whole, ordinary words preserved and long unbroken values wrapped.
- referrals.test.mjs (three tests): actual Vue compilation plus bounded/escaped consent/source structure, permission-filtered actions, branch-change clearing/cancellation and history suppression, responsive layout and fixed A4 white/dark-text repeated identity/reference/page structure.
- Existing MC, drug-label and billing-print suites run without edits in the full suite. Two pre-existing public-registration skips remain unchanged; no PostgreSQL contention test is skipped.

## Initial validation at 3744aad

The original candidate gate passed; the pagination follow-up gate below supersedes it:

| Check | Result / evidence under `/private/tmp/doc04-evidence/` |
| --- | --- |
| `/private/tmp/doc04-run php -d memory_limit=1G artisan test -d memory_limit=1G` | **1,218 total: 1,216 passed, 2 existing public-registration skips; 19,505 assertions**, 499.507 s. `backend-release.log`. No PostgreSQL contention test skipped. |
| Pint `--test` | Passed, `pint-release.log`. |
| PHPStan / Larastan level 7, `--memory-limit=1G --debug` | Passed, zero errors, `phpstan-release.log`. |
| `npm run types:check` | Passed, `types-release.log`. |
| `npm run lint:check` | Passed, `lint-release.log`. |
| `npm run format:check` | Passed, `format-release.log`. |
| `npm run build` | Passed, `build-release.log`. |
| `node --test tests/Frontend/*.mjs` | **391 passed**, zero failures/skips, `frontend-release.log`. |
| `migrate:fresh --seed` on separate `kpone_doc04_seed_testing` | Passed, `seed-release.log`. |
| `git diff --check` and staged diff check | Passed. |

Additional fixture-isolation verification: new PostgreSQL counter test followed by unchanged Treatment Plan regression suite, **13 passed / 87 assertions**, `isolation-regression-final.log`. The earlier full-gate failure and its new-fixture correction are described above; it is not hidden or counted as a pass.

Complete diff: `/private/tmp/doc04-evidence/change.diff`; changed-file manifest: `/private/tmp/doc04-evidence/files-changed.txt`. Both are regenerated from the local candidate commit. The served tree is unchanged. No push, PR or merge.

PG18.6 task-owned cluster `/private/tmp/doc04-pg`, host 127.0.0.1, port 58427, user doc04. Effective and actual database/port/version were checked before requests. Test DB `kpone_doc04_testing`; separate fresh-migrate/seed/browser DB `kpone_doc04_seed_testing`. No .env opened or created. No application dependency added. Synthetic .test identities only.

## Real Chrome evidence

Directory `/private/tmp/doc04-evidence/`. Production assets, synthetic doctor and CA, actual saved document flow. Measured 1440 and 390 CSS px with document scroll width equal to viewport; no page overflow. Existing Chrome zoom was 80%, so viewport override dimensions were calibrated from actual innerWidth, not assumed. Temporary override is reset after verification.

- `issue-{1440,390}-{light,dark}.jpg`: empty composer, no clinical sections selected.
- `preview-{1440,390}-light-bm.jpg` and `preview-{1440,390}-dark.jpg`: long BM/EN previews.
- `history-{1440,390}-{light,dark}.jpg`: own history with current/replaced/void states and labelled local times.
- `copy-bm-*`, `copy-en-*`, `replaced-*`, `void-*`: retained copies and invalidation warnings.
- `native-a4-preview-dark-final.jpg`, `native-a4-preview-en-final.jpg`: native Chrome print preview/Save as PDF.
- `referral-bm-final-dark.pdf`, `referral-en-final-dark.pdf`: actual browser-saved synthetic review artifacts, not application file retention. System PDFKit used to inspect/render, without adding a dependency. A4 dimensions approximately 595 x 842 points; white paper/dark text. Inspection outputs are local review artifacts.
- `denied-ca-history-*`, `denied-ca-copy-*`: real 403 pages with no document content. Automated tests cover every catalogue role and other-doctor/branch/organisation denial.

## Open questions and deferred work

- Doctor approval of both EN/BM wording and clinical section labels; all remain draft. No production approval is implied.
- Clinic written-consent/guardian procedure and signed-paper duplicate/retention/legal-hold policy. A tick is acknowledgement, not legal-process evidence.
- Conservative fixed pagination preserves content but can leave whitespace; no automatic font shrinking is used.
- Only the fixed existing organisation/branch prefixes are supported; an unknown branch/organisation refuses numbering.
- No recipient directory, free-text template editor, uploads/attachments, electronic delivery/signing, PDF/signed-original archive, fee or other document type.
- No MC behaviour repair or approval change; the earlier MC letterhead completeness discrepancy remains separate.


## Pagination follow-up (owner request before PR)

The originally supplied `REF/KP/CHR/2026/000004` PDF really had three non-blank pages:

1. Recipient, urgency and referral reason, followed prematurely by the repeated signature.
2. The long relevant-history section, followed by another signature. Screenshot: `/private/tmp/doc04-evidence/pdf-en-final-2.png`.
3. Selected diagnosis `Synthetic diagnosis alpha`, selected allergy status `Allergy status unknown`, consent acknowledgement/written-consent caveat, then another signature. Screenshot: `/private/tmp/doc04-evidence/pdf-en-final-3.png`.

The defect was not blank pages: the renderer put a signature on every page, and the keep-together rule moved an entire long section to a fresh page when its block fit within 27 lines. The fix keeps together only genuinely short blocks (named 8-line budget); longer sections consume remaining space and continue. The signature renders once, after all body content on the last page. An inserted separator is also omitted when it would otherwise become a blank final page; user-entered body text/blank lines are retained. There is still no truncation or font shrinking.

New snapshots explicitly identify `referral-a4-v2`, separately retaining the immutable wording record's `templateRendererVersion` (`referral-a4-v1`). Wording revision 1 and `production_approved=false` are unchanged. Earlier encrypted snapshots/HTML are never rewritten; a correction requires a replacement as before. No migration, permission, MC, source, financial, dispensary or stock change was made for this follow-up.

Added regression tests: short EN/BM referral has one page and one final signature; long EN/BM referral has substantive content on each page, matching article/page numbers, and exactly one signature on the last page after the final clinical content. Pagination unit tests pin using available introductory-page space, exact text preservation, and an exactly full page not pushing its generated separator onto a blank final page. Three extra frontend assertions pin conditional signature rendering and the inline responsive screen stylesheet. **Changed or removed existing assertions: none.**

Focused tests on final code: **18 passed / 634 assertions**, `pagination-focused-boundary.log`. Standalone comparison using the original algorithm and the identical whitespace fixture: old first page 9 lines, corrected first page 27 lines; `pagination-old-new-check.log`.

Real Chrome, synthetic-only, saved new renderer output (fresh disposable fixture; not a rewrite of the earlier retained 000004 snapshot): long EN referral **2 physical A4 pages**, short EN referral **1 physical A4 page**. Physical page counts match printed 1/2, 2/2 and 1/1. PDFKit rendering/text inspection confirmed every page is populated and the long PDF has the signature on page 2 only. White paper/dark text, no clipping or shrinking.

- Original pages 2/3: `pdf-en-final-2.png`, `pdf-en-final-3.png` (original pre-fix evidence retained).
- Corrected Chrome PDFs: `referral-pagination-long.pdf`, `referral-pagination-short.pdf`.
- Corrected physical pages: `pagination-long-{1,2}.png`, `pagination-short-1.png`.
- Native Chrome previews: `pagination-long-native-preview.jpg`, `pagination-short-native-preview.jpg`.
- Styled screen routes: `pagination-{long,short}-screen-{1440,390}-{light,dark}.jpg`; mobile long-route end views `pagination-long-screen-390-{light,dark}-end.jpg`. The long 390 captures use viewport start/end views to avoid full-page screenshot stitching artifacts.
- Computed screen style: white panel `rgb(255,255,255)`, Arial 14.6667 px / 19.8 px; loaded inline stylesheet. Measured innerWidth/scrollWidth both 1440 or 390, respectively. Main print content is formatted, not raw unstyled text. The browser theme and temporary viewport were restored.

### Final pagination gate

All checks passed on frozen final runtime/test code, PostgreSQL 18.6 at the verified task-owned 127.0.0.1:58427:

| Check | Final result / log under `/private/tmp/doc04-evidence/` |
| --- | --- |
| `php -d memory_limit=1G artisan test -d memory_limit=1G` | **1,222 total: 1,220 passed, 2 unchanged public-registration skips; 19,550 assertions**, 498.158 s. `backend-pagination-final.log`. No PostgreSQL contention test skipped. |
| Pint `--test` | Passed, `pint-pagination-final.log`. |
| PHPStan / Larastan level 7, `--memory-limit=1G --debug` | Passed, zero errors, `phpstan-pagination-final.log`. |
| `npm run types:check` | Passed, `types-pagination-final.log`. |
| `npm run lint:check` | Passed, `lint-pagination-final.log`. |
| `npm run format:check` | Passed, `format-pagination-final.log`. |
| `npm run build` | Passed, `build-pagination-final.log`. |
| `node --test tests/Frontend/*.mjs` | **391 passed**, zero failures/skips, `frontend-pagination-final.log`. |
| `migrate:fresh --seed`, separate `kpone_doc04_seed_testing` | Passed, `seed-pagination-final.log`. |
| Working and staged `git diff --check` | Passed. |

Follow-up diff from `3744aad`: `/private/tmp/doc04-evidence/pagination-follow-up.diff`. No existing assertion was changed, removed or weakened; tests only add coverage. No new migration or permission. No push or PR.

A pre-boundary-fix full run was stopped when the generated-spacer edge was discovered; it is not release evidence. Only the final complete gate above is release evidence for this follow-up. Browser short/long examples do not hit this exact-fill edge; their verified content and page counts are unchanged by it.


## Print acknowledgement and sans-serif follow-up (owner request)

Renderer remains **referral-a4-v2**; no v3. The owner-authorised, unreleased initial EN/BM draft wording definition now prints acknowledgement only:

- EN: “Patient agreement to this disclosure was acknowledged by the referring doctor.”
- BM: “Persetujuan pesakit terhadap pendedahan ini telah diakui oleh doktor yang merujuk.”

The full issue-screen caveat is unchanged, byte-for-byte in `Referrals/Index.vue`: “A tick does not prove the clinic's written-consent or guardian procedure was followed.” The printed document no longer contains that internal caveat. Explicit consent remains required and encrypted with recipient/evidence. Wording remains DRAFT and not production-approved. Fresh task-owned disposable DBs recreate the initial unreleased template definitions; **no retained template/document row is updated**, and no production migration is added. Old immutable snapshots remain exact; new issues/replacements capture the revised initial draft wording. MC revision 1 is untouched.

The printed body now inherits the existing Arial/sans-serif family, at the unchanged 11 pt / 1.35 line height. Server pagination code and constants, wrapping, page margins and last-page signature rules are unchanged. The verified short/long examples retain their one-/two-page counts. The optional screen COPY/status banner uses the same family; print resets its screen padding/border to preserve the existing geometry.

### Every changed assertion and reason

**Existing assertions replaced, changed, removed or weakened: none.** A repository search found no test pinning the literal old printed caveat; the existing issue-screen caveat assertion remains unchanged.

Added assertions:

- New backend test `test_print_acknowledgement_omits_internal_caveat_in_both_draft_languages`: pins exact EN/BM acknowledgement in retained wording and rendered body; refuses reappearance of EN/BM internal-caveat fragments in generated HTML; preserves renderer v2, draft flag and one-page short output. This distinguishes the authorised printed wording from the internal screen warning.
- Existing frontend test gains an exact full issue-screen caveat assertion: prevents accidental shortening/removal of the warning while the print wording changes.
- Existing frontend print test gains three assertions: body inherits the page font, Arial remains 11 pt / 1.35, and no monospace declaration remains. These pin a single sans-serif family without changing pagination or shrinking clinical text.

Focused gate: **19 passed / 652 assertions**, `typeface-focused.log`.

### Fresh Chrome/PDF evidence

All paths under `/private/tmp/doc04-evidence/`; synthetic `.test` data only:

- `referral-typeface-en-short.pdf` and `referral-typeface-bm-short.pdf`: **1 physical A4 page** each, labelled 1/1.
- `referral-typeface-en-long.pdf` and `referral-typeface-bm-long.pdf`: **2 physical A4 pages** each, labelled 1/2 and 2/2; signature only on page 2.
- `typeface-{en,bm}-{short,long}-preview.jpg`: actual native Chrome Save-as-PDF previews.
- `typeface-{en,bm}-short-1.png`, `typeface-{en,bm}-long-{1,2}.png`: every saved PDF page rendered and visually inspected, white paper/dark text, no clipping or font shrinking.
- `typeface-pdf-inspection.log`: PDFKit confirms font-family set **[Arial]** in all four PDFs, absence of internal caveat on all six pages, and signature presence only on the last page.
- `typeface-short-screen-{1440,390}.jpg`: styled screen output; measured 390 CSS px with no horizontal overflow. Computed body font Arial 14.6667 px / 19.8 px.
- `typeface-issue-caveat-{1440,390}.jpg`: unchanged full issue-screen warning visible. Temporary viewport was reset; no patient data beyond synthetic fixtures.

### Full wording/typeface gate

Passed on frozen runtime/test code, task-owned PostgreSQL 18.6 at verified 127.0.0.1:58427:

| Check | Result / log under `/private/tmp/doc04-evidence/` |
| --- | --- |
| `php -d memory_limit=1G artisan test -d memory_limit=1G` | **1,223 total: 1,221 passed, 2 unchanged public-registration skips; 19,568 assertions**, 488.305 s. `backend-typeface.log`. No PostgreSQL contention test skipped. |
| Pint `--test` | Passed, `pint-typeface.log`. |
| PHPStan / Larastan level 7 `--memory-limit=1G --debug` | Passed, zero errors, `phpstan-typeface.log`. |
| `npm run types:check` | Passed, `types-typeface.log`. |
| `npm run lint:check` | Passed, `lint-typeface.log`. |
| `npm run format:check` | Passed, `format-typeface.log`. |
| `npm run build` | Passed, `build-typeface.log`. |
| `node --test tests/Frontend/*.mjs` | **391 passed**, zero failures/skips, `frontend-typeface.log`. |
| `migrate:fresh --seed`, separate `kpone_doc04_seed_testing` | Passed, `seed-typeface.log`. |
| Working and staged `git diff --check` | Passed. |

Follow-up diff from `529d1a2`: `/private/tmp/doc04-evidence/typeface-follow-up.diff`. Initial draft-definition change only: no new migration, grant, renderer version or source/financial change. Existing snapshots remain immutable; all DRAFT marks remain. Served tree unchanged. Local commit only; not pushed and no PR opened.
