# Triage pure Stage1 publication candidate

Base: current main67fcc9105ad6da7adec8383265129fd0ba2a2aba. Tree/workspace/KPOne-triage-publication; branch feature/clean-look-triage-publication. No commit/push. Exact patch /workspace/kpone-review-bundles/TRIAGE-CL1-publication-current-main.patch. manifest.json records source hashes.

Plan/spec: Existing triage form placed in shared PageFrame, card/field/status tokens and responsive spacing. Reuse already merged Clean Look shell/tokens/spec/Dialog in place; no new design switch.

Files: resources/js/pages/Triage/Show.vue.

Frozen input: TRI-CL-1.patch, listed in root CA source-inputs. Review-only docs from recovered patch excluded from code candidate; original evidence remains at /workspace/KPOne-tri-cl1/docs/reviews/TRI-CL-1-2026-11-26.md. No functional Emergency Contact, Family, Treatment or TRI2 additions. No backend/routes/permissions/migrations/dependency changes.

Parity/self-review: Script differs only by PageFrame component import; all calculations, validation, fields and submission unchanged. No test assertions changed. Pure patch applied cleanly to currentmain. For future Q16b/Clinical integration preserve its functional banner via normal conflict reconciliation rather than importing it into this slice. Existing review source is not claimed as a new historical designer signoff.

Gates: focused9/9PASS; Prettier and diffcheckPASS. Isolated fulltypes/build are running serially; final outcomes appended when done. Prior scoped synthetic light/dark visual evidence covers exact reviewed appearance; no new currentmain browser run claimed. No full backend rerun for template/style-only work.

Risk: shared styles in Homes have broader visual consumers; preserve role projections/masking and test their existing assertions. Consultation prior browser fixture did not contain populated prescriptions; integrated medication UAT remains broader evidence. Triage wrapper indentation makes diff large but does not change submission logic. Future main changes require final ancestry/parity check before publishing.

Proposed commit: style(triage): apply reviewed Clean Look restyle
Proposed PR title: Restyle triage with shared Clean Look components
Proposed PR body: Existing triage form placed in shared PageFrame, card/field/status tokens and responsive spacing. Script differs only by PageFrame component import; all calculations, validation, fields and submission unchanged. No test assertions changed. Validation: focused9 tests, formatting/whitespace and prior reviewed synthetic visuals; append isolated types/build result before publication. This is a pure presentation slice, separate from domain feature work.

Final isolated gates: full vue-tsc PASS, production Vite build PASS, changed-page ESLint PASS. Safe vendor/generated copies with explicit task testing environment; no .env. No full backend suite claimed. Pure source patch unchanged.
