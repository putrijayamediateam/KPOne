# Modal lifecycle implementation contract (source preparation)

Root selected Option A; Staff bounded plan review clear with explicit browser validation/success/unknown-result gates. No modal source installed while #1171 CI recovery is prioritized.

## Exact API approach

RegistrationController::create retains policy authorization first and the same four service-produced props. Return a private no-store JSON representation only when the request explicitly wants application/json and is not an Inertia request; otherwise retain existing Inertia Create response. Reuse requestJson (same-origin, no-store, X-Inertia removed). Never serialize wider shared page props or fabricate form options.

The actual Index remains mounted behind the shared Dialog. A normal unmodified Register Visit click opens it; existing href and modified/new-tab behavior stay fullpage. No history entry. Back retains ordinary board-navigation/unmount behavior; no recovery promise or history trapping.

## Form lifecycle

One child mounts after validated authorized load, unkeyed while open. Fullpage default behavior remains unchanged. Optional modal presentation moves only action positioning needed for an internal scroll container; all fields/errors/submit remain in the same form.

Expose readonly current processing/draft/command-key state to host; keep mutable form internal. User draft includes selected/quick mode and real form edits; generated keys alone are not changes. Existing useForm.post options retain preserveState/onError. Add a narrowly scoped success notification; host closes only on successful Inertia response for the expected Registration result component. Validation errors retain same child. Network/HTTP exceptions produce an explicit unconfirmed result message with same command/draft; onFinish alone never means success. No automatic key reset/reopen after unknown outcome. Explicit discard explains it discards local draft, not a possible server registration.

Actual Inertia3.6.1 ordering: useForm.onBefore resets before submission, router checks event before onStart sets processing. A host navigation guard must allow its own matching POST /registration command (key matches child), not block it as a dirty navigation. The resulting response redirect is handled by the request lifecycle rather than a new user visit. Unrelated in-app navigation while processing is refused; ordinary dirty navigation follows existing confirm convention. Remove listeners on unmount. Browser popstate remains uncancellable existing behavior; do not advertise a broader guarantee.

## Polling and context

Coordinate existing Index polling while submission navigation is active: cancel scheduled/in-flight board refresh and invalidate generation before it can overwrite the response, then resume from existing current filter/page when submission finishes or modal closes. Keep current filters; after success, query using those existing filters so index server default rows cannot be mistaken for filtered results. Do not invent counts from rows.

Load cancellation/generation guards cover close/reopen/branch changes. Initial response branch must match active command context. Once a draft exists, a context change must not silently rewrite expected_branch_id, reload a fresh command or move the draft to another branch. Mark context stale and require explicit discard/reopen; existing server branch/authority enforcement remains final. No PHI persistence.

## Close/focus

Disable automatic close X and use an owned labelled44/36px Close control. Processing ignores Close/Escape/outside. Dirty Close/Escape uses shared OperationalConfirmDialog with Keep editing/Discard labels; Keep editing restores prior focused form field and leaves the same instance. Outside click may simply remain open to avoid accidental confirmation. Nested Reka picker portals must remain usable inside the modal; test actual keyboard/pointer interaction. After true dismissal, restore actual opener if present or board heading. Validate actual focused server error below dialog chrome.

## Required actual evidence

Real server validation redirect on Index retains selected/new patient, errors and original command. Real successful synthetic atomic registration closes exactly once and updates board while retaining active filters; verify one visit/queue entry despite retry. Deliberately lose a committed response, confirm unknown-state draft retention and same-key retry recovers original result without duplicate. Test processing dismissal refusal, dirty Keep editing, explicit discard/fresh key, stale loader/revocation refusal, direct route/new-tab fallback, Back behavior, nested pickers and keyboard focus at390/768/1440 light/dark. Existing test adaptations require exact independent delegated review before edits.

## Implementation dispositions from actual proof

- CI #1171 timeout investigation closed before implementation: isolated shard and one same-head CI rerun passed, underlying transient timeout cause remains unknown.
- The Register Visit opener uses a native anchor with the original fullpage href. Actual browser evidence showed Inertia Link's composed handler still navigated despite the custom click callback; native interception preserves Ctrl/new-tab and keyboard activation.
- No forced search bypass remains. Generic readiness/mutation refresh retains its original automatic guards. Modal close waits for dialog transition, typing/menu, visibility and in-flight protection; QR uses its existing QR projection reload. Only confirmed registration success resets a previously exhausted poll-error budget; Cancel preserves paused state.
- A real Keep editing test exposed OperationalConfirmDialog's focus restoration overriding the host's nextTick. The owned confirmation now uses the same shared Dialog primitives with explicit close-auto-focus prevention/restoration and safe Keep editing initial focus. No shared component is modified. A disconnected prior field falls back to a currently mounted form control.
- Source-test disposition: Staff independently approved the exact sticky-class branch adaptation; three Index harnesses merely map the new dialog import. Existing assertions in those harnesses and REG-LIVE remain unchanged; added runtime cases cover the new lifecycle/refresh behavior.
