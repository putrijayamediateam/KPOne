# Registration: check current choices before registering

Bounded demo continuation, 26 November 2026. Exact base `ecadc1125c6b6603828cf6951a06e6d4e547e3b6`, stacked on Registration foundation PR #1132 (`feature/clean-look-registration`). Three changed files: Create page wiring, new read-only summary component and four new actual-component tests. Frozen patch SHA-256 `a97fd5ed281175da3bbf7bc0fef826ce13a012409fccd4b5e193c0931c3c0154`.

The authoritative RegisterVisit demo has a Check and register section. The existing page showed only patient name, branch and visit type beside its submit action. The new section shows current selected/saved or explicitly new patient identity, command branch, visit type, doctor option, urgency, ordered reasons with one Primary marker, and self-pay or selected panel/member reference. Labels are resolved from selected IDs; unavailable choices are not replaced with invented identities. OTC doctor/reasons remain optional and visible because they are still submitted by the existing form. Text is escaped; no prices, allergy projection, queue numbers, readiness or assignment predictions are added.

The original submit function is byte-identical to the base, recorded in `submit-parity.json`. Existing errors, repeat-visit confirmation, idempotency, branch guards and sticky primary action remain unchanged. No backend, permission, migration, Emergency/Family changes or existing assertion edits. This slice does not provide the demo modal/four-stage workflow or claim full Registration parity.

## Validation

- Full frontend: 2209/2209 pass, including four new compiled actual Vue component SSR cases.
- Full TypeScript check and production build pass.
- Full ESLint, resources Prettier check and diff check pass.
- Browser: consultation, OTC panel and refused local validation at 390/1440 in light/dark; current name, new-patient marker, optional OTC values, self-pay hiding stale reference and original invalid-name focus verified. No document overflow, page errors or patient/visit mutation requests. Actual saved-patient selection adds four further captures with the exact saved KP number and no new-patient marker: 16 captures total.
- Synthetic-only preview3102 and existing owned synthetic database; no successful registration performed.

Evidence: source-manifest.json, REGISTRATION-REVIEW-SUMMARY.patch, browser.mjs/browser-results.json/screenshots, full-frontend.log, types.log, build.log, lint.log, full-lint.log and format.log. Independent reviewer closure and final publication information follow after gates.


## Publication

Draft PR #1186: https://github.com/putrijayamediateam/KPOne/pull/1186. Head `46ca8f1977259c10e6c32440fd86b4effa39a9c6`, branch `feature/registration-review-summary`, base `feature/clean-look-registration`. Normal push, clean tree; no merge. Root independent final review verified exact hashes, original script except import, four actual component tests and representative images. All gates green; full resources formatting also passed.
