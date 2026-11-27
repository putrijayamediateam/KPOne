Legacy cards and side sheets still used pre-Clean-Look corners, surfaces and shadows after the shell update. Align them with the existing shared tokens: 14px card corners and soft card shadows, no duplicate nested-card shadow, popup sheet surface/shadow, and 18% light / 50% dark backdrops.

Three source files only. No permission, migration, dependency or existing assertion changes. Follows merged #1125 and contains main 88210df4347404506ac55f49d0afb14f974e9385.

Validation:
- 60 existing scoped frontend tests passed.
- Isolated TypeScript and production build passed.
- 2,235 combined frontend tests passed with these exact token modules.
- Independent source review and matching light/dark, mobile/desktop visual evidence passed.
- Diff checks passed; backend code unchanged from the fully validated shell.

Owner reviewed the exact three-file diff and authorized this commit, push and separate PR on 26 November 2026. Exact-head CI and current-main containment must remain green before merge. No deployment included.
