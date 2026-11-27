# Branch Waiting/Serving timeline columns

Frozen two-file8032307fa8e6cc78361f74ecf33c9366213a92a367f1f7a5062ce447e93fe73b on Medicine #1242. Explicit branch waiting|seeing task condition moves the unchanged PatientBoardStatus renderer before existing timing and uses Waiting/Since labels. Default Registration, emergency without task, branch Held/Done and own tasks keep their layouts. No endpoint, policy, permission, data or command change.

Status locations are mutually exclusive and bind the same sole parent treatmentDialogKey/readiness event, with original triage slot, flags, warnings and controls. New compiled lifecycle test proves one active treatment/readiness instance across default/seeing/held/waiting transitions, preserves open/close state, emits once and cleans up on unmount. New actual SSR proves order, one status/triage, returned warning and unchanged default/Held/Done headers. Existing explicit branch-waiting generic timing assertion was faithfully replaced by exact six-header expectation after independent approval; earlier default Registration/emergency assertions remain.

Full frontend2319, types/build, scoped lint/format/diff checks and17 actual task tests pass. Existing PHP UI contract test1/21 also passes. No heavy backend suite rerun for presentation-only change.

Actual preview3204, same isolated synthetic task DB read-only: four branch Waiting/Serving390dark/1440light states show correct DOM order and existing44px focused hit-testable action below persistent emergency host. Menus opened/closed, no mutations invoked. Eight whole clinical/stock tables byte-identical before/after. Captures scroll relevant card/action below host and do not establish full-page parity.

Separate stacked review draft, not archive integration/merge approval. Next branch Held remains a separately reviewed proposal.
