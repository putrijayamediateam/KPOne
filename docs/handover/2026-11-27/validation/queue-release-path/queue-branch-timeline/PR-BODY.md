Branch Waiting and Serving now put **Status before Waiting/Since**, matching the task-specific demo structure while retaining the original flags, triage, readiness and treatment controls. Own tasks, emergency/default Registration and branch Held/Done retain their existing layouts.

Stacked on #1242. Two files only: PatientBoard and actual component/lifecycle tests. Mutually exclusive status locations share the same parent dialog key and readiness event; no endpoint, policy, permission or mutation change.

Validation: full frontend2319, types/build, lint/format,17 actual task tests and existing PHP UI contract1/21 pass. Four actual desktop/mobile branch states verify order and44px hit-testable actions below the persistent host; eight synthetic clinical/stock tables remain unchanged. One exact existing branch-waiting header expectation was updated after independent review; default/emergency assertions retained.

Review draft only; no merge, archive #1142 ancestry, full-demo parity or production activation claim.
