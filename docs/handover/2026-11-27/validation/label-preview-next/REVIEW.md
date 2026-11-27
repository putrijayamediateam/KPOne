# Label preview screen composition

The preview now exposes existing patient/branch context and separate prepared, blocked, excluded and previously printed label counts in its screen toolbar. Existing blocked-selection refusal and individual eligible-label navigation remain intact. The print history counter retains canPrint visibility. Stale/retired previews hide the added identity and counts.

Scope: Labels.vue, one new actual-toolbar SSR runtime test, and TEAM.md. No existing assertions changed. No backend, permission, migration or physical-label changes. Script, @page generation, printed medicine section and original CSS are byte-identical to main eec5d5444; all appended styles are screen-only. This is a bounded adaptation of the locked DrugLabel demo, not full modal/selection workflow parity.

## Verification

- 53 focused frontend checks passed, including 50 unchanged checks and three new actual-toolbar runtime cases.
- Scoped unchanged DrugLabelService PHPStan stopgap passed with zero errors. The first invocation used an incorrect path and was corrected; both logs are retained.
- Full frontend types and production build passed; scoped ESLint, format and diff checks passed.
- 16 actual Laravel screenshots: ready, mixed blocked, reprint and authorized doctor readonly at 390/1440 in light/dark; no page overflow or browser errors.
- Print and individual eligible links measured 44px at 390 and 36px at 1440. Existing individual eligible navigation was exercised.
- Two print-media checks hide the toolbar and retain 100x62mm physical label dimensions (377.9375x234.328125 CSS pixels); paper remains white in dark mode. No browser/native print or physical printer verification was performed.
- Review source-manifest.json, print-parity.json, browser-results.json and the individual logs for exact evidence. Independent Design closure is recorded separately.

## Synthetic fixture provenance and limits

Only task-owned label preview database (actual name kpone_label_screen_preview_test) on 127.0.0.1:55439 was used. The inherited synthetic branch address initially exceeded the existing 96-character label budget and correctly blocked labels. The normal BusinessDetailsService updated only this synthetic branch to a short fictional address; no validation limit was relaxed. A normal DrugLabelService recordPrint call provisioned synthetic reprint history; browser verification did not invoke Print. Fixtures preserve mixed-selection refusal and readonly authority. Full backend/contention/migration suites were not rerun for this presentation-only change; remote CI remains required before merge.
