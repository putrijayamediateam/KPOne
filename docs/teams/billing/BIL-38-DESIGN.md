# BIL-38: the Director may publish prices (design note)

Owner decision, 24 Nov 2026, relayed by the coordinator: asked whether the Director should be able to publish a price,
the owner ticked "Benarkan Director".

Today `prices.publish.organisation` is held by `finance_officer`, `ca_supervisor` (PX-01) and `administrator` (ADM-01).
The Director holds `pricing.references.manage.organisation`: they reach Pricing and manage charges and price books,
but cannot publish an amount. This separation dates from P0; PX-01 and ADM-01 already widened publishing. Synthetic data
only. Classic only. No money rule changes: the publication path itself is unchanged.

## 1. Change

- `BillingPermissions::roles()` adds `prices.publish.organisation` to `director`.
- One additive migration in my range, `2026_11_25_200200_grant_director_price_publish_permission`: `firstOrCreate` the
  permission, `givePermissionTo` only when missing, a missing role logged and skipped, `down()` a no-op. Nothing goes to
  `ca`, so the `medical_assistant` mirror rule does not apply.
- What the Director gains, through the existing gates:
  - `PricePublicationService::publish()` (actor lock with the permission at the active branch, optimistic version,
    audit);
  - `ConsultationTariffAdministrationService`;
  - the `canPublish` / `canPublishPrices` flags on the Pricing, Medicine Catalogue and Clinical Service Catalogue pages.
  No code changes there.

## 2. Staff authority (checked)

- `prices.publish.organisation` is **not** in `PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS` (staff, branches,
  access, clinician credentials), so `StaffAuthorityService::canManage()` is unchanged: who may administer whom does not
  move.
- `canAssignRoles()` is unchanged too:
  - the Director already passes it as the protected governance authority;
  - every other actor still may not assign `director` (protected role);
  - adding a permission to the Director's role can only make it harder, never easier, for a non-Director to cover the
    Director's permissions.
- `SupervisorPricingTest::test_the_grant_does_not_touch_staff_administration` and
  `BillingBoundaryTest:74` (`isAdministrativeAuthority` false) stay as they are and keep proving it.

## 3. Existing assertions that change (owner approval needed one by one)

1. `tests/Feature/Billing/SupervisorPricingTest.php:50`, `test_exactly_these_roles_hold_the_two_pricing_permissions`:
   the publish holder list `['administrator', 'ca_supervisor', 'finance_officer']` becomes
   `['administrator', 'ca_supervisor', 'director', 'finance_officer']`.
2. `tests/Feature/Billing/SupervisorPricingTest.php:122`, `test_finance_officer_and_director_behave_exactly_as_before`:
   `assertFalse($director->can(PUBLISH))` becomes `assertTrue`. The test name would also change, to
   `..._finance_officer_and_director_hold_both_pricing_permissions`.
3. `tests/Feature/Billing/PricingControllerTest.php:66-79`: the Director's publish POST is expected 403 with no price
   entry. It becomes a successful publish (redirect, one `price_entries` row). The comment about "deliberately distinct
   permissions per the P0 phase design" is replaced by the BIL-38 decision.

Not affected:
- `AdministratorRoleTest:26` (the Administrator still holds it);
- `RoleOneRolesTest:150` (Branch Manager still must not hold it);
- `StaffAuthorityBoundaryTest:69` (it stays a non-authority permission);
- `PanelSettingsTest` (no reference to publishing);
- `PostgresBillingRegressionTest:418` (a custom user).

`tests/Frontend/fixtures/access-control-roles.json` is a static, already stale snapshot and is not compared with the
catalogue. The full run will confirm the list; anything further goes to the owner first and stays failing until approved.

## 4. Tests

- A new test: the Director publishes a price (one entry, version 1, audit `billing.price_published`, as for the Finance
  Officer), and the migration grants additively, idempotently and never revokes (up, down, up).
- No new contention test is needed: `PostgresBillingRegressionTest:418` already races a publish against finalising for any holder of the permission, and the path does not depend on the role.
