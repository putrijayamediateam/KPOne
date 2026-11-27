# Independent bounded lane UI review

Current disposition: source clear; final mobile visual closure pending settled replacement captures.

Exact patch `TRI2-LANE-UI-ON-SHARED-v1.patch` SHA-256 `1ee88356ed7646d643d247c6f18aa3574dbb7c67bdbcd3d673ac8d940135aa82`; independently verified patch and all 17 manifest file hashes.

Independently ran all 10 lane runtime tests successfully. These exercise original mapper permissions/versions, independent paging and applied filters, manual failure removal versus automatic last-known state, and synchronous focus on persistent named parent regions before controls can disappear. Source retains existing row action handlers and uses server-projected board rows. Waiting duration remains distinct from observed/recorded alert time and taken metadata. Shared PatientBoard change is conditional metadata only.

The small readiness projection correction excludes latest-red rows from canSet, matching the existing mutation policy, without expanding grants. Builder reports separate Billing source clearance and 21 backend tests/424 assertions; those backend gates were not rerun here.

Inspected Queue desktop light: lane is distinct, branch-time alert metadata readable, page 2/2 and prior-page control present, existing ordinary rows retained separately. Inspected Registration mobile dark: current capture is scrolled beneath the fixed shared alert, with lane heading/identity outside the viewport and pagers transiently disabled. Requested settled mobile card/header and pager captures; this is an evidence limitation, not a confirmed source defect.

Builder reports 18 browser checks, independent 25+1 paging, own scope, manual-error focus/retry, and actual filtered HTTP responses with zero ordinary rows while 25 remaining emergencies remain after one explicit Call. Manual-call evidence records Cancel without request and exactly one normal Call command. Full 2,257 frontend/type/build gates are builder evidence. No full-demo or production acceptance claim.

## Final settled mobile closure

Final disposition: CLEAR exact `1ee88356ed7646d643d247c6f18aa3574dbb7c67bdbcd3d673ac8d940135aa82` for this bounded extraction. The evidence gap above is closed, not a remaining blocker.

Independently inspected replacement Registration 390 dark settled header/card and Queue 390 dark settled pager. Lane heading, patient identity, waiting versus alert metadata and normal actions remain readable beneath the fixed shared host. Page 2/2 shows an enabled Previous and correctly disabled Next. `settled-mobile.json` records both parents' deliberate scroll offsets and target placement approximately 12px below the host, rather than claiming an unscrolled whole-page view. The fixed alert consumes significant mobile height; these captures demonstrate access by scrolling, not a full-page demo comparison.

Builder added one new synthetic visit through existing domain services after the earlier explicit Call reduced total 26 to 25, restoring 26 solely for final pager captures. No prior record was reset or reversed. Earlier call/filter invariant evidence is stage-specific and remains labelled accordingly. Frozen source is unchanged; no new build or full-suite result is claimed for this capture-only follow-up.
