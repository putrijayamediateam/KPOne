# REG-WORKSPACE-1 — review candidate

The Registration board, Patient directory and QR intake now share permission-filtered workspace navigation in demo order: Today, QR check-ins, Patients. They remain explicit existing routes, with aria-current on the active view. Registration status controls stay under Today; QR retains its existing table/review actions and actual pending count. Patient directory does not invent a QR count. No Follow-up/all-branch placeholder is added.

Six-file overlay `workspace-overlay.patch`, hashes in `source-manifest.json`; tree `/workspace/KPOne-registration-workspace`, branch `feature/registration-workspace`. Base is origin/main c1e5fc05c plus CA source snapshot recorded in baseline.json. Only the overlay belongs to this slice: do not publish the accumulated CA working diff. No commit/push/PR performed.

Files: new RegistrationWorkspaceNav.vue; Registration/Index.vue and Patient/Index.vue mounts; new registration-workspace-navigation.test.mjs; import-only NullStub mappings in rb-01/rb-02 component harnesses. Existing assertions unchanged. No PHP/controller/service/routes/schema/permissions/contact/family changes.

Validation:
- 84 relevant Patient/Registration frontend tests pass (5 new component SSR cases; existing behavior assertions preserved). Final omitted-prop regression was additionally rerun in five focused tests.
- Full vue-tsc passed after copying missing local generated wayfinder baseline; first attempt failed due missing generated helper, not application source. Final production build passed after explicit optional-Boolean default fix; scoped ESLint/Prettier and git diff --check pass.
- Actual browser navigation on own3050 preview/own synthetic kpone_registration_workspace_test: Today populated, Patients real masked search populated, QR empty;12captures390/1440light/dark, zeroJSerrors/overflow, all nav links44mobile/36desktop, Patients browser-back and Today navigation pass. Evidence browser-results.json. Supplementary browser-extra-results.json confirms4 populated QR captures, actual Verify navigation, correct1pending badge and Doctor QR omission. keyboard-results.json confirms Tab focus and Enter navigation;18 application screenshots total.
- Canonical wrapper6captures at390/1440, CA Supervisor, Today/Patients/QR, demo-runtime.json. No static-only reference substitution.
- Staff independently reviewed source, five new tests and selected final screenshots against canonical references: INDEPENDENT-REVIEW.md, bounded clear.

Browser caught a real first-candidate bug: optional Boolean availability defaulted false when omitted by Patient page, hiding QR. Explicit undefined default fixes it; test renderer now actually omits optional props, matching production. Review also preserved original real pending count after moving QR out of the status strip. Early failed browser captures/logs are superseded by final logs; no failure is relabelled as a pass.

Remaining scope: navigation-only candidate, not full demo parity. Header/shared Patient identity, inline workspace composition, doctor pills/filter density, helpful-information sidebar, modal four-stage register and follow-up/allbranch workflows remain open in domain gap matrix. Existing pink Patient links and other inherited page styling remain outside this overlay. NEXT-COMPOSITION-PLAN.md records independent next preparation and permission/data boundaries. Backend full baseline gate belongs to the frozen CA integration; this presentation slice did not rerun full backend/migrations, and makes no new backend runtime claim.
