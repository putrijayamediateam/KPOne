# Q-13 design note: "Medicine ready for your check" on the Queue board

Owner decision: DSP-23 (the doctor checks prepared medicine before payment, per-branch switch, default off). Daniel's note 6 asks for a row reading "Medicine ready for your check". B.E.R.N.I.S.S confirmed Q-13 is mine (21 Nov). Build waits for DSP-23 on main.

## Today (survey of main at 5d5e8864e)
- `QueueDirectoryService` builds `$removedHints = pendingForVisits(...) + CheckoutReopenEligibility::forVisits(...)`, both `array<visitId,bool>`, only for the Removed tab rows; `row(..., bool $hint)` turns the hint into the open-consultation link.
- A visit sent to the dispensary is a Removed queue entry (`removal_reason = sent_to_dispensary`), so it shows on the Removed tab.
- Polling rule in `DoctorDispensaryAttentionService`: structural ids only, no medicine content.

## Proposed
1. **Backend, read only.** Aisyah supplies `DoctorDispensaryAttentionService::checksForVisits(User $actor, Collection $visits): array<int,bool>`. Rules (hers): resident doctor, own assigned visit, active branch, case status `awaiting_doctor_check`, per-branch switch on. Switch off returns `[]`. No medicine text, no counts.
2. **Queue row field.** `QueueDirectoryService` calls it once per page for the Removed rows (one query, no N+1) and adds `dispensaryCheckWaiting: bool` to the row (false by default; only ever true for the doctor's own row). The existing open-consultation hint is unchanged.
3. **Chip.** `PatientBoard.vue` shows a warning-token chip "Medicine ready for your check" in the Status column of that row (same tokens as the DD-38 chips: warning fill, border, 4.5:1 or better in light and dark). The row's existing open-consultation action stays the way in. Not shown to CA, CA Supervisor, Director or TV. No sound, no toast.
4. **Not in this slice.** No new permission, migration, route, poll, push, or change to queue order, counts or the TV and QR pages. The Registration board is NOT fed by this row builder (it uses VisitDirectoryService), so it is unchanged.

## Changes after Mira's review (21 Nov)
- The new array is its own (`$checkWaiting = checksForVisits(...)` after the existing hints); it is NOT merged into `$removedHints` with `+`, because that array only drives `canOpenEncounter`. New rows()/row() parameter, field emitted next to `callHold`.
- `canOpenEncounter` is also set true for a check-waiting row (OR), so the doctor always has the way in. Decision to confirm with Aisyah, since her file decides who may open it.
- Permission: the owner approved P3 (21 Nov), the doctor's own-patient check permission `dispensary.doctor_check.own` (Resident Doctor, Aisyah's), so no new permission is needed. The per-branch switch is read once per page, not per row; filtering to the actor happens in SQL.
- Chip: the wrapping `w-full` warning-token block (like the not-ready panel), not a nowrap chip, because the label wraps in the narrow Status column and at 390 px; has a `data-testid`. Touch set: `resources/js/types/patientBoard.ts` and `PatientBoard.vue`; keep the pinned strings (`openConsultation: row.canOpenEncounter,` in Queue/Index.vue, `returnedFromDispensary`, callHold) intact.
- Tests: no existing test pins the full queue row key list (re-grep at build time); add Director and CA cases with a case awaiting the check (must be false), and a query-count test on a 50-row removed page.

## Tests
- Row has `dispensaryCheckWaiting` true only for the assigned doctor's own removed row with a case awaiting the check; false for another doctor, for CA, with the switch off, and for other case statuses.
- Query count on a 50-row removed page does not grow with row count (batched).
- Frontend: chip text and token classes pinned; no chip when false.
- No existing assertion is expected to change (none pins the full row key list); re-grep at build time and list any for the owner.

## Open questions
- Aisyah: exact status string and the switch lookup (I only call her helper).
- Daniel: chip position in the Status column, and whether the chip text wraps at 390 px.
