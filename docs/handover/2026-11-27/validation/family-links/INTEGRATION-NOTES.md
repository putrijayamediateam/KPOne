# Family integration notes

Feature patch is against clean main23639679e. Read-only apply-check against `/workspace/KPOne-ca-integration` found three context conflicts, not semantic incompatibilities:

1. `phpunit.xml`: add `PostgresFamilyLinkContentionTest.php` alongside existing Patient merge contention entries: Feature exclusion, Contention file and Contention3 file. Preserve emergency and all other suite entries.
2. `resources/js/pages/Patient/Show.vue`: add FamilyLinksCard import and its existing minimal template block. Preserve EmergencyContactCard import/props/template and all recovered styling.
3. `routes/web.php`: add PatientFamilyLinkController import and the two family GET/POST routes; preserve emergency routes and every other current route.

Clinical page/directory, clinical types, TEAM and new files passed the current snapshot's apply check. Clinical FamilyWaitingCard is only shown for non-null server projection. Backend Family record URL points to `#family-links`.

Do not copy entire clean-main Patient or Clinical pages over integrated styled versions. Family migration is independent of emergency schema and introduces no role grant. The new guarded command tables require migration before preview routes are exercised. Use the integration tree's own synthetic DB.
