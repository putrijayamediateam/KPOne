# Truthful Registration patient-search state — bounded implementation proposal

Base: frozen #1211 (`f8e45b027a9842728440eff19d818e866927e6d2`). New isolated branch/tree. Own RegistrationCreateForm.vue, new actual-SFC search tests and any exact independently reviewed existing test adaptation. No backend, permission, duplicate policy, mode-switch or shared component edits.

## Behavior

1. Preserve existing Quick create conditions exactly: the nonempty-query/no-listed-rows branch remains controlled by `!searching`; short-query and empty-recent behavior is unchanged. An error does not silently impose a new successful-search prerequisite.
2. Track which current trimmed query has a successfully accepted response. Reset that marker on query changes/start. Only an accepted successful empty response may say 'No matching Patient found.' For a >=3-character query during its debounce, say 'Search pending…'; for a failed query say 'Search unavailable. No results have been confirmed.' Existing actionable error remains visible. Short queries retain 'Enter at least 3 characters.' Actual in-flight search retains spinner/existing availability.
3. Each search has an incrementing generation, captured trimmed query and AbortController identity. Check current generation/query/controller/not-aborted/not-disposed after fetch and after JSON; stale success, HTTP error, parse/network error and finally cleanup cannot overwrite current rows/error/loading. Invalidate on query change and unmount. Clear/cancel the scheduled debounce when explicit Enter starts the same search to avoid duplicate requests.
4. Preserve selected-patient/quick draft, exact registration snapshot, all submit callbacks and post routes. Search request headers/body/CSRF/masking remain unchanged.

## Tests/evidence

New actual SFC/real-useForm controlled-promise tests: clear query restores recent rows and rejects late old success/error; A→B out-of-order result cannot overwrite B; old finally cannot stop B's loading; unmount rejects late completion; explicit Enter cancels queued debounce; short-query/empty-recent/failed-search Quick create affordance stays unchanged; only accepted empty result gets no-match wording. Controlled abort-ignoring promises are qualified defensive tests, not proof of a browser incident.

Actual browser with task-owned synthetic preview: delayed search shows pending/loading without no-match; controlled HTTP500 shows error plus truthful unavailable copy while existing Quick create remains available; recover successful empty/nonempty results; clear while delayed response is pending; out-of-order routed responses (ordinary browser abort may prevent stale delivery, report honestly); both fullpage/dialog as appropriate and390/1440light/dark. No patient/visit creation needed. Existing assertion changes require exact patch review first.
