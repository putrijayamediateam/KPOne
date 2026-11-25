# Q-16 design note: zone order, red-zone alert, board details panel

Owner decision (25 Nov 2026, tick box via B.E.R.N.I.S.S): build all three before Monday 30 Nov. Survey of main at 7c7d6174c. Build order: 1 zone order (own PR), 2 red-zone alert, 3 details panel. Rooms today, average wait and TV "Calls today" follow, read-only, in their own PRs. The TV feed, chime and voice are not touched.

## 1. Zone order (changes Call next and the board)
**Today.** Waiting order is `Urgent, then queued_at, then id`, written out three times: `QueueDirectoryService` (today's Waiting, and carry-over) and `CallNextService::next`. Call In by row is a person's choice and has no order; only Call next and the board change.

**New rule, one shared piece of SQL** (`QueueWaitingOrder`, in `app/Domain/Queue`): zone rank (red 0, yellow 1, everything else 2), then Urgent, then arrival (carry-over keeps its date first, as now). The zone is the latest triage assessment's `chosen_zone` (`visit_triage_assessments`, max `sequence`). **A patient with no triage sorts as green** (rank 2). All three call sites use the one helper. Today-before-carry-over and the not-ready skip are unchanged.

**Privacy.** The order is applied for every viewer, but the zone chip is still shown only to the viewers TRI-1c allows (CA, CA Supervisor, the patient's own doctor). Order alone tells a viewer that someone ahead may be higher priority, nothing more. The zone is never in the payload for others.

**Tests.** Order unit test over all zone, Urgent, arrival and carry-over combinations; Call next picks the red patient over an earlier green; the board and Call next agree; a race test: a triage assessment saved while Call next runs gives either the old or the new order, never a patient called twice (Call In still re-locks and re-checks).

## 2. Red-zone alert and "I'll take it"
**Derived, not raised.** An alert exists while a Waiting consultation's latest triage zone is red and nobody has taken it. No hook into `TriageService`. The only new storage is the claim: `queue_red_zone_claims` (`queue_entry_id` unique, `claimed_by_user_id`, `claimed_at`; append-only; PostgreSQL refuses update and delete; an insert check that the entry is Waiting, red and in the claimant's branch).

**Take it.** `POST /queue/entries/{entry}/take-red-zone`. Permission `queue.call.own` (resident doctors; no new permission). In one transaction: fresh permission and effective branch, row lock on the queue entry then the visit, refuse unless still Waiting and red and unclaimed, insert the claim, **assign the patient to the claiming doctor** (so the claimant can Call In and Call next sees the patient in their list; the visit's assigned doctor changes under the visit lock, `lock_version` bumps, the old and new doctor ids go in the audit row) , idempotency key, audit `queue.red_zone_taken` with ids only. The first press wins: the loser gets 409 "Taken by Dr X" (display name under the staff-visibility rule). Taking does not call the patient; Call In keeps its room, busy and not-ready rules.

**Shown by polling.** The queue payload gains `redZoneAlerts`: queue number, minutes waiting, `takenBy` (name or null) and `mine`. No patient name, reading or reason. Seen by resident doctors with `queue.call.own` at the branch and by CA and CA Supervisor (`queue.view.branch`). Not TV, not QR. The alert clears when the entry leaves Waiting. "Minimise" and "Show alert" are browser state only.

**Tests.** Two doctors pressing at once (real two-process, serial suite): one claim, one 409, the patient assigned once; CA cannot take; not-red or not-waiting refused; branch scope; the payload for each role; the claim table refuses update and delete.

## 3. Board details panel
**Read-only.** `GET /queue/entries/{entry}/details`: age (not date of birth) and sex, active allergies (names only) and the allergy status, last BP and its date from the latest completed encounter, last visit number and date with the existing previous-consultation link, who pays (coverage label and member reference), the visit note, and the latest triage readings. `Cache-Control: no-store`, throttled, HEAD records nothing.

**Gate.** Existing permissions only: `patients.view.organisation` and the board permission for that branch (`queue.view.branch`, or `queue.view.own` for the doctor's own patient), effective branch, entry in that branch. One audit record per open through `ReadOnlyReviewAudit` (new event `queue.details_viewed`, ids `visit` and `patient`, no values), written before the data is built; if the audit fails nothing is returned. No new permission unless Mira finds one is needed (I will tell B.E.R.N.I.S.S first).

**Two points to confirm.**
1. *Triage readings.* TRI-1c's owner decision is that the zone and readings reach only CA, CA Supervisor and the patient's own doctor. The canvas shows triage vitals to every board holder. Default here: the readings follow TRI-1c (others see "Not shown for your role"). If the owner wants them wider, that is the Triage team's decision.
2. *Allergies for the CA.* The CA already sees allergies at Dispensary. The panel reads the active allergy list through a read-only helper gated by the board and patient permissions above, not by `allergies.view.own` (doctor-only). Default: allow; I will say so in the PR.

**Tests.** The key list per role, 403 without the board permission or in another branch, audit row with ids only and none on HEAD, no values in audit, the query count flat, and the triage readings withheld from a viewer who may not see triage.

## Not in this note
Rooms today, average wait and TV "Calls today" (read-only, own PRs). The TV signed-in line is dropped (owner). Bulk selection stays selection-only unless Daniel names a real action by Thursday 12:00.

## Existing assertions
None expected to change (grep at build time): the ordering tests in `CallNextTest`, `QueueDirectoryTest` and the `ReadOnlyReviewAudit` event list are the places to check; any change is listed for the owner.
