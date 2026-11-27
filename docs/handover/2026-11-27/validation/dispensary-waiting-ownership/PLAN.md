# F08 Waiting ownership — contract assessment, not implementation

The locked Pharmacy demo's Waiting tab shows Whose cases: Mine / All doctors, initially Mine. Existing published row-context1235 ListService exposes the branch-scoped waiting list under existing Directory canView: branch CA/CA Supervisor/MA or existing label-view permission. It has no ownership predicate, while Prepare's independent doctor filter is already normalized away on Waiting. Waiting rows link to the existing case; no new check action is implied.

A read-only filter can safely narrow the existing authorized branch scope to visit.assigned_doctor_user_id=current actor. That is attending-doctor ownership, not current_handler_user_id or sent_for_check_by_user_id (both preparation actors). It must never widen beyond current list authority. However, on CA/MA viewers that definition naturally yields no cases. Applying the demo's Mine default to every viewer would change existing operational visibility without clarifying that meaning.

Coordinator technical disposition needed before code: whether Mine should be available only to existing attending-clinician viewers, and whether that audience defaults Mine while CA/MA keeps existing All. An alternative explicit Assigned to me label could avoid ambiguous preparation ownership, but diverges from literal demo wording and also needs disposition. Do not infer a new role grant or actor directory.

Once pinned: use a scalar normalized query (mine/all), Waiting only, clear on leaving; scope its Waiting count/rows consistently and preserve Prepare/Done/search/day/pagination contracts. Mine is additional assigned-doctor predicate only; All means all already-authorized branch cases, never all branches. No Mark checked, Ready state, clinical write or permissions change. Test crafted unknown/array values, branch/organisation isolation, distinct preparer-versus-attending assignment, empty Mine truthfulness and other tabs clearing. Preserve existing Verify-completes decision and case-only workflows.

No source changes made for F08. Continue unrelated reviewed work while this contract is resolved.
