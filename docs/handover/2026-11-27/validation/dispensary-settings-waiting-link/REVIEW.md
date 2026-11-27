# Settings waiting-case navigation

Stacked on1191/db23d6af. Separate nullable waitingCasesUrl is generated only through existing DispensaryDirectoryService::canView on the freshly reauthorized reader actor, using named dispensary.index route and TAB_CHECK. The current setting need not be on: existing waiting cases can still require checking. Destination middleware/service authority and branch filters remain unchanged. No settings-permission shortcut, role shortcut, new query/count/grant or clinical state.

Five files: reader, Settings.vue, existing backend/frontend tests with new cases/harness support only, TEAM.md. No existing assertions changed. Original settings two-key DTO and metadata two-key/null contract untouched; toggle script change only imports Link/adds optional URL prop. Exact patch d597bf00b4b61b8d91365d3e36cdf7edcf67001ac33f1301815bc51496a00af7.

26 PostgreSQL tests/239 assertions pass (8reader +18doctor-check);11 frontend tests pass; full types/build, PHPStan0/Pint/lint/format/diff pass. Browser8 role/width/theme states at390/1440 light/dark prove44/36 target and no overflow. Keyboard Enter follows the real check tab with check-note marker while setting is off. Settings-only Director sees no link and direct destination GET returns403. Browser performs no mutation. Synthetic fixture switches its task-owned branch off through the existing settings service; all case status/version snapshots remain unchanged.

Only task-owned kpone_settings_waiting_link_preview_test at127.0.0.1:55439, preview3090. No real data or production changes. Independent source and Design reviews recorded separately. Existing switch colour and duplicate branch text remain separate presentation debt. No full Settings demo parity or merge claim; remote CI required.
