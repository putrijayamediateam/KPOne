# Q16f aggregate — independent Billing/Panel review

Frozen patch: Q16F-CURRENT-MAIN-v1.patch, SHA256 bd1c2fe571362f686295554827fb0627cf1767c3fa77dedb5e68533f003642ab, base9764037af1bfcb24ebad3102a124ff3f6cd7acd2. Independently verified patch hash and all12 source hashes against the manifest. Review scope is the aggregate service, authorized controller selection and backend boundary/cardinality/snapshot tests; this is not independent UI/browser acceptance.

No remaining bounded backend blocker. The initial three-statement aggregate could read totals before a newly committed call and room counts afterward. Author replaced it with one PostgreSQL materialized scoped-call CTE statement. Totals, recalls, room groups and page bounds now share a single statement MVCC snapshot without locks or isolation changes.

The query preserves branch-local operational date, exact branch scope, fixed constrained service groups, recall counts and null/literal 'No room' merging. C-collated row numbering and final output order align. Empty results still return metadata with page1/last1; room pages clamp to the true group count, return at most25 groups, and keep full totals separate. Only at most three service rows, one metadata row and25 room rows leave SQL. The existing manageable-branch controller fallback and403 for no manageable branch remain; rooms_page is validated. No patient or caller identity is projected and no TV feed changes.

Read new tests for midnight/other branch, denied TV role, unauthorized branch fallback, null-room grouping,26→25+1/clamping and exactly one query for1/26 rooms. The deterministic QueryExecuted observer inserts a call after the statement returns: the current DTO remains coherent and the next read includes it. This is an observer-based interleaving proof, not an independently executed two-process race.

Author-reported final gates: backend9 tests/87 assertions PASS, PHPStan0/Pint PASS, full frontend2,217 PASS. These results are attributed to Queue; no redundant heavy rerun performed by this reviewer. Browser/Staff design closure remains separately required.
