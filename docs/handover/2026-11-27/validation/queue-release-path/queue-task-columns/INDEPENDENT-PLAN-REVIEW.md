# Independent staged column plan review

Read locked canonical-columns.json, PLAN.md and actual PatientBoard Status block/mobile CSS at reviewed context cc73b152. Clear to implement only ordinary own Waiting/Seeing opt-in six-column presentation first. No source implementation approval is implied yet.

The internal reusable Status renderer must preserve status/urgent/returned/check warning/completed timestamp/call-hold readiness/actions/treatment badge and action predicates/events exactly. Parent retains the single treatment-dialog key; avoid duplicate or hidden active dialogs when layout/task changes. Suppress triage only where the dedicated Zone renders the same authorized projection; null/withheld differs from explicit untriaged. Preserve row identity, default Registration/emergency markup behavior and full-width mobile treatment Status rules. Patient-cell safety metadata must remain readable and action-reachable.

Existing source-location assertions require exact proposed adapters before alteration. Require default/task actual-render matrix, event/dialog wiring tests and short-phone browser proof. Medicine Items/Sent by, actual completion timestamp and current downstream location remain explicit separate DTO contracts. Existing demo action labels do not authorize new Resume/Check mutations.

Populated Medicine-check prerequisite is independently reviewed in queue-checks-browser/INDEPENDENT-REVIEW.md. Its existing Open consultation is POST encounter entry, so the read-only proof intentionally did not invoke it.
