# BIL37 exact refresh independent review

Reviewed head3c2f5b1f8373c581e5ad5d262062f2fa262a24ec containing main83c5999. Exactly18 main-relative files. Independent frozen-manifest comparison confirms15 unchanged blobs; the three differences are BillingPermissions, routes/web.php and TEAM.md. Current-main-relative permissions add only Resident Doctor billing.view_readonly.branch, preserving newly merged BIL36 Finance/Director print grants. Routes retain upstream additions and add only the scoped readonly billing review route/controller import. TEAM keeps both BIL36 and BIL37 rows. No refresh drift found in service, migration, page, types or tests.

Reviewed readonly service and fail-closed shared audit path: current active branch and own assigned/attending doctor determine access; drafts are excluded, projection stays explicit, audit precedes returned content, private billing metadata/actions are absent. Existing shared queue.details_viewed audit event is retained alongside billing.reviewed. The recorded View bill sizing follow-up remains36 desktop/44 below1024; no new layout or clinical workflow bundled by refresh.

Independently ran bil-37-doctor-bill.test.mjs:2/2 pass. Root reports fresh20 backend/263 assertions and Pint/PHPStan green; those are not independently rerun here. No bounded source/parity blocker. Exact-head CI remains coordinator release gate; no merge performed.
