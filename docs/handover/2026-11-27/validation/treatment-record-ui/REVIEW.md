# Treatment-room UI/controller delivery — 26 November 2026

The authorized treatment-room workflow now lets Medical Assistants and CA Supervisors start treatment, record Done or Not successful with a fixed reason, correct a procedure/outcome, and void a wrong-patient record while preserving history. CA and authorized doctors receive read-only evidence. Treatment does not complete the consultation or release its hold.

## Exact scope and dependencies

Standalone overlay: `/workspace/kpone-review-bundles/TREATMENT-RECORD-UI.patch`, SHA256 `8d0096924aad1e09ac1f580faade4c4506e93ff87efe8d5ceb00cf53d8e1104c`. 22 files: 12 existing UI/controller/route/type files and 10 new controller/components/pages/tests/design files. File hashes are in `source-manifest.json`; original integrated CA inputs and hashes are under `baseline/` and `baseline-manifest.json`. The patch passes `git apply --check` against that exact saved baseline. It is an overlay on the CA integration snapshot, not a standalone main branch patch. Root may need context resolution where later integration changes touched these pages.

Requires Queue owner's frozen TreatmentRoom domain/policy/directory/projection/Q2 payload and approved migration. Those dependency copies are deliberately excluded. No permission grant, migration, old assertion change, public TV/QR outcome, result field, patient transfer link, or clinical-state change is included. No commit, push or PR was created.

## Important implementation decisions

- Endpoint authority is server-side domain authority, repeated for writes and exact replay. Historical reads use the historical clinical-care relationship, not permission alone. Authorized URLs are added to existing approved clinical/visit history/bill surfaces; record details are fetched explicitly and audited, not placed in generic polling payloads.
- The linked step fixes the initial procedure. Corrections expose only the field group allowed by their correction type. Void requires a separate confirmation and cannot choose a replacement patient.
- Unknown network/server response outcomes freeze the exact draft, operation, payload and UUID key. Retry resends that same operation; reload reconciles authoritative state before a different action. Deterministic validation refusals can be corrected. The HTTP adapter preserves unknown fields for service rejection, removing only CSRF transport metadata.
- Optional Q2 outcome summaries show the full unsuccessful reason to authorized readers. Existing no-record DTO/message behavior is preserved.

## Validation and evidence

- New HTTP tests: 4 passed, 79 assertions (`http-tests.log`), including role boundaries, unknown fields, replay, corrections/void and post-completion read/write differences.
- New SSR/doctor-alert tests plus unchanged Q2 suite: 15 passed (`frontend-final.log`). Command: `node --test tests/Frontend/treatment-room-record-ui.test.mjs tests/Frontend/q-2-treatment-progress.test.mjs`.
- Final `npm run types:check` passed (`types-final.log`). Scoped ESLint/Prettier, PHPStan on changed controllers, Pint and diff whitespace checks passed. Production Vite build passed (`build.log`). Backend domain contention/integrity checks are owned and separately reported by Queue.
- Real Laravel synthetic preview on isolated port3037/database `kpone_treatment_ui_preview_test`: 50 UI checks, 36 screenshots at390/1440 in light/dark, no horizontal overflow or JavaScript errors. `browser-report.json` contains all paths and the identical original/retry payloads. The harness deliberately committed the first outcome then dropped its response; retry produced only one initial record.
- UI flow: eligible held patient → start urine sample → readonly initial procedure → Not successful/Patient declined → uncertain-response recovery → CA read-only → attending-doctor read → correct outcome to Done → correct procedure to Swab sample → wrong-patient void → retained history, no revived predecessor.
- `structural-proof.json`: one original plus two correction records, one terminal void, recorded audit1/superseded2/voided1, visit still registered/version1, queue still Serving/version3, consultation hold unresumed. No direct ledger/clinical-state mutation used.
- `control-measurements.json`: measured current selected-page controls36px/14px desktop,44px/14px mobile. Dialog/history screenshots independently reviewed by Staff; this measurement is not a claim about every shell control.
- Independent source/visual review: `/workspace/kpone-review-bundles/TREATMENT-RECORD-UI-INDEPENDENT-REVIEW.md`, no blocking finding. Independent reviewer did not rerun all browser checks.

## Review limits and integration

The first browser run stopped at a harness locator for portalled select options after its earlier checks passed; the correction continuation fixed the locator and completed the remaining flow on the same fixture. This was not an application failure or reseeded success. Browser evidence is synthetic and does not replace integrated full-suite checks. Historical policy and broad concurrency are covered by backend/HTTP tests, not every browser role combination. The preview contains synthetic credentials only and is not a deployment.

Suggested commit: `feat(treatment-room): add authorized treatment record workflow`

Suggested PR body:

> Add the MA/CA-supervisor treatment-room record workflow and authorized read-only clinical/history panels. Initial outcome follows its linked procedure; correction and wrong-patient void retain append-only history. Unknown response outcomes keep the exact request key and payload locked for safe retry. Existing consultation state, role grants and public queue payloads remain unchanged.
>
> Validation: HTTP4/79; frontend15; types, scoped static checks and build; synthetic browser50 checks/36 captures, including a committed request with a lost response and exact retry. Depends on the separately reviewed TreatmentRoom backend and approved migration. No legacy assertion changes.
