# Search-first mode assessment — read-only, no fix claimed

Current published baseline:1211 (`f8e45b027a9842728440eff19d818e866927e6d2`).

The existing query watcher already aborts pending search on changes and restores recent/empty rows. A controlled late-result diagnostic intentionally lets the mocked fetch resolve despite that abort: before completion query is empty, signal aborted=true, results0; afterward the old result appears. This demonstrates a missing post-await identity/abort guard under that controlled condition. It does **not** establish an actual-browser reproducible network race; ordinary Fetch abortion may prevent it. Do not label it a confirmed runtime privacy regression.

Concrete source behavior relevant to the next UI mode contract: Quick create appears when no rows are listed and query is nonempty, even below three characters; it also appears with an empty recent list. The no-result branch does not check patientSearchError, so an error state can coexist with 'No matching Patient found.' Search-first is therefore an existing presentation convention, not a proven successful-search prerequisite. No authority or duplicate policy changes are inferred.

Recommended next review should separate (a) truthful error/no-match presentation and defensive current-response guards, from (b) demo Find/New mode composition. Preserve existing backend/duplicate rules and avoid presenting failed search as verified absence. Exact behavior and source-test proposal should be reviewed before any implementation. All published trees remain clean.
