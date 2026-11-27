# Registration Find/New composition

Three-file slice based on #1219 `7efdd0786659564e9c35758d18785977a8c4f03f`. Exact patch SHA256 `4fcdb3351c3e8ca9b3b06a133cf3ea8723e8be5d98c75efa027715a3c3f53cfb`.

The Patient section now exposes the demo's Find/New composition through a labelled group of native pressed buttons. Current mode is a no-op; selected Find cannot clear a patient. New uses the same existing Quick create availability, including short query/error/empty recent cases. Existing inline actions remain. Returning to Find restores recent patients and may legitimately disable New again; the retained draft is available when the same existing conditions permit re-entry. Help text no longer implies a successful network search is required.

The existing full form script is byte-identical after removing only the new availability computed and mode dispatcher (`script-parity.json`). Existing search, change/select/quick handlers, payload, command key, retry/display snapshot and backend are unchanged. Both buttons remain under the existing locked fieldset, and the dispatcher synchronously refuses locked switches. No new permission, schema, clinical rule or submission path.

One existing UI-polish help-text regex adapted after exact delegated Staff review (`PROPOSED-help-test.patch`); its accessible association and both original availability assertions are preserved. Four new actual-SFC tests cover selected/current no-op, exact availability, focus/recent/draft behavior, and processing/uncertain locks.

Validation: 20 focused; full frontend2,241/2,241; types; production build; full ESLint/resources Prettier; scoped checks; diff check pass. Staff independently reran four new tests. Actual browser16 captures at390/1440 in both themes,6proof flags, nooverflow/JS errors,44px mobile/36px desktop mode buttons. Keyboard Enter/Space, actual focus, retained draft through a genuinely eligible return, selected no-op and loading/results lockout verified. Unconfirmed UI is a controlled transport abort before server; no committed replay claimed for this presentation slice. Synthetic visits remain9; one attempted POST was intercepted before server. No PHI persistence or Back recovery claim.

Final independent review is recorded separately. No merge/deployment; this slice does not establish complete Registration/demo parity. Remaining lower patient-field composition and approved Emergency/Family integration are separate dependencies.
