# Queue task columns and populated Medicine checks — bounded plan

27 November2026. Starting clean reviewed context #1223 at cc73b15203f774c25a48f9a6a5af2eed8b12b015, all six exact-head CI checks green. No application edits yet. Canonical source is the actual wrapper's MyQueue C0, extracted into canonical-columns.json; WfQueue is not the page. Existing later TRI2/privacy/Q4/Hold decisions take precedence over illustrative demo actions.

## Exact data mapping

| Scope/task | Canonical substantive columns (plus existing actions) | Existing approved data and limits |
|---|---|---|
| Own Waiting | No., Patient, Zone, Reason, Waiting | queueNumber/name/coverage; nullable triage distinguishes withheld vs explicitly untriaged; visitReasonExcerpt; waitingMinutes. Current generic table puts zone into Status and uses generic Waiting/since. |
| Own Seeing | No., Patient, Zone, Reason, Since | Same authorized identity/zone/reason; calledAt and existing active duration. No inference that held patients are Seeing; both own and branch workload exclude active holds. |
| Own Treatment updates | No., Patient, Zone, Treatment, Result | Existing treatmentProgress label/state/times and policy-gated outcomeLabel/reasonLabel. Missing outcome must remain unavailable, not fabricated success. Later approved label supersedes Back from treatment; viewing never resumes/acknowledges. |
| Own On hold | No., Patient, Zone, Treatment step, Held | Existing treatmentProgress and heldMinutes; actual cap remains3. Existing Open consultation action, no automatic Resume mutation from the table. |
| Own Medicine checks | No., Patient, Zone, Items, Sent by | Membership/count and canOpenEncounter are available. **Item count and sender are absent from QueueRow**; medicine lines are deliberately excluded. First prove populated authorized list with existing DTO; no invented items/sender. Any later narrow aggregate metadata addition requires separate policy/query review. |
| Own Done today | No., Patient, Zone, Seen for, Completed | Reason and removalReason available; **removedAt is not currently exported**. Do not substitute queuedAt/calledAt for completion time. Date membership already uses exact branch-local removed_at bounds. |
| Branch Waiting/Serving | No., Patient, Doctor, Status, Waiting/Since | Existing fields suffice; current generic table orders Duration before Status. Keep readiness/urgent/triage visibility and action authority unchanged. |
| Branch On hold | No., Patient, Doctor, Treatment step, Held | Existing progress/held fields suffice. Preserve visible readiness and neutral reassignment warning in row metadata. |
| Branch Completed | No., Patient, Doctor, Now at, Completed | removalReason describes consultation routing, not necessarily current downstream state. Label destination truthfully unless a current-state projection is separately supplied; completion timestamp absent as above. |
| Branch Doctors | Doctor, Room, Now, Waiting | Existing reviewed bounded Q16d disclosure. It is not evidence of a complete canonical Doctors-tab table. |

## First bounded work: populated check evidence, no application change

Create a separate synthetic clone of the reviewed context preview. Reuse the existing seeded demo medicine→SKU mapping, Puchong synthetic dispensary location and approved batch; inspect stock before use. Do not fabricate a DispensaryCase status or use the test trait's direct lifecycle writes. Use real Patient/Visit/Queue/Clinical/Allergy/TreatmentPlan/DispensaryHandoff/Dispensary start/update/sendForDoctorCheck services with actor authority and versions.

The existing clone has doctor checks off. In this isolated fixture only, the already-authorized CA Supervisor will enable the existing versioned setting through DispensarySettingsService::setDoctorCheck (no product policy/grant change, no production setting). Prepare one line with a synthetic, explicitly nonclinical instruction and sufficient real synthetic stock; do not Verify or hand over. If existing stock is insufficient, use approved inventory service setup or stop; never force balances. Preserve one-shot guard, database/host/port checks and IDs/versions.

Prove actual own Needs me count1/row, original Open consultation destination, negative other-doctor/CA/Director list membership, lack of medicine lines in queue response, and unchanged case/items/stock/visit/queue/encounter after reading. Capture390dark and1440light. This closes populated-list evidence, not Items/Sent by parity.

## Next implementation proposal after peer review

Recover task-specific Queue presentation with an explicit optional task prop confined to Queue. Keep default Registration and emergency lane contracts intact. Start only with tasks whose fields are already present (Waiting/Seeing/Held/Treatment), preserving shared row mapper/actions and displaying authorized zone separately. Keep readiness, urgent, returned, held and reassignment warnings visible even where the canonical table has no dedicated Status column. No new mutation buttons or direct Resume/Check action. Actual SSR cases must cover each role/task, null/withheld triage, red readiness, outcome redaction, warning preservation, empty colspan and unchanged default Registration. Full type/frontend/build and short-phone action evidence follow.

Completed time and Medicine Items/Sent by need explicit separately reviewed metadata contracts before those exact columns can be claimed. No fake placeholders presented as data, no wider clinical payload, no permission or migration. Staff source/visual review required before implementation freeze; Dispensary peer review requested for the synthetic case path.

Read-only prerequisite refinement: existing Puchong DEMO-PARA500 stock balance is0. The fixture will create a clearly synthetic new batch through InventoryReferenceAdministrationService::createBatch and record one opening unit through InventoryMovementService::openingBalance, using existing approved roles, mapping and location. No balance is forced and no depleted batch is reused. This is disposable synthetic setup only. Staff staged plan review is clear; Dispensary domain-path review pending before execution.

## Narrow first presentation implementation

Prepared isolated branch recovery/queue-waiting-seeing-columns at exact #1223 cc73b152; no source edits yet. First release will cover only ordinary own Waiting/Seeing. Queue Index passes an opt-in task prop only to the ordinary board; emergency board and all other tasks retain the original layout. Target six columns: No., Patient, Zone, Reason, Waiting or Since, Actions.

The existing Status cell contains clinically relevant readiness/urgent/returned/treatment metadata and controls. It must be retained under Patient, not dropped to resemble the demo. Implementation should extract that exact status content into a shared internal renderer (default renders in its original Status cell; own selected tasks render below Patient), optionally suppressing only its triage display when triage is rendered in the dedicated Zone cell. Preserve the existing treatment-dialog state in the parent and forward its open/update and readiness events; no duplicate dialogs. Default Registration remains nine columns, default/emergency Queue remains six. Reassignment remains under Patient in own scope. Null/withheld triage shows no invented No triage state; explicit triaged=false keeps the existing No triage yet label.

Expected files: PatientBoard.vue, a local internal status-renderer component, Queue/Index.vue and new actual SSR task-matrix tests. If source-location assertions depend on the extracted block, prepare exact faithful adapters for independent review before editing them; the behavior must not be weakened. New tests cover six actual headers, explicit and withheld triage, readiness/urgent/returned/treatment/warnings, existing action emissions/dialog wiring, and default Registration/emergency parity. Mobile labels must agree with actual task headers. No backend/permission/migration changes.
