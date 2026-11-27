The Dispensary case and doctor-check/attention partials still used legacy palette and field styles, and some tablet controls dropped below 44px. Apply the approved Clean Look surfaces, blue scan hints and semantic warning states, with 36px desktop / 44px mobile and tablet targets. Keep the existing case layout stacked below 1280px, as specified in the approved case-layout exception.

This is a nine-file presentation-only change: three Vue files and six owner-approved appearance-test updates. Both partial scripts match main byte-for-byte; the case script differs only in two presentation class strings and their comment. Clinical conditions, permissions, actions, request payloads and verification behavior remain unchanged. No backend, migrations, grants, Treatment, TRI2 or unrelated Dispensary features are included.

Validation:
- 118 case-focused and 28 partial-focused tests pass (overlapping suites).
- Scoped ESLint/Prettier, full vue-tsc, production build and diff checks pass.
- Independent review verified the exact approved patch, script parity and assertion-approval union; six changed test files independently passed 24 tests.
- The three Vue files exactly match previously reviewed source with synthetic responsive/light-dark screenshots. Those are carried visual evidence, not a new main-based browser run. No full backend suite was rerun for this presentation-only patch.

Known limit: existing inner medicine-field columns remain dense at 1440px; this change preserves their clinical grouping. DD57 retains its previously approved descriptive test title while its assertions now reflect both approved semantic-surface changes.
