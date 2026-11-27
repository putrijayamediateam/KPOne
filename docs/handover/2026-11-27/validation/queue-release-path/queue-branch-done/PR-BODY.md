Branch Done now shows the recorded consultation destination under **Sent to**, alongside the branch-local **Completed (consultation)** time. The explanation distinguishes checkout history from current dispensing/payment progress. Completed-context rows use **Consultation checked out**, avoiding an implied current billing state.

Stacked on #1246. Four presentation/test files only; existing API, permissions, actions and clinical state remain unchanged. Exact task/scope/projection guards prevent generic-row relabelling. Patient safety/triage controls remain present; unknown routing/time stays unavailable. This does not implement the demo's current downstream “Now at” concept.

Validation: full frontend2322, types/build,20 actual component/mapper tests, PHP UI contract1/21, lint/format pass. Four actual own/branch desktop/mobile states verify MYT rendering in a New York browser, paging and44px actions below the persistent host; eight synthetic state tables unchanged. Three faithful fixture/layout expectation adapters independently reviewed; unrelated/default assertions retained.

Draft review only; no merge, archive #1142 ancestry, full-demo parity or production activation claim.
