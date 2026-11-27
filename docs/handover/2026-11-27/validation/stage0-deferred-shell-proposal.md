# Small Stage0 follow-up proposals (not implemented)

Source: docs/design/CLEAN-LOOK-SPEC.md rail behaviour, lines153–155. Prepared by staff_design, 2026-11-26. Root approval to edit still pending; this document is a technical proposal, not a new owner permission gate.

## Per-person pin preference

In WorkspaceRail.vue, replace constant storage key with computed key `kpone.shell.pinned:${user.value.id}`. Read it on mount; when auth user id changes, load that user's value with default false. Persist changes only to the current user's key, keeping current try/catch and offset update. During user-change hydration, suppress persistence until the stored value is loaded (or use an explicit togglePin handler to persist only user actions). Do not migrate the old browser-global key into individual identities: its owner cannot be inferred. Leave it unused; no database changes.

Prefer explicit togglePin(): update pinned, persist current user key; use watch on pinned only for emitOffset. Watch user id after mount to reload per-account preference and recompute offset. This avoids writing the previous person's state to the new person's key during a reactive auth change.

Focused checks: A pins then reloads (pinned), B signs in same context (default unpinned), B sets its preference, A returns (A retained); blocked localStorage does not throw; CSS offset matches each loaded preference. No assertion replacement needed.

## Close after focus leaves shell

Add desktop rail/panel refs and a shared focus-boundary check in WorkspaceRail.vue. On focus entering rail/panel, cancel pending close; on focus leaving, start existing300ms delay. At expiry re-check document.activeElement; close only if outside the rail, panel and their owned popup content. Re-check avoids races while moving from rail to panel; keep pinned visibility unchanged. Escape must still suppress rail reopen.

Portalled popups are important: branch Select and account DropdownMenu are outside rail/panel DOM. Mark the shell account DropdownMenuContent with a shell-specific data attribute. For branch SelectContent, add an optional `shell`/`overlayOwner` prop to WorkspaceBranchSwitcher.vue that supplies that marker only when invoked from the shell. Pass it from WorkspaceRail. Include marked popup descendants in focus boundary. This is a two-file scoped fix; do not merely close on `focusout` or accept every page popup globally. Never close/unmount the branch selector while keyboard navigation is inside its portal.

Recommended event strategy: document focusin listener inspects new active target, cancels when owned, schedules when outside; rail/panel focusout handles window blur/null targets. Reuse clearTimers on unmount. Keep pointer hover behavior unchanged except do not close a panel under active keyboard focus.

Focused browser checks: Tab out closes after300ms, rail→panel stays open, Shift+Tab back stays open, branch popup option keyboard movement/selection works, account menu works, external page focus closes, pinned panel persists, Escape restores focus without reopening. These interaction tests are new coverage, not changes to approved assertions.
