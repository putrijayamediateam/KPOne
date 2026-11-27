# F24 settings metadata source review

Read final new DispensarySettingsReader, controller show diff, existing mutation/current service and six new DispensingSettingsReadTest cases. Read author backend-final24/216 PASS and PHPStan0 logs; no independent heavy rerun or source edit.

No blocking source finding. Reader refreshes user/activity/roles/permissions, checks active same-organisation branch and effective assignment (existing Director exception), then reads exactly one scoped settings row for both displayed switch/version and attribution. Conservative provenance requires updater, timestamp, retry key and version>1, so seeded/default or unattributed legacy timestamps do not become fictitious changes. Name uses existing visibleUsers scope restricted to one updater with Staff member fallback; output is only localized changedAt/changedBy, no IDs/email/role. Original settings DTO and mutation locking/retry/audit behavior remain untouched.

New tests cover localized next-day timestamp and HTTP shape, missing/default provenance, hidden updater, stale inactive/role/assignment denial, wrong active branch/org/disabled branch, no-op/retry attribution stability, one settings query and constant query count as users grow. Same-row snapshot closes the plan review concurrency concern. An extra malformed foreign-org updater fixture could be additive defense coverage, but source visibleUsers organisation predicate already prevents its identity disclosure and this is not a blocker.

Bounded backend/read contract clear. Frontend visual/accessibility and final exact publication gates remain author/Staff responsibilities; no broader Settings authorization or mutation redesign signoff implied.
