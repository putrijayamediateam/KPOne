# Source-backed Queue content ledger — 27 November

Canonical C0 parsed directly from uploaded MyQueue.dc.html, not reconstructed notes. Current application source is the stack at Doctors candidate, base #1247. This table records content mapping; it is not final main-release approval.

| Canonical view | Current bounded mapping | Evidence / qualification |
|---|---|---|
| Own Waiting: No, Patient, Zone, Reason, Waiting | Same core columns plus existing Actions | #1229; authoritative safety content retained under Patient |
| Own Seeing: No, Patient, Zone, Reason, Since | Same core columns plus Actions | #1229; active holds excluded by existing workload query |
| Own Back from treatment: Treatment, Result | Treatment updates; paired planned step and recorded procedure/outcome | #1234; does not assert physical return or automatic Resume |
| Own Medicine to check: Items, Sent by | Item lines, Sent by | #1242; all exact current handoff review lines, including intentionally not-dispensed; no quantity claim |
| Own On hold: Treatment step, Held | Same core columns with real badge once | #1233; three-held cap and controls retained |
| Own Done: Seen for, Completed | Seen for, Completed (consultation) | #1238; branch-local checkout time, unavailable remains unavailable |
| Branch Waiting / Serving | No, Patient, Doctor, Status, Waiting / Since, Actions | #1244; exact safety renderer, no extra policy |
| Branch Held | No, Patient, Doctor, Treatment step, Held, Actions | #1246; triage and sole dialog preserved |
| Branch Done: Now at, Completed | Historical Sent to, Completed (consultation) | #1247; explicit truthful adaptation, not current downstream progress |
| Branch Doctors | Doctor, Room, Now, Waiting for them | Current optional table; source provenance and waiting subset retained, no action invented |
| Own/all view selector | Current server-selected permitted scope | Still a contract gap; cannot expose branch scope merely to copy demo |
| Red illustrative tab | Approved persistent emergency host + independent red lane | Later owner decisions govern; not a missing tab to add silently |
| Doctor/room/next context and TV link | Existing room provenance, authorized candidate, capability-gated link | #1223; current room is not candidate destination |

Remaining whole-screen work: final latest-stack representative integration capture and role/accessibility comparison; explicit source-backed dual-scope decision if requested; current downstream metadata only under a separate authorized contract. TV composition remains separate. Illustrative clinical mutation buttons do not authorize new actions; actual reviewed service actions and confirmations remain authoritative.
