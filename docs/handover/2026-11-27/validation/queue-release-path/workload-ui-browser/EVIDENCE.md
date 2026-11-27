# Workload UI browser evidence

27 November2026, frozen candidate8ed6fbc5a2da4052489f1ee4d766dc45972451b1289bec66fbc7b34099b78bf5, isolated synthetic preview3157. No real data or production activation.

- results.json:15 successful checks covering own and branch Done today25+1 with disjoint pages, keyboard paging focus, Reset, failed optional read clearing actionable rows while retaining focus, automatic retry, held/Treatment/Seeing separation, empty Medicine checks, no overflow or JavaScript errors.
- filters-results.json:both readers preserve applied query on page2 and Reset to1; observed main Queue POSTs always use valid Waiting status. Each filtered total26/page2one row is sourced from backend responses.
- cards-results.json and four summary captures:actual own first4 cards retain Treatment; full sidebar also retains Done today. Captures at1440light and390dark deliberately scroll the card group below the persistent host. Theme transitions are fast-forwarded for screenshots; no product DOM content is fabricated.
- Pager measurements prove36px desktop and44px mobile controls hit-test below the host;390×640 host bottom256, pager center remains visible. Treatment screenshot is deliberately scrolled to the held/outcome portion of the real patient card; it does not claim the identity/header are visible in that same viewport. Medicine checks capture proves empty state only, not populated medicine-check presentation.
- state-before.json and state-after.json are byte-identical across28 created visits:visit/queue/encounter versions, queue status, active-held state and unacknowledged step count. Thus browsing does not resume, acknowledge, complete or otherwise mutate these clinical records.

One initial browser harness attempted keyboard Enter while the pager remained disabled during main refresh, then injected a background failure and incorrectly expected explicit-action focus. Preserved results-first-harness.json/run-first-harness.log are excluded from passing evidence. Corrected harness waits for an enabled control and injects failure only for the explicitly requested second page. No application code was changed to pass that harness correction.

Synthetic fixture has26 legitimately completed consultations today, one held unsuccessful Treatment (Patient declined), and one Seeing; all use real services and respect the held cap. No cap bypass, invented outcome, ad hoc permission grant or fake count. This is bounded tab integration evidence, not full demo/TV/production release acceptance.
