# Queue workload read slice — implementation contract

Baseline: immutable first Queue candidate v4 (1a0924e754fc5c26d444e6e453f1636dd5ce37492fd132f7433bbba20238c787) on the reviewed CA integration source. Follow-up tree /workspace/KPOne-queue-workload. No migration, permission grant, existing assertion edit, publication or production operation.

GET /queue/workload accepts expected_branch_id, section=held|treatment|checks, page. It authorizes active staff and effective active branch using existing Queue permissions. Each independent query counts the full eligible set, orders by queue entry ID, returns at most25 existing QueueRow projections, and clamps an out-of-range page after membership shrinks. No filtering of previously loaded ordinary pages. Ordinary Queue search/count contracts remain untouched.

Membership:
- Held: registered Serving visit with a current ConsultationHold (resumed_at null) linked to its queue entry and visit. Existing Queue identity scope; no new clinical details.
- Treatment attention: registered Serving visit with any done Q2 step whose closed_at remains null. This is exactly the existing Q2 doneCount meaning; both MA Done and Not successful complete the linked Q2 step. The list does not claim a physical return, acknowledge progress, or Resume. Existing TreatmentStepProjection controls current outcome/reason through TreatmentRoomPolicy; generic Queue readers retain step-only projection.
- Medicine checks: same DSP23/Q13/Q15 eligibility—resident doctor with doctor_check.own and encounters.view.own, both assigned and attending on the case, awaiting_doctor_check plus open handoff, registered visit whose queue is removed/sent_to_dispensary. No medicine snapshots and no link to supervisor-only directory. Existing mapper supplies canOpenEncounter and check flag.

No old assertions need changing for this backend additive endpoint. Frontend tab replacement is separate: existing Q5/Q5b/Q15 label and Completed-placement assertions must be enumerated exactly before modification. This source does not rename tabs yet. Done today cannot be a cosmetic rename of the current all-date removed list; exact completed date semantics require its own query before display.

New tests cover oldest held row beyond ordinary25, own/branch isolation,26 treatment entries with independent second page/clamping after acknowledgement, read without acknowledgement, medicine-check parity and denied roles, endpoint branch and input checks. Focused PG gates run only on isolated kpone_queue_workload_test.
