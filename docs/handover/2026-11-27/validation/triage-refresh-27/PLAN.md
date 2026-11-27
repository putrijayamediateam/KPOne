# Triage foundation refresh

Scope: `resources/js/pages/Triage/Show.vue` only. Existing PR1134 supplies PageFrame, card/field/status tokens and responsive spacing. Follow CLEAN-LOOK-SPEC.md section4: minimum44px below1024 and36px desktop, with semantic focus ring. This is a foundation presentation slice, not full demo content parity.

The normal current-main merge preserves the original page blob. One local class correction changes the existing reset-zone control from desktop `min-h-0` to `min-h-9` and supplies a rounded semantic focus ring. The script equals current main after excluding the existing PageFrame import. Existing request payload, blank-reading behavior, guideline restrictions, lower-zone refusal, retry key, version refresh and submission handler remain byte-identical.

No test assertion, permission, migration, backend query, audit or state transition changes. Transaction/row-lock/version enforcement remains server-owned and unchanged; this slice does not claim to rerun backend concurrency tests. English UI and semantic tokens are retained; no raw palette or new accent is introduced. Actual-component synthetic browser proof must cover390/768/1440 and both themes, keyboard focus, reset behavior and no overflow. The public component harness is explicitly not authenticated end-to-end clinical proof.

Validation commands from the isolated candidate:

```sh
export PATH="/workspace/kpone-tools/bin:$PATH"
php artisan wayfinder:generate --with-form
npm run types:check
node --test tests/Frontend/*.test.mjs
npm run build
./node_modules/.bin/eslint resources/js/pages/Triage/Show.vue
./node_modules/.bin/prettier --check resources/js/pages/Triage/Show.vue
git diff --check
```

Passing means exit0 for every command, no failing frontend cases and final bounded browser measurements meeting the target minima. Remote exact-head CI and then-current-main ancestry remain required before merge. Proposed commit: `fix(triage): preserve desktop reset target and visible focus`.
