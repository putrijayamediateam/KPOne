# Family UI slice — implementation and validation

Staff/design ownership: resources/js/components/patient/FamilyLinksCard.vue; resources/js/components/clinical/FamilyWaitingCard.vue; minimal integration imports/templates in pages/Patient/Show.vue and pages/Clinical/Show.vue; optional FamilyWaiting type in types/clinical.ts; new tests/Frontend/family-links-ui.test.mjs. Patients team owns all backend/migration/route changes. No dependencies, existing assertions, permission grants, emergency/guardian functionality or family billing changes.

## Plan and behavior

Manual existing-patient search reuses Registration request/classification and masked identifiers without recent patients, prefilled phone or automatic lookup. Explicit current-patient relationship sentence and both patient numbers precede Save. Server capabilities control add/edit/remove/relink; inactive pair uses latest returned version, merged originals expose only generic removal. Commands carry expected branch, version, current-perspective relationship and stable UUID; changed draft after a lost response cannot reuse that key silently. Denied access clears identity; patient/branch changes abort requests and discard late results; changing query aborts old matches. No browser storage.

Consultation uses only the server's bounded authorized waiting projection, labels relative-to-current direction, and links to Patient details. It shows no clinical/payment/phone data or call/start actions. Family links remain separate from guardian/emergency and billing arrangements. Touch controls use 44px minimum below desktop and 36px minimum desktop; long content wraps.

## Peer review and fixes

Patients independently reviewed the contract and found unlink must carry the retained relationship code (fixed) and an old directory denial must not clear a new-context editor (fixed with new regression). Backend Family record anchor changed to #family-links to match UI. Both-direction wording checked separately: directory current-to-relative; Consultation relative-to-current.

## Validation

New actual component-script/SSR tests: six pass, covering manual lookup, branch/query stale response, key stability and altered-draft refusal, suppressed unlink identity/version/code, denial clearing, allowlisted Consultation display and delayed old-directory denial. Existing Patient/Clinical focused tests: 45 pass, existing assertions unchanged. Focused ESLint/Prettier and diff check performed; final gates and browser results to be appended when complete.

## Scope/risk

Standalone feature tree is main-based; frozen Stage0/Registration/Emergency overlay integration remains coordinator-owned. Backend authorization, atomicity and projection filtering are independently owned/tested by Patients. UI does not replace backend authority. Browser checks must use only synthetic task-owned preview DB. No migration execution by this reviewer, no commit/push/PR.

## Proposed commit / PR description

Add manual patient family links and authorized waiting-family context

Staff can explicitly link existing patient records with directional relationship labels, correct/remove/relink using versioned commands, and review relatives waiting today within their existing Consultation queue scope. Family membership grants no additional clinical, guardian, contact or billing access. The UI preserves failed drafts and unchanged retry keys while clearing stale branch/patient results.

Validation: focused existing and new frontend tests, type/build and synthetic desktop/mobile light/dark interaction evidence as recorded below. Backend/migration evidence belongs to the combined feature review.

## Final results and freeze

- Full vue-tsc PASS; production build PASS, then final wording-only production rebuild PASS (build-final.log). Focused ESLint/Prettier PASS and git diff --check PASS.
- 45 existing focused tests plus 6 new interaction/SSR tests PASS. Final six rerun after uncertainty-copy change PASS; no historical expectations changed.
- Actual synthetic preview3043: manual create, inverse parent/child rendering, relationship correction, removal/relink, and doctor-owned waiting child all PASS. Deliberately committed a create while dropping the response: draft stayed visible and unchanged retry used the same key successfully. 28 screenshots at390/1440 light/dark; no overflow or JS errors (browser-results.json).
- Additional actual keyboard test PASS: Enter opens editor, relationship/search focus, Cancel restores trigger focus, empty manual-search error receives focus; all visible Family card input/buttons at least44px on390 (keyboard-results.json).
- Peer visual review found raw network failure copy unhelpful. Replaced with explicit uncertain-save wording, preserving server validation messages and draft/key. Final error-only recapture PASS4 views, no JS errors/overflow; request aborted before mutation in this supplementary check. Other28-state behavior evidence is unchanged. Self-reviewed final mobile error and Consultation cards; Patients independently reviewed editor/error/Consultation layouts.
- Initial browser start failed before login due preview server cwd, fixed by preview owner; not an application defect. No production or shared database was accessed.

Source frozen after final wording fix. Exact six-file UI patch family-ui.patch and hashes ui-manifest.json. Main-based standalone screenshots do not claim final combined Stage0+Emergency visual approval; coordinator merges minimal imports/templates into current integration. Shared default Dialog close improvements are separately owned/applied in Stage0; no duplicate primitive patch here.
