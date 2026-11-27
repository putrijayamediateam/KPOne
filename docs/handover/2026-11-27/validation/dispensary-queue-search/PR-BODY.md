Dispensary search now finds the A-/B- queue number already shown on a case. Matching is exact and case-insensitive, with positive bigint validation, while preserving existing branch, status and Type filters. KP/KPV/name search, search-independent counts and Done-today boundaries remain unchanged. The placeholder names supported search fields; medicine search remains a separate gap.

Stacked on #1210. No permissions, migrations, clinical mutations or existing assertions changed. Cases without queue numbers retain null.

Validation:13 focused PostgreSQL tests/311 assertions,10 existing frontend tests, changed-path static/format checks, full types and production build passed. Twelve actual synthetic390/1440 light/dark states plus exact KP/KPV/name/lowercase/overflow checks passed using GET-only browser interactions. Independent backend and Design review are recorded in the bounded review package.
