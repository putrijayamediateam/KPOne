# Q-16 design note: zone order, red-zone alert, board details panel

Owner decision (25 Nov 2026, tick box via B.E.R.N.I.S.S): build all three before Monday 30 Nov. Survey of main at 7c7d6174c, revised after Mira's review (three corrections, five gaps). Build order: 1 zone order (own PR), 2 red-zone alert, 3 details panel. Rooms today, average wait and TV "Calls today" follow, read-only, in their own PRs. The TV feed, chime and voice are not touched.

## 1. Zone order (changes Call next and the board)
**What it really changes.** TRI-1a already sets `priority = urgent` on a yellow or red triage and never lowers it (`VisitAdministrationService::raiseToUrgentForTriage`), so today triaged yellow/red and manually urgent patients already go first. Q-16 changes: red above yellow, yellow above a manually urgent green or untriaged patient.

**Today.** Waiting order is written three times: `CallNextService::next` (today first, Urgent, operational date, `queued_at`, id) and `QueueDirectoryService` for today's Waiting and for carry-over (the latter adds operational date).

**New rule, one shared helper** (`QueueWaitingOrder`, `app/Domain/Queue`), used by those three places: after the today-before-carry-over rule, zone rank (red 0, yellow 1, anything else 2), then Urgent, then operational date and arrival, then id. A red carry-over still waits behind today's patients (as today); I will say so in the PR. No triage, or a latest assessment with a NULL zone (no guideline), ranks 2. The zone is a correlated scalar subquery on the latest `visit_triage_assessments` row (unique `(visit_id, sequence)` index, scoped by organisation; no `LATERAL`, tests also run on sqlite). It runs for every Waiting row of the branch and day, hundreds at most.

**Must not change (zone-neutral):** the public QR "patients ahead" and wait estimate (`PublicQueueInsightService`; a pinning test), `QueueWaitData` (explain), `ConsultationSpace`, `QueueWaitingCountService`, the TV feed, OTC and the Registration board.

**Privacy.** The order applies to every viewer; the zone chip stays visible only as TRI-1c allows. The payload never carries a zone for others.

**Tests.** Every zone, Urgent, arrival and carry-over combination; manual-urgent green sorts after yellow; green after a retake stays urgent; Call next picks red over an earlier green and agrees with the board; a triage save mid-poll moves rows across pages while counts stay right; a real two-process race: a triage save against Call next gives the old or the new order and never calls a patient twice.

## 2. Red-zone alert and "I'll take it"
**Derived, not raised.** An alert exists while a Waiting consultation's latest zone is red, the entry has no active not-ready flag, and it is not taken. No hook into `TriageService`. Storage is only the claim: `queue_red_zone_claims` (`queue_entry_id` unique, `claimed_by_user_id`, `claimed_at`; append-only; PostgreSQL refuses update and delete; insert check: entry Waiting, in the claimant's branch). "Taken" means a claim exists and the claimant is still the visit's assigned doctor (a later reassignment or re-triage shows the alert again).

**Audience (owner-confirmed, see Decisions).** TRI-1c shows a zone only to triage recorders (CA, CA Supervisor, Medical Assistant) and the patient's own doctor. The owner's choice is that the branch's doctors see the alert. That is a new, narrow TRI-1c exception: the alert shows only the queue number, minutes waiting and who took it (no name, reading, reason or zone label elsewhere). Default if the owner does not widen it: recorders plus the patient's own doctor only. Never the Director, branch manager, TV or QR.

**Own endpoint, not the board payload.** The board payload is filtered by the viewer's board and search filters and a doctor only sees their own patients, and a doctor in a consultation is not on the board. So: `GET /queue/red-zone-alerts` (the Q-4b `call-next` pattern: `no-store`, 60 a minute, active branch, polled every 10 s while the tab is visible), shown on the Queue board and the consultation page.

**Take it.** `POST /queue/entries/{entry}/take-red-zone`, permission `queue.call.own` (resident doctor only; no new permission). One transaction in the project lock order (`ConsultationLocks`): `ConsultationLocks::users(org, [claimant, old doctor])` first, then patient, visit, queue entry. The old doctor is read before the lock and re-checked after the visit lock. Claimant validated with `VisitDoctorEligibilityService::lockAndValidate` (active, resident doctor, assigned to the branch today). Refused unless the entry is still Waiting, red, not flagged not-ready and unclaimed. A dedicated method on `VisitAdministrationService` modelled on `raiseToUrgentForTriage` (the claimant lacks `visits.update.branch`): inserts the claim, **assigns the patient to the claimant**, bumps the visit `lock_version`, writes audit `visit.doctor.changed` and `queue.red_zone_taken` (ids only), with an idempotency key. The same doctor's retry after success returns success; another doctor gets 409 "Taken by Dr X". A claimant who is already Serving a non-held patient may claim; Call In and Call next then refuse as busy, as for any busy doctor. The old doctor's page goes stale and their Call In is refused safely. Taking never calls the patient. The audit-log label for `queue.red_zone_taken` is added.

**Tests.** Two doctors at once (real two-process, serial suite): one claim, one 409, one assignment; same-doctor retry; CA cannot take; not red, not waiting, not-ready flag, other branch refused; the old doctor's Call In refused; Call next of the claimant sees the patient; payload per role; the claim table refuses update and delete; lock order checked by the existing lock-order test pattern.

## 3. Board details panel
**Read-only.** `GET /queue/entries/{entry}/details`: age (not date of birth) and sex, active allergies (names) with the allergy status, last BP and its date, last visit number and date with the previous-consultation link, who pays (coverage label and member reference, a plain column), the visit reason (the encrypted presenting information is not shown), and the latest triage readings. `no-store`, throttled. HEAD records nothing (coded explicitly). One audit record per open through `ReadOnlyReviewAudit` (event `queue.details_viewed` added to `EVENTS`; `visit` and `patient` ids exist), written before the data is built and outside any transaction; if it fails nothing is returned (pattern: `TriageViewProjection::open`).

**Audience (owner-confirmed, see Decisions).** Last BP is encounter content and allergy names are doctor-only today (`encounters.view.own`, `allergies.view.own`); `patients.view.organisation` plus `queue.view.branch` also covers the Director. `AGENTS.md` requires an explicit role decision for clinical content. Default: the triage-recorder set (CA, CA Supervisor, Medical Assistant, who hold `visits.triage.record.branch`) plus the patient's own doctor, never the Director or branch manager, gated by a role-and-permission pair as TRI-1c does, so no new permission. The triage readings follow TRI-1c with the same set. If the owner wants the Director or other board holders to see it, that is a new clinical grant and needs a new narrow permission, which I would raise with B.E.R.N.I.S.S first.

**Tests.** Keys per role; 403 outside the audience or branch; audit row with ids only and none on HEAD; no values in audit; flat query count; an in-audience viewer's own-doctor scope.

## Decisions (owner-confirmed, 25 Nov 2026, tick box in the coordinator session)
1. Red-zone alert audience: **B, all the branch's resident doctors** (plus recorders and the patient's own doctor). A narrow TRI-1c exception: the alert carries only the queue number, minutes waiting and "taken by"; no name, reading, reason or zone label. Never the Director, branch manager, TV or QR.
2. Details panel audience: **A, CA, CA Supervisor, Medical Assistant and the patient's own doctor.** No new permission. Triage readings in the panel follow the same set.
3. A red carry-over patient waits behind today's patients, as today.

## Not in this note
Rooms today, average wait and TV "Calls today" (read-only, own PRs). TV signed-in line dropped. Bulk selection stays selection-only unless Daniel names a real action by Thursday 12:00.

## Existing assertions
None expected: without triage rows every entry ranks 2, so `CallNextTest`, `QueueDirectoryTest` and `TabCountsTest` keep their order. Re-check `TriageServiceTest` and `TriageChipsAndVitalsTest`; frontend source pins (`q-4-call-next`, `q-4b-held-call-next`, `q-1-waiting-time`, `dd-30-queue-tabs-phone`) may need a line if `Queue/Index.vue` or the consultation page changes. Any change is listed for the owner.
