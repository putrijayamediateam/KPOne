# Registration settings composition — bounded publication packet

Base: main d0f37b5c1864b796fc7b4224996bd917551af3e7. Independent branch `feature/registration-settings-composition`; no downstream Registration feature ancestry.

The existing settings page now separates fixed system rules, four configurable requirements, where those requirements apply, and the separately authorized branch doctor-assignment setting. Native checkbox switches retain existing v-models and show the current draft's Required/Optional state; no toggle writes. The two existing save commands, versions, permissions, errors, explicit stale reload and automatic-assignment dirty guard remain unchanged. The complete page script is byte-identical to main.

Scope: one page, one new actual-component test file, one whitespace-only existing fixed-label assertion adaptation reviewed by Staff Design under standing delegated technical authority. No backend, migration, grant, route, shared component, new settings field or patient-data change. Demo fake branch totals and unsupported legal approval prose are excluded. The known-minor phone exception and in-progress QR captured policy are described from existing services.

Staff's preliminary review caught nonstandard switch dimensions/active color/reduced-motion support. Those four owned tracks were aligned to 44×26, 22px knobs, success active color and reduced-motion suppression; whole-label hit areas retain 44px mobile/36px desktop minimum. Actual browser measurement is a final gate.

Validation already complete: 10 focused frontend tests, including 3 new compiled actual-page native-event/real-useForm tests; backend RegistrationSettingsTest 8 tests/173 assertions on isolated synthetic DB; full ESLint and resources Prettier pass. New tests prove no toggle request, exact separate command payloads, permission-based auto form omission, stale error/reload behavior and listener cleanup. Full types/frontend/build and actual browser evidence are pending at this checkpoint; do not read this as final clearance.

Browser scope: synthetic Director on own cloned DB, four responsive/theme states per section, native Space toggle/status/no request, exact switch geometry/focus/reduced motion, controlled outgoing stale version reaching the real server and preserving draft/error, explicit reload, separate auto draft/no write. No successful settings mutation. A controlled version override is not a naturally occurring stale-edit claim. Permission omission is component/backend evidence, not a browser role proof.

## Final builder evidence

Types, full 2,212 frontend tests and production build PASS on final source. Browser PASS:20 captures at390/1440 light/dark;12 label/track/knob measurements at390/768/1440, native Space/status/no-request, reduced-motion transition suppression, real server stale refusal with draft preserved, explicit reload restoring saved values, separate auto-assignment draft toggled and reverted without request. No JS errors or document overflow. Exactly one controlled stale-version requirements POST; before/after registration_settings JSON identical. No successful settings save or live public-flow claim.

Harness correction: initial reduced-motion assertion checked transition duration; Tailwind transition-none intentionally leaves the default duration while setting transition-property:none. Final assertion checks the actual disabled transition property. Application source did not change for this harness correction.

Frozen exact patch SHA256:34bf7b8272c8459d770904b756f47606acfac35157f4faa9a6f141f972a410e8 (three files). Final independent review/publication pending.

Final Staff independent review CLEAR exact34bf7b82. Local commit3bf91447aa8dbb27fd8dae1bf37ea6f4765157a2 clean. Publication blocked by automatic approval review: verified public origin and push permission, but reviewer requires explicit trusted user approval for these three source files to public KPOne. No remote tree/branch/PR created; no alternate transport attempted. See publication.json.

Publication resolution: owner explicitly approved exact three-file Settings publication to public KPOne. Supported API retried successfully after fresh approval; draft#1240 https://github.com/putrijayamediateam/KPOne/pull/1240 head d2d13fdc248643892cd3015f30b944afb630c836. Tree b554694e901a49f90caaef0feb99609ac99ec6e1 identical to reviewed original3bf91447, preserved archive branch; local clean aligned to API commit. No merge.
