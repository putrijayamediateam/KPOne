# Queue workload backend and client preparation

Backend immutable artifact QUEUE-WORKLOAD-BACKEND-v1.patch SHA256 bb75afe6342cb92d897d4d336fcc51e146a2707750c9985bd548bd30302c0759. Five scoped files against captured CA/v4 source; manifest-v1.json records exact baseline and result hashes. Forward CA / reverse isolated tree apply checks pass. No whole HEAD diff exported.

Validation: final5 new tests40 assertions pass; prior combined new+existing Q2/Q13/Q15 run24tests268assertions passes. The new scale test demonstrates constant query count for1 versus25 hydrated rows,26 total and page2/clamping. Actual MA not-successful outcome is included while consultation stays held; Director receives existing step-only data with no clinical outcome. Scoped PHPStan0errors; PHP formatting and whitespace pass. Billing and Staff independently reviewed bounded membership/source and reported no blocker; no independent full test rerun claimed.

Separate client-preparation artifact QUEUE-WORKLOAD-CLIENT-PREP-v1.patch SHA256 87189ecf3bddfe140a1adff3c2c3f43def46a42d0f6e83b49754ef633a2eea36. Two new files,3 actual composable tests pass, lint/format/scopedTypeScript pass. It clears/aborts selection changes, rejects stale scope/generation, preserves server-clamped pages and clears failed actionable rows. It is not wired into Queue yet; no production build/browser claim for this follow-up.

No migration, grant, old assertion changes, commit or push. Test DB isolated kpone_queue_workload_test. Existing v4 preview remains separate and unchanged. UI-INTEGRATION-PROPOSAL.md records truthful label/data dependencies and the exact affected old test groups before any modification. PLAN.md is the backend contract.
