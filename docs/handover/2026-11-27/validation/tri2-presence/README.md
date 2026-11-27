# TRI-2 recent doctor poll presence — 26 Nov 2026

Coordinator explicitly authorised the recovered design's cache-only presence contract (design lines146–148), separate from the frozen lifecycle backend. No new permission, migration, clinical field or existing assertion change.

Existing GET alert poll now additionally returns:

```json
{"presence":{"available":true,"doctorCount":0,"windowSeconds":30}}
```

The 12 per-alert keys remain unchanged. Only an active resident doctor with existing emergency authority polling the current active branch registers a timestamp. Triage-recorders read the count without registering themselves. Every cached candidate is checked again for current active user, organisation, role, permission and effective branch access. No names or IDs are returned. This means recently polled, not currently available for clinical work; the count is never inferred from a roster or alert list.

One cache map per organisation/branch, <=512 entries, expired/future/malformed timestamps removed, timestamps older than30seconds excluded, map TTL60seconds. A short nonblocking cache lock serializes writes. Cache failure, contention, invalid state or capacity overflow returns `available:false,doctorCount:null`; UI must show unavailable rather than no doctors. No presence table or audit-history dataset is introduced; the configured application cache is used.

Apply `tri2-presence-overlay.patch` AFTER the frozen lifecycle overlay SHA `d8725e7ed0b3aec9399202e665b361cd61dfae29374549d5c3602f576899581e`. Three source files only. This does not alter the frozen lifecycle artifact. Presence overlay manifest records its own base/source hashes.

Initial new tests:6 passed,37 assertions (distinct counts, recorder/roster exclusion, expiry, cross-branch denial, revocation/inactivation, cache failure/lock contention, cleanup/capacity). Final combined gate and independent reviewer outcome are recorded alongside this note. No production activation, UI implementation or deployment performed.

Final gates: presence plus unchanged existing Q16b tests **17 passed /103 assertions**; scoped PHPStan0, Pint, diff whitespace and overlay apply-check passed. Frozen presence patch SHA256 `dcc91701131f58f0718ec60279ca00f7cd35ad3b683ffeca1b7a895e16f11a80`. Independent review requested; no independent runtime rerun is claimed.
