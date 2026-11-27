# Staff Experience / Design review — Stage 0 shell

Independent review by the staff_design agent under B.E.R.N.I.S.S, 2026-11-26. This is current-diff evidence, not Daniel's historical approval or complete staff UAT. Reviewed the recovered shell, ShellMenu/management integration, registration routing, shared card/sheet primitives, and available desktop/mobile evidence. Read AGENTS.md, CLAUDE.md and current progress/handoff material.

## Slice plan, files and spec

Own only `resources/js/components/shell/WorkspaceRail.vue`: restore keyboard entry into each area submenu, make long mobile navigation usable at short viewport heights, resolve lint blocking the new active-area build. Spec intent: keyboard-operable rail, Escape restores focus, usable mobile drawer, active destination highlighted. Parent owns other Stage 0 source and integration. No permissions, role assignments or existing test assertions changed.

## Review findings and diff

- Fixed blocker: keyboard focus traversed successive rail icons and replaced the panel before reaching its links; Enter visited the area's primary route. Added `focusPanel`, using Vue nextTick before focusing the chosen panel's first link. Enter, Space and ArrowRight now enter that panel. Added aria-expanded/aria-controls and a matching panel id; removed unused router import.
- Fixed blocker: Director HR drawer at 390×568 had content extending to y=992, sheet scrollHeight1048 and no working scroll container while body scrolling was locked. Bounded sheet overflow and made its flex nav min-h-0/flex-1/overflow-y-auto. Header and close control remain outside the scroller.
- Added the two required blank lines in the coordinator's linkActive logic, allowing lint and build to complete. Retained query-aware active matching and visit Billing routing.

Risk: low/moderate UI interaction change. Keyboard Enter now matches mouse panel activation. First-link focus assumes an area has a destination, consistent with server-generated nonempty areas. Mobile flex layout retains full-width links. No backend authorization or data writes introduced.

## Validation commands and outcomes

- `npx prettier --write resources/js/components/shell/WorkspaceRail.vue` — passed.
- `npx eslint resources/js/components/shell/WorkspaceRail.vue` — passed.
- `node --test tests/Frontend/clean-look-shell.test.mjs tests/Frontend/clean-look-tokens.test.mjs` — 10/10 passed.
- `npm run types:check` — passed (exit 0); log `/tmp/kpone-staff-review-types.log`.
- `PATH=/workspace/kpone-tools/bin:$PATH APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: npm run build` — passed, Vite 1m12s. Existing unresolved font-at-build warnings; URLs remain runtime-resolved. Log `/tmp/kpone-staff-review-build.log`.
- `git diff --check` — passed.
- Inline Playwright regression against rebuilt preview 3017: Enter, Space and ArrowRight each focus Front desk's first submenu link; subsequent Tab remains in that panel; Escape removes unpinned panel and restores Front desk rail focus without reopening — all passed.
- Director mobile HR at 390×568: computed overflowY auto, nav clientHeight440, scrollHeight940; wheel scrollTop500 and last link fully visible — passed. Screenshot `/workspace/kpone-validation/mobile-hr-scroll-390x568.png`.
- `node /workspace/kpone-tools/browser/evidence.mjs` — passed (exit 0): visit Billing and registration Dispensary/Billing query-tab active areas verified, before/after screenshots refreshed, mobile document overflow false. Log `/workspace/kpone-validation/staff-design-evidence.log`.

## Self-review and remaining decisions

Server-side menu destinations remain permission-filtered; this scoped repair does not broaden staff-account management or publishing rights. No new security regression identified in reviewed integration; this is not an exhaustive security audit. Existing approval-gated assertions were untouched. No commits, pushes, merges or deployments performed.

Deferred, nonblocking: pin preference uses browser-global `kpone.shell.pinned`, whereas spec asks per-person preference. Coordinator is assessing duplicate management-menu generation (`ShellMenu::for -> groupsForMenu` plus middleware `hasDestinations`). Keep these as separate scoped decisions rather than silently widening this fix. Desktop focus-out closing still deserves a dedicated interaction pass; repaired keyboard entry and Escape are verified.

Suggested commit title: `fix(shell): make area navigation keyboard accessible and mobile scrollable`

Suggested PR description: Area menus could not be entered reliably with the keyboard, and long mobile menus extended below short screens without scrolling. Focus the selected submenu on keyboard activation and constrain mobile navigation to a scrollable region. Add accessible expanded/control state and resolve the active-link lint errors so Billing and registration-tab highlighting ships in the rebuilt bundle. Validation: focused shell/token tests 10/10, scoped ESLint, production build, keyboard activation/Escape and Director mobile-HR browser checks; TypeScript and active-area browser checks also passed.

## Completed follow-up: per-person pin and focus boundary

Coordinator authorized implementation after reviewing the small proposal. Source files: WorkspaceRail.vue and components/workspace/WorkspaceBranchSwitcher.vue only. No package/dependency changes; no existing assertions changed.

Pin storage is now keyed by authenticated user id (`kpone.shell.pinned:<id>`). Mount/auth-key changes load that person's preference; explicit toggle persists only the current person's key and updates the layout offset. Legacy global key is deliberately unused because ownership cannot be inferred. Reads/writes retain exception handling.

Document focus handlers close unpinned menus after300ms only when focus is outside rail/panel and owned portalled controls. Desktop account menu and branch Select portal carry the same owner marker. BranchSwitcher exposes optional overlayOwner, leaving other callers unchanged. Pointer close also respects active shell keyboard focus. Popup Escape is left to the popup, while shell Escape still restores rail focus. Listeners/timers cleaned up on unmount.

Validation completed serially for heavy gates: scoped ESLint passed after automatic style corrections; Prettier check passed; `npm run types:check` passed (`/tmp/kpone-pin-types.log`); task-env production build passed (`/tmp/kpone-pin-build.log`); existing clean-look-shell tests5/5 passed; git diff --check passed. No additional full suite run.

New repeatable browser validation: `node /workspace/kpone-validation/shell-focus-pin.mjs`; all7 groups passed, log `/workspace/kpone-validation/shell-focus-pin.log`: focus leaves closes, rail/panel focus remains, branch portal focus/Escape, account portal focus/Escape, pin reload/persistence under external focus, second account with copied browser storage does not inherit pin, Escape restores focus without reopening. Initial script attempt expected a main control on Dashboard and timed out; corrected harness navigates Registration before testing. Final run exit0.

Self-review: no authorization/data change; optional popup marker does not affect callers outside shell. Browser isolation check copies the exact first account's localStorage into second account context without cookies, exercising shared-browser preference isolation. Storage-denial catch paths reviewed statically; not fault-injected. Cross-tab live synchronization is not introduced or required. Backend duplicate menu assessment remains coordinator-owned.

Updated proposed commit: `fix(shell): persist pin per person and respect keyboard focus boundaries`

Updated PR addition: Remember menu pinning per staff account and close unpinned panels after focus leaves, while retaining keyboard access through portalled branch/account menus. Validated with type/lint/build, unchanged shell tests, and seven live browser groups including copied-storage account isolation.
