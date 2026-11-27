# PRINT-1 — white paper in every theme

Date: 2026-11-03. Branch: `fix/print-1-dark-mode`. Isolated worktree: `/Users/klinikputrijaya/Herd/KPOne-print-1`.

Fetched base and full-gate validation HEAD: `019139c7b2fe8d8c9e0ba01ab9429706ae0d425b`. The full gate ran against the application/test changes while uncommitted on that HEAD. The owner subsequently authorised committing PRINT-1, pushing only this branch and opening one PR to main. This is synthetic local validation, not production approval.

## Change and cause

The existing invoice/receipt rule resets `.document-print` only (`resources/js/pages/Billing/Print.vue:192`). The label already resets `.label-preview` (`resources/js/pages/Dispensary/Labels.vue:247`). Neither rule resets the containing page. `resources/views/app.blade.php:59` gives `html.dark` a dark background, and `resources/css/app.css:196` applies the themed body background. Chromium prints those remaining canvas areas when background printing is enabled.

A shared, print-only rule at `resources/css/app.css:246` sets white background and black text on html/body containing `.document-print` or `.label-preview`, and on those document surfaces. `!important` overrides the inline theme background. These markers belong to Billing Print and Dispensary Labels only. The rule changes no dimensions or page-break properties. Existing A4 and label CSS, D02 footer behaviour, D03 content and local white-print safeguards remain intact. Screen media is unaffected.

| Changed file | Change |
| --- | --- |
| `resources/css/app.css` | One print-only rule for the document and containing page backgrounds and text colour. |
| `tests/Frontend/print-1-dark-mode.test.mjs` | Two new tests pin the exact surfaces, white/black declarations and print-media containment, with no layout declarations. |
| `docs/reports/PRINT-1.md` | This report. |

No PHP, permission, grant, migration, dependency, route, payload, calculation or stored-data change.

## Every changed existing assertion

**None.** No existing test file, expected value, name or skip changed. In particular, `tests/Frontend/d02-print-footer.test.mjs` and `tests/Frontend/d03-print-layout.test.mjs` were run unchanged. The two new tests failed before the CSS change (0 passed, 2 failed), then passed after it. The combined new/D02/D03 run passed 19 tests, with zero failures or skips.

## Owned environment and isolation

PostgreSQL **18.6**, host **127.0.0.1**, port **58784**, role `kpone_print_1`; cluster `/private/tmp/kpone-print-1-pg18`. Databases created for this task with UTF8/template0:

- `kpone_print_1_preview`: synthetic fixtures and authenticated Chromium capture.
- `kpone_print_1_testing`: full suite and explicit PostgreSQL replay.
- `kpone_print_1_seed`: separate fresh migration/seed verification.

The explicit process environment and effective connection host, port, database, encoding and cluster directory were checked before application use and before the first preview request. Logs: `effective-db.log`, `server-db.log`, `gate-db.log`, `seed-db.log` under the private evidence root below. No server-address equality assumption is added to any test.

PHP 8.4 with a task-only 1 GiB CLI configuration; placeholder application key; dummy empty database password; array mail; synchronous queue. No real `.env` or `.env.preview` was opened, edited or created. Preview at task-owned `127.0.0.1:8908` used an explicit router and empty environment directory. Tests, preview and seed use separate storage roots; the test suite never touches preview storage. Browser requests outside the owned preview origin were blocked. Synthetic `.test` identities only.

The served tree was left clean on `main`; no other worktree or non-task database was changed.

## Chromium before/after proof

Evidence root: `/private/tmp/kpone-print-1-evidence` (private, outside Git). Captures use headless Chromium through Playwright, with background printing enabled, from real authenticated pages. No real patient document was used.

Four document cases: ordinary invoice, receipt, long-footer invoice and drug label. Four theme outcomes per case: explicit light with OS dark, explicit dark with OS light, system with OS light, system with OS dark. This covers **16 states per phase**. Each state has a background-enabled PDF and a matching background-disabled content-reference PDF: **64 PDFs total** across before/after. Each phase also has screenshots at 390 and 1440 px: **64 screen screenshots total**. The long footer is 987 characters over 38 lines and occupies page two. Billing PDFs retain A4; labels retain 100 × 150 mm.

PDFKit rasterises each PDF page at 2× scale. The matching background-disabled reference identifies printed text and rules; its non-white mask is expanded by two pixels for antialiasing. Every pixel outside that mask in the background-enabled output must be exactly RGB 255/255/255. This tests unused paper throughout each page, including page two, rather than just corners. Twenty background-enabled pages are checked per phase.

| Document / theme outcome | Before: non-white pixels outside content | After |
| --- | ---: | ---: |
| Invoice, light / system-light | 0 each | 0 each |
| Invoice, dark / system-dark | 564,049 each | 0 each |
| Receipt, light / system-light | 0 each | 0 each |
| Receipt, dark / system-dark | 823,033 each | 0 each |
| Long invoice, light / system-light (two pages) | 7,500 each | 0 each |
| Long invoice, dark / system-dark (two pages) | 601,722 each | 0 each |
| Label, light / system-light | 0 each | 0 each |
| Label, dark / system-dark | 1,415 each | 0 each |
| **Total** | **3,995,438** | **0** |

The before receipt shows the reported black rectangle below its footer; the after receipt is white. The after long-footer second page and drug label were also visually inspected. Final `final-after-pixel.log` confirms the rerun against the final captured PDFs.

Content and geometry controls:

- All 16 business-payload hashes and printed texts match before/after.
- All 32 corresponding screen PNGs are byte-identical, and recorded computed styles and element bounds match.
- All 16 corresponding background-enabled PDFs have identical page counts, media boxes, extracted text and every character's bounds. Ordinary invoice, receipt and label have one page; long invoice has two.
- Complete ID-ordered row hashes (sorted serialised rows for keyless tables) match across **83** financial, inventory, dispensary, clinical, patient, visit and business-detail tables. Auth/session/audit activity from logging in is outside this business-table comparison.

Private harness correction: the first row-snapshot helper assumed every selected table had an `id`; `patient_number_counters` does not. The helper was corrected to sort keyless rows, then both snapshots and the final authenticated capture were repeated. No application or existing test was changed for that harness error. The final comparisons passed.

The content reference is theme-specific: existing theme-dependent rule/border shades are retained. No claim is made that document ink is byte-identical between different themes; the before/after comparisons are within each theme.

Primary evidence paths relative to the root:

- `before/` and `after/`: PDFs, screen screenshots, rendered page PNGs, `matrix.json` and `pixel-results.json`.
- `print-evidence-manifest.json`: 174 PDF, screenshot, raster and matrix files with sizes and SHA-256 hashes.
- `comparisons.json`, `layout-comparison.json`, `before-rows.json`, `after-rows.json`.
- `before-pixel.log`, `final-after-pixel.log`, `red-test.log`, `targeted-frontend.log`.
- `ci.log`, `frontend.log`, `pg-replay.log`, `pg-replay.xml`, `pg-replay-summary.json`, `seed.log`, `types.log`, `lint.log`, `format.log`, `final-build.log`.
- `review.diff`: the complete three-file review diff, including new files. `PRINT-1-evidence.zip`: curated private evidence bundle; no environment, login script, cookie or session files are included.
- Example bug: `before/receipt-dark-os-light-backgrounds-on.pdf`; fix: `after/receipt-dark-os-light-backgrounds-on.pdf`.
- Page two: `after/long-invoice-system-os-dark-page-2.png`; label: `after/label-dark-os-light-page-1.png`.

## Final validation

The final application/test tree passed `composer ci:check` with exit 0: **1,571 passed, 33,872 assertions, 2 existing skips, 0 failures** (1,573 tests including skips; 806.582 seconds). Frontend validation passed separately. The explicit PostgreSQL replay also passed, as verified from its JUnit XML.

| Check | Result |
| --- | --- |
| Changed PHP syntax | No new/changed repository PHP files. Private PHP harnesses linted before execution. |
| Pint / PHPStan level 7 | Passed, zero PHPStan errors, before backend suite. |
| `composer ci:check` | **1,571 passed, 33,872 assertions, 2 existing skips, 0 failures**; exit 0. |
| Frontend types, lint, format | Passed. |
| `npm run build` | Passed. |
| `node --test tests/Frontend/*.mjs` | **576 passed, 0 failed, 0 skipped**. |
| Explicit PostgreSQL replay | **120 passed in 18 classes, 1,202 assertions, 0 skips, 0 failures, 0 errors**, exit 0; 97.674 seconds. |
| Separate `migrate:fresh --seed --force` | Passed on `kpone_print_1_seed`, dummy data only. |
| Tracked and new-file whitespace | Passed, including both new files. |

## PR preparation

The owner requested a fresh fetch, merge of current main and quick-check replay before committing. Fetched `origin/main` remains `019139c7b2fe8d8c9e0ba01ab9429706ae0d425b`; `git merge origin/main` returned **Already up to date**, with no conflict. No repository PR template was found. Only the three PRINT-1 files listed above are included; no environment file, private evidence, screenshot or unrelated change is included.

The requested quick checks passed on the application/test tree being committed:

- `git diff --check origin/main`, plus separate new-file whitespace checks: passed.
- `composer run lint:check` (Pint): passed.
- `node --test tests/Frontend/*.mjs`: **576 passed, 0 failed, 0 skipped**.
- `node --test tests/Frontend/print-1-dark-mode.test.mjs`: **2 passed, 0 failed, 0 skipped**.

These checks supplement the full gate above. No application or test code changed after the full gate. Private replay logs: `pr-pint.log`, `pr-frontend.log`, `pr-print-rule.log` under the evidence root.

- [x] Owner authorised the commit, push and this one PR.
- [ ] Owner authorises merge. Do not merge or enable auto-merge.

## Limits and deferred work

**A physical printer test is still outstanding.** Headless Chromium PDF output cannot prove a printer driver's margins, colour management, background-print setting or physical sticker alignment. Other browser engines and printer hardware are not claimed as tested. Existing print layout/content limits remain outside PRINT-1. Tax and e-Invoice suitability remains to confirm with the clinic's accountant or tax adviser.

No real data. The task-owned preview server and PostgreSQL cluster are stopped; private evidence and synthetic databases remain local. The served tree remains clean on `main`, untouched. Merge is not authorised.
