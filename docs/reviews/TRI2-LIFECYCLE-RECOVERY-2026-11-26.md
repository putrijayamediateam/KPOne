# TRI2 lifecycle backend scoped recovery

This draft follows Treatment-record UI #1180 and keeps Family #1148 as an explicit release-order prerequisite. Archive #1142 must never be merged. Only the previously reviewed lifecycle backend, bounded alarm ranking and exact historical fixture/triage-guard adaptations are recovered here; emergency lane, shared host/presence and Hold bridge UI remain separate dependent slices. This backend draft is not an instruction to deploy it alone with an incompatible client.

## Preserved owner decisions

The exact migration is SHA256 `dfff7e9cd1926c5f2060f7356cec4d4980a86db5717654510aad03e9ea979ab6`. It adds immutable episode/event evidence and the already-approved resident-doctor-only `queue.emergency.respond.branch` authority. It performs no activation of existing alerts. Staff-account management authority is unchanged.

Seen does not silence the alarm; an effective Take does. Take preserves Waiting, queue position and existing not-ready evidence and never calls the patient. New not-ready flags are refused for red emergency patients. Reassignment restores effective untaken responsibility, and a fresh episode has a claim floor so earlier claims cannot silence it. Red Waiting carry-over belongs in the later independently scoped/paginated emergency lane; it must not be silently omitted from the final release. Existing source transactions close episodes atomically on leaving Waiting, cancellation or lowered triage.

The bounded25-alert response ranks effective untaken rows before taken rows, then preserves legacy/age/ID ordering. It cannot lose an untaken alarm behind25 older taken rows. Cross-doctor banner data stays restricted; no patient identity is inferred from emergency response permission. Full-set state, authority, version, idempotency and actor/visit/queue locks remain in domain services.

The later shared client must preserve the approved ten-second alarm, device mute maximum120seconds, explicit Seen/Take, and draft-safe Cancel/Hold-then-Take behavior. These frontend behaviors are not implemented or changed by this backend-only patch.

## Activation and assertions

The explicit activation command defaults to dry-run with required fixed organisation/branch IDs. Only synthetic execution is authorized here. Compatible source writers and Take client precede a controlled scan; full rollout follows zero eligible legacy/invalid-open reconciliation. Production activation is not performed or implied.

Existing backend assertion groups A–C and the exact plain-TRUNCATE SQLSTATE0A000 / CASCADE P0001 append-only guard were already owner-approved. Their historical patch names may retain PROPOSED; these are not new permission requests. The historical pre-STK3 inventory rollback fixture suppresses only the later close hook with an exactly-once typed expectation; no rollback assertion or runtime service is weakened.

Shared QueueController and routes are reconciled by hunk. The new real PostgreSQL race is registered in Contention and Contention3 and excluded from Feature; parent Treatment and all other registrations remain. Existing Family, Treatment, Q16d/e/f and AUTO content is neither copied wholesale nor silently removed. Final immutable manifest and focused/runtime evidence accompany this scoped recovery; no full-demo or aggregate-main readiness claim.

The recovered NEW bounded-alarm regression originally queried the later EmergencyLaneProjection. In this lifecycle-only slice it directly asserts26 still-open Waiting episodes after25 Takes, plus bounded25 alerts containing the remaining untaken emergency. The exact lane-total assertion is retained as `TRI2-LANE-ALARM-ASSERTION-FOLLOWUP.patch` for the separate lane extraction; no existing main assertion is removed. This keeps each dependency slice runnable without silently importing the lane.
