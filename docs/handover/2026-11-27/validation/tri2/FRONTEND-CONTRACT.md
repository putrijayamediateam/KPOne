# Frozen TRI-2a backend contract for shared UI

Source `/workspace/KPOne-tri2`. Backend ownership remains Patients/Registration; frontend ownership Staff Design in separate tree. Apply exported `tri2-backend-overlay.patch` against its CA snapshot, or copy exact source snapshot excluding `.env` and using own generated/vendor files. Never use Git HEAD diff as the incremental feature patch.

## Read and actions

GET `/queue/red-zone-alerts?expected_branch_id=ID` returns `{alerts: Alert[]}` under no-store. Gate: emergency permission OR existing triage-record permission; domain also requires active resident doctor for emergency authority. Recorders retain narrow read only. Each row preserves six old fields `visitNumber`, `queueNumber`, `waitingMinutes`, `takenBy`, `mine`, `canTake`. Adds exactly:

```ts
alertOrigin: 'clinical' | 'activation' | null;
episodeId: number | null;
lockVersion: number | null;
alertStartedAt: string | null; // ISO UTC, activation means observed at, never clinical red-since
seenByMe: boolean | null;
legacy: boolean;
```

No patient identity, clinical readings/reasons, or new presence identity. `takenBy` is existing permitted doctor display name. Server order is legacy first by queued time/ID, then known episodes by alert time/ID. Preserve server ordering. Lane rows use existing board policy-authorized projection, not broadened cross-doctor alert data.

POST `/visits/{visitNumber}/queue/emergency-seen` JSON body `{expected_branch_id,episode_id,lock_version,idempotency_key}`. Returns only `{episodeId,lockVersion,seenByMe:true}`, no-store. Gate `queue.emergency.respond.branch`, active resident doctor only. Same actor/key/content retries resolve without mutation. Keep stable key for unchanged uncertain retry; changed operation/version needs new key. A different key after already Seen refuses. Seen does not silence alarm; subsequent Take uses refreshed version.

POST `/visits/{visitNumber}/queue/take-red-zone` uses Inertia, same four command fields. Preserve optional `{from:'consultation',from_visit:CURRENT_VISIT_NUMBER}`. Success/refusal returns to validated original consultation; otherwise queue. Errors in `queue` (or request validation fields) are displayed. Exact retries append no second claim. Legacy row omits episode/version/key and can still Take before conversion; a legacy request after conversion refuses and must refresh. No Seen on legacy rows, but full9A release follows activation reconciliation so none remain in target scope.

Take assigns only; it never calls in or navigates automatically into another clinical chart. `canTake` false when effective claim remains assigned. Reassignment resumes untaken/alarm state on same episode, regardless of `seenByMe`; red-lower-red gets new episode and Seen resets. Not-ready flags survive; new red not-ready creation is refused. Taken episodes remain in lane until source closes.

## Hold integration

Clinical/Show has `requestHold`, unsaved-work guards, save/discard/hold choices, and `changeHoldState` around lines458–705. Reuse those guards; do not issue silent Hold from shared shell. Hold endpoint POST `/visits/{current}/encounter/hold` body: expected_branch_id, visit_lock_version, queue_lock_version, encounter_lock_version, existing `clinical.hold.holdIdempotencyKey`. PreserveState/Scroll true; versions come from fresh props, and dirty forms must not have content/versions overwritten independently.

For approved confirm-Hold-then-Take: finish successful Hold, then send separate Take. If Take loses/stales, current consultation remains held; show truthful failure and existing Resume path. Do not auto-resume, auto-save, hide unsaved-work guard, or join operations into a backend transaction. Hold failure prevents Take. Shared host should request page-owned guarded Hold through an explicit typed callback/context registration rather than duplicating clinical logic.

## Ownership and release

UI can own shared banner/controller modules + main/focus layout mounts + Queue lane + Clinical bridge only in separate tree. Agree final module paths in exact assertion proposal D; existing test assertions remain unchanged pending owner approval. Five-second reads and ten-second alarm recurrence are separate clocks; Seen never silences, mute device-only <=2min. Failed polling stays visible as paused/outdated state. Keep existing endpoint/privacy/no-Call/error/safe-return assertions.

No production activation. Backend cannot ship alone with legacy client because live clinical hooks create episodes whose Take requires version/key. Compatible client precedes scan; full9A UI follows zero eligible legacy and zero invalid-open health check.

## Separate approved presence overlay

GET also carries top-level `presence:{available:boolean,doctorCount:number|null,windowSeconds:30}` via the separate3-file presence overlay. Existing12 alert row keys unchanged. `available:true` and count0 permits the no-recent-doctor-poll indicator. Unavailable/null means presence unknown, not zero. Count is active authorised resident doctors with same-branch polls within30seconds, not roster count/busy status/clinical availability. No names/IDs returned. See `/workspace/kpone-validation/tri2-presence/README.md`.
