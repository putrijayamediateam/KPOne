Own On hold now uses No., Patient, Zone, Treatment step, Held and Actions. The Treatment step cell reuses the authorized existing badge, including not-successful outcomes; missing progress is shown as unavailable. Held time retains the existing server-derived duration.

Stacked on #1229. Only the own Held task opts in. Safety metadata, readiness/treatment controls, the single parent-owned dialog and existing Open Consultation action remain. Default Registration, emergency and branch layouts retain their original badge and status placement. No backend, permission, migration or clinical transition changes.

Validation:27 focused tests and full frontend2311 pass, with new actual badge/default-parity/lifecycle coverage. A new regression caught an absent Boolean prop default suppressing original badges; explicit defaulttrue fixes it without changing assertions. The existing Q1 harness only gains the actual helper import. Types/build/lint/format pass. Four actual own/supervisor desktop/mobile states verify placement and44px focused action targets; eight clinical/stock tables remain unchanged. Independent final review is recorded in the release packet.

This is a bounded presentation follow-up. Treatment Result, Done completion/current destination and Medicine Items/Sent by remain separate contracts. No merge or production activation requested; archive #1142 must never be merged.

Parent #1229 CI found one old PHP source-location assertion after the status extraction. Its independently reviewed test-only fix is included through parent efa4eb000; focused1/21 and Pint pass. Fresh parent CI remains pending; this draft does not waive that gate.
