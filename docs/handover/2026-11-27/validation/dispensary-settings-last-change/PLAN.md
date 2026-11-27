# F24 last changed — minimal existing-data reader proposal

Preparation only; no application edits. Current reviewed Settings context intentionally omits fabricated metadata. Remaining demo F24 Last changed can be supplied from existing branch_dispensary_settings.updated_at/updated_by_user_id without a new write or permission.

Proposed contract: an optional separate settingsChange prop containing localized changedAt and changedBy label, or null when no attributable recorded change exists. Use current active branch, organisation and existing settings.manage authority; reauthorize reader consistently with existing settings service before querying. Display no raw numeric user ID, email or role. Resolve name through existing StaffAccessService::visibleUsers(viewer); fall back to Staff member when updater identity is not visible. No new staff visibility. Date localized using existing branch timezone, not client timezone; no invented initial/default timestamp. Exact null semantics require checking seeded/legacy row provenance before implementation.

Proposed files: DispensarySettingsService or narrowly scoped reader, SettingsController, Settings.vue, new backend feature tests and new frontend runtime tests, TEAM.md. Existing snapshot/whole-prop assertions must be inventoried and a faithful exact impact listed before any change. Prior context test's no-Last-changed assertion belongs to that slice and will need honest narrowing to no-fabricated-value if reused; do not silently weaken privacy checks.

Validation: authorized Director and assigned CA Supervisor/current permission holders; no unauthorized CA view, wrong branch/org or stale assignment; missing row/updater and hidden updater fallback; localized timestamps; constant bounded query cost; no extra clinical/staff data in payload. No migrations, new role grant, write behavior or audit mutation. Existing settings toggle request/idempotency/locking untouched.

Waiting-case link is a separate authority-filtered contract, not implied by settings.manage. Do not add it with this metadata reader. Root/Design review before implementation because this is an E-class read-contract extension, not a CSS-only patch.
