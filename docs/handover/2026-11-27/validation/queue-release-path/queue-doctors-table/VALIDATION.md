# Doctors table — bounded Queue presentation

Base: 32bd1444afd151dcb3cc2a4cae4ddc9bcbf6341e (#1247). Three files only; optional table mode in QueueRoomsToday, branch Doctors caller opt-in, five new actual-component tests. Immutable patch f6e95cab7e5cc24c55e21f0b1f082d28ceaf3ab996ef36c1331f9ee0c1dd947c.

Canonical mapping: demo branch Doctors uses Doctor / Room / Now / Waiting for them. This slice uses existing authorized Q16d fields only. Chosen/approved-roster provenance, not-ready subset and held/cap remain explicit. Free is qualified as no active consultation, not roster availability. Unknown state is unavailable. Default cards, query, eligibility, authority, pagination and mutation behavior unchanged. No new doctor actions or TV-feed changes.

Passed: full frontend 2327; focused existing/new Rooms tests 12; backend RoomsTodayTest 3/27; types; production build; scoped ESLint/Prettier; diff check. No backend source change. Independent review recorded separately.

Actual guarded synthetic clone kpone_queue_doctors_table_preview_test: existing 3 +24 staff provisioned using StaffProvisioningService with existing resident_doctor role =27 eligible; real pages25+2. No clinical eligibility/availability claim from synthetic setup. Browser3210 verifies branch four headers,1440 light/390x640 dark, no horizontal overflow, explicit keyboard paging focuses persistent summary hit-testable below persistent emergency host,503 optional-read error retains focus and polling recovers; own scope has no Doctors control. Eight complete clinical/stock tables compare byte-identical after all reads. Escaped/unknown/room provenance combinations covered by actual SSR rather than invented runtime state.

Evidence: browser/results.json, screenshots, state-before/after.json and state-proof.log. The first shell invocation mistakenly redirected state.php stdout into its own internally written state-after.json; it reported unchanged successfully but stdout contaminated that evidence file. Rerun directed stdout to separate state-proof.log; final after file is valid and byte-identical. No application change was made to resolve this harness issue.

This remains a stacked presentation draft on the reviewed Queue chain, not a standalone main merge or full Queue parity. Root owns merge decisions; archive1142 is not a dependency.
