# Next Queue screen context slice — source-backed plan

27 November2026, after workload UI draft1220@efe71415580ac71b15507522dd62308981c4c884. Canonical source: actual full-wrapper MyQueue runtime in queue-demo-makeover/canonical-runtime.json and extracted MyQueue.dc.html. This is source preparation, not implementation or full parity acceptance.

## Small next implementable gap

The canonical Waiting workspace visibly explains the assigned room and next patient, and provides a Waiting-room TV link where authorized. Existing KPOne DTOs already support a narrow read-only context treatment:
- QueueController passes DoctorRoomService::choice, including currentRoomId/currentRoomSource(chosen|roster|null), rooms and operationalDate. Index currently omits currentRoomSource from its local type and cannot describe provenance. Render only provided source, never infer roster status from a room name.
- QueueSnapshot.callNext already carries server-authorized state, reason, doctorName and ready candidate(queueNumber/patientName/roomName/version). A separate noninteractive next-context line can use this same block; never choose the next row from ordinary or emergency loaded pages.
- Shared nav.queueDisplayScreen and existing /queue-display route already authorize the related link. Link only under that existing shared capability. No TV feed, name mode or settings edits.

Preserve the locked Q4 steady Call next button label (resources/js/lib/call-next.ts explicitly forbids candidate number in its label), named confirmation, stale-candidate recheck/pause, versions and refusal flow. Do not copy the demo's literal patient-bearing action label over that safety contract or duplicate interactive Call next controls. Room mutation selector and its payload remain unchanged. Hide candidate context unless state ready and candidate exists; render backend refusal reason otherwise without inventing availability.

Proposed scope: one small Queue-only context component plus QueueIndex wiring/type and actual SSR/setup tests. No backend/permission/migration changes. Header self-name or a My patients/All doctors switch are deferred until capability semantics and responsive scope contract are reviewed; own-only access must not gain branch identity scope. Per-tab column differences and populated Medicine-check visual evidence remain separate acceptance work.

Required review: Staff plan/source/final; actual current room-source null/chosen/roster and ready/nonready states, escaped names, capability link hiding, existing Call-next confirmation/Cancel/retry tests untouched, short-phone layout and true control hit targets beneath persistent host. No live names or simulated counts; synthetic fixtures through existing services only.
