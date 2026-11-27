# Phase 0B — Operational staff identity administration

## Objective

Phase 0B turns the Phase 0A identity foundation into a controlled internal staff lifecycle. It remains a foundation/administration phase: no patient, clinical, queue, billing, HR workflow, finance workflow, website, Yezza, WhatsApp, OTP, or public portal functionality is present.

## Delivered capability

- Server-filtered staff directory with branch, department, role, status, and text filters.
- Staff detail view covering identity, employment, status, roles, current primary branch, past/current/future assignments, and authorised audit entry points.
- Transactional internal account provisioning with explicit organisation ownership from the actor context.
- Google-only pre-provisioning or strongly validated immediately hashed password access.
- Explicit profile and department editing without broad model mass assignment.
- Multiple approved roles through Spatie Permission.
- Effective-dated branch create/update/end/primary operations through `BranchAssignmentService` only.
- Explicit activation and deactivation integrated with inactive login/session rejection.
- Server-side higher-authority target protection and deliberate confirmation for status/access actions.

## Existing permission model reused

Phase 0B deliberately does not add redundant permission names:

| Operation | Required existing permission(s) |
| --- | --- |
| View directory/detail | `staff.view.own`, `.branch`, or `.organisation` according to scope |
| Provision complete account | `staff.manage.organisation` and `access.manage.organisation` |
| Update identity/profile/department | `staff.manage.organisation` |
| Activate/deactivate | `staff.manage.organisation` |
| Change roles | `access.manage.organisation` |
| Change branch assignments | `access.manage.organisation` |

Policies and domain services add subject-specific authority checks. Browser navigation visibility is only a usability aid.

## Transaction and audit boundary

`StaffProvisioningService` owns one outer database transaction. It creates the user and profile, synchronises Spatie roles, invokes nested `BranchAssignmentService` transactions for every assignment and primary promotion, verifies the effective-primary invariant, and records `staff.created`. Laravel nested transactions share the same connection/transaction semantics, so a late failure rolls back user, profile, role pivots, assignments, promotions/demotions, and audit rows together.

Tracked tests inject failures after role state begins changing and after multiple branch writes/audits. They prove database counts remain unchanged and no partial account survives.

Identity and access events include:

- `staff.created`
- `staff.identity.updated`
- `staff.profile.updated`
- `staff.department.changed`
- `access.role.attached` / `access.role.detached`
- `staff.activated` / `staff.deactivated`
- existing branch assignment created, updated, ended, demoted, and promoted events

Audit metadata contains explicitly selected identifiers and safe before/after fields. Passwords and credential material are never passed to the recorder.

## Authority and lifecycle invariants

- Actor and target must belong to the same organisation.
- Runtime staff administration and branch-assignment services require an explicit, non-null actor; omitting an actor is never a trusted-system authorization mode.
- The privileged staff-administration update path cannot edit the actor's own record. Normal self-profile changes remain in the separate settings path.
- Self role, access, and status mutation is rejected.
- The actor must cover the target's current effective administrative (`*.manage.*`) permissions.
- Proposed roles are restricted to catalogue names and their actual seeded database permissions must be covered by the actor at the same or a broader explicit scope.
- Director is an explicit protected authority role; a non-director cannot govern a director even when platform permission bundles otherwise match.
- Technical administrators receive no clinical permission by implication and cannot assign a role containing an unknown clinical capability they do not hold.
- Provisioned staff have exactly one currently effective primary assignment. In Phase 0B, primary means the stable home/base branch and remains non-expiring while the account is active. Temporary dated assignments provide additional operational access; temporary-primary scheduling and automatic fallback are deliberately deferred.
- Reactivation requires exactly one currently effective primary assignment.
- Historical assignments are ended, not deleted.

## Fortify registration residue

Public registration remains absent from `config/fortify.php`. After `StaffProvisioningService` and its tests existed, repository search confirmed no binding or reference to `CreateNewUser`, the route list confirmed no registration routes, and negative registration tests remained in place. The unreachable starter action was then removed so internal provisioning is the sole account-creation path.

## Residual limitations

- Forced first-login password rotation and invitation delivery are not implemented. Google-only provisioning is preferred where organisational OAuth policy permits; password bootstrap requires a controlled local procedure.
- Branch assignments use inclusive date-only validity. Access remains effective through `valid_until` and ends the following day; immediate same-day branch revocation is not supported without a future, deliberately designed datetime lifecycle.
- Branch-scoped administrative mutation is intentionally not introduced. Phase 0B mutations are organisation-scoped pending an approved responsibility/delegation matrix.
- The access-control catalogue page remains read-only; Phase 0B changes staff role membership, not role definitions or arbitrary permissions.
- Production deployment roles, audit database grants, retention/export, recovery procedures, and formal regulated-data threat modelling remain separate work.
