# BIL-38: Director publishes prices

Status: reconstructed design, 26 Nov 2026. The owner clarified the scope in this Codex chat: Director may publish prices; staff-account administration must not change. The earlier design note was not found on the fetched branches. The four original test changes and two later catalogue fixtures were approved in this chat, with positive coverage. Publication candidate is isolated in `/workspace/KPOne-bil38-publication` on `review/bil38-publication`, based on main `953e9d75fa33bf66bc90933079e7a1a78e1e5c55`. Publication approval and independent release review remain separate gates.

## Proposed implementation

Grant the existing `prices.publish.organisation` permission to `director` in `BillingPermissions::roles()` and add an additive migration for existing Director roles. Retain all current holders. Do not create a new permission or role. Follow the existing migration convention: web guard, grant missing permission only, clear permission cache, missing role logged and skipped, no destructive rollback.

Reuse the current publish controller/service and its branch/organisation boundary, validation, version guard, transaction and audit. Do not modify `PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS`, `isAdministrativeAuthority`, staff policies or `StaffAuthorityService`.

## Exact existing tests requiring decisions

1. `SupervisorPricingTest::test_exactly_these_roles_hold_the_two_pricing_permissions`: add `director` to the sorted publish holders: administrator, ca_supervisor, director, finance_officer. **Approved individually by the owner in this chat, 26 Nov 2026.**
2. `SupervisorPricingTest::test_finance_officer_and_director_behave_exactly_as_before`: change only the Director publish assertion from false to true and rename the test to describe the new rule. Keep Finance grants and all other roles' refusals. **Approved individually by the owner in this chat, 26 Nov 2026.**
3. `PricingControllerTest::test_publish_requires_its_own_permission_separate_from_reference_management`: preserve the reference-only refusal by explicitly removing the publish grant from the synthetic Director fixture before the request. Add a separate positive test for a normal Director publishing successfully. **Approved individually by the owner in this chat, 26 Nov 2026.**

A fourth affected test exists on current main after IMP-1 merged:

4. `CatalogueImportTest::test_the_actor_must_hold_all_four_permissions_and_a_wrong_header_is_refused`: its Director-denied fixture relies on Director lacking publish permission. Remove that grant explicitly from the synthetic fixture to preserve the missing-permission refusal; add separate coverage for the now-authorised Director. The CLI currently checks capabilities, not an Administrator-only role. **Approved separately by the owner in this chat, 26 Nov 2026; Sunday's operator remains Administrator.**

The handoff specifies a named Administrator for Sunday's catalogue import. Keep that runbook actor unless the owner explicitly changes it, even if the Director has all required capabilities after BIL-38.

## Validation required

Run the three test files above, StaffAuthorityBoundaryTest, a focused additive migration test (repeat execution, missing role, unrelated grants unchanged), and the full repository gate. The existing staff-authority assertions must remain unchanged and pass.

Owner decision, 26 Nov 2026: explicitly approved the drafted additive migration `2026_11_26_250100_grant_director_price_publication_additively.php` by multiple-choice reply. This is local implementation approval, not production migration or deployment approval.

## Additional owner approvals, 26 Nov 2026

5. `ClinicalServiceCatalogueControllerTest::test_consultation_tariff_publication_remains_separate_from_reference_management`: explicitly revoke publish from its synthetic Director, preserving the existing refusal assertions. Add a separate ordinary-Director tariff publication success test. **Owner approved both.**
6. `UnifiedCatalogueSetupTest::test_price_publication_failure_rolls_back_the_entire_medicine_setup`: explicitly revoke publish from its synthetic Director, preserving rollback assertions. Add a separate ordinary-Director linked medicine and price creation success test. **Owner approved both.**

The STK-46 RoleOne expected-permission table update is excluded: no STK-46 grant exists in this standalone candidate. No BIL-36/BIL-37 permission changes are included.
