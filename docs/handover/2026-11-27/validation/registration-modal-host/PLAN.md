# Registration modal host: concrete bounded contract

Date2026-11-26. Proposal against frozen form extraction #1190. Implementation awaits bounded peer review, then proceeds under standing technical delegation. No migration/grant/new business state.

## Evidence

- Authoritative RegisterVisit.dc.html: real dialog with scrollable body, close/cancel links to Registration, one submit. Demo wrapper intercepts links/hash navigation; it does not define a safe production dirty/back contract. Demo persistence is simulation infrastructure and must not be copied for patient drafts.
- RegistrationController::create authorizes Visit create and returns guardianToday/options/requirements/recentPatients. It has no modal projection today. Store redirects successful queued registrations to the real board; validation returns to the referring page.
- Existing requestJson sets application/json, same-origin/no-store and strips X-Inertia. Use this helper, not hand-parsing full Inertia shared page props.
- Existing shared Dialog/Content provide modal focus trap and forwarded dismissal events. OperationalConfirmDialog supplies existing confirmation shell. RegistrationSettings uses router.before and beforeunload for unsaved edits; neither is a reliable cancellable browser popstate guard.

## Recommended A: board-owned native modal, no history entry

The current authorized Register Visit anchor retains href=/registration/create as full-page/no-JavaScript/new-tab fallback. A normal unmodified click on the actual Registration board opens one modal host and fetches the same create route with Accept application/json. Modified/new-tab clicks navigate normally. The board remains the actual mounted page with its current filters/rows; no fake or recreated board backdrop.

The controller builds its existing four props once and returns JSON only for the explicit JSON representation; HTML/Inertia navigation remains existing Create response. Same authorization and services, no extra records/columns or new endpoint permission. New backend tests must prove HTML unchanged, JSON same scoped shape, unauthorized/inactive context refused and branch/idempotency/options tied to current actor.

Host loader aborts on close/unmount/context change, generation-checks late success/error, compares returned command branch with current authorized branch, and never mounts stale data. Failure displays a readable error plus full-page fallback/retry; no blank form or made-up options. Only one RegistrationCreateForm instance mounts after valid load.

Expose read-only form lifecycle to the parent: processing, draft presence and registered-success event. Draft presence includes selected patient/quick-create state and actual form edits; generated command keys are not independent user edits. Direct full-page route gets no new guard/behavior. Child submission validation/payload/branch/retry handlers remain unchanged except a success notification consumed only by the modal host. Successful existing index redirect closes modal; failures retain same child state and visible errors. If success navigates to VisitDetails, ordinary unmount applies.

## Close/draft/focus

- Loading close aborts load, returns focus to Register Visit trigger.
- Clean close/cancel/Escape dismisses and unmounts; next open loads a fresh command.
- Outside pointer dismissal never discards a draft. Dirty close/cancel/Escape opens the existing confirmation shell: Keep editing returns to same child unchanged; Discard closes and clears in-memory state. No localStorage/sessionStorage/history draft storage.
- Processing blocks host close/cancel/Escape/outside. Do not cancel the submitted registration command or manufacture rollback. In-app navigation guard must distinguish the form's own POST from unrelated navigation so it cannot block submission or its success redirect. Review actual Inertia event ordering before implementation.
- Modal title and internal scroll body use existing shared tokens and44pxmobile/36pxdesktop targets. Fullpage sticky toolbar must receive explicit presentation prop only if required to avoid overlap inside the dialog; do not change its default. Close returns focus to real opener if still present, otherwise the board heading. Preserve error focus and nested select/picker accessibility.

## Back/reload distinction (technical choice for coordinator)

A adds no history entry: browser Back/Forward keep the existing board navigation behavior; leaving unmounts the modal/draft. We must NOT claim a Back-confirmation or draft restoration that router.before cannot provide. Explicit close controls and supported in-app navigation can guard unsaved work; browser refresh/tab close uses beforeunload when applicable. No popstate push/forward trap.

Alternative B: URL-addressable modal with Back-as-close and draft-preserving Forward requires a persistent host/history contract and likely shared-layout changes or sensitive draft history storage. This is larger than the current native-dialog slice and is deferred unless selected explicitly. It should not be improvised with a fake background board or uncancellable history rollback.

## Owned files / gates

Index.vue trigger + host mount; new RegistrationCreateDialog.vue/loader helper if useful; RegistrationCreateForm.vue presentation/lifecycle surface only; RegistrationController JSON representation and new backend tests; new runtime/browser tests. No shared Dialog primitive edits. Preserve prior nav/composition branches when integrated; exact source-test impact reviewed before edits.

Tests: loader authorization/branch/abort races, one form owner, clean/dirty/processing dismissal and focus, one POST, actual server refusal retaining form, actual successful synthetic registration via existing service only if coordinator permits test fixture write (standing synthetic testing normally permits it), fresh reopen key, no leaked timers/listeners, direct URL/new-tab fallback, real board filters preserved, Back behavior explicitly as A.390/768/1440 both themes, nested select/keyboard/error target geometry; full gates and independent exact-hash review before publication.
