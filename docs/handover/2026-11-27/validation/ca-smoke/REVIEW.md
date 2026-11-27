# Integrated synthetic CA workflow smoke — 26 November 2026

Preview: `/workspace/KPOne-ca-integration`, http://127.0.0.1:3036, isolated `kpone_ca_preview_test` database. No production data, .env, permission grants or application source changes. Dedicated existing-role actors were provisioned through StaffProvisioningService; Puchong consultation room through QueueDisplayAdministrationService/DoctorRoomService. Queue agent's actors and held case were not touched.

Patient `Synthetic CA Endtoend Patient`, `KP-00000012`, visit `KPV-00000002`, case `53c3253a-cb39-4d6a-9ca0-c89dbd94ea10`.

## Actual UI evidence so far

- CA quick-create registration with synthetic passport, DOB and unique phone succeeded; registered consultation appeared Waiting with assigned synthetic Puchong doctor. Initial reused fixture phone correctly triggered duplicate-review protection; unique synthetic phone used instead.
- CA saved pulse80 and temperature36.8 through Triage. Registration's Triage count became0 and doctor queue displayed Triaged.
- Doctor used Call In confirmation, then Open Consultation. Clinical page displayed CA triage readings. Synthetic note saved; no-known-allergies declaration and separate consultation allergy review performed through their actual UI.
- Doctor added seeded Paracetamol500mg tablet, quantity1, dosage1tablet, Once daily; saved Treatment Plan and completed consultation using confirmation. Queue moved visit to Consultation Completed and CA Dispensary showed one To prepare case.
- CA started dispensing. Case accurately refused completion until each line is saved and exposed lack of eligible Puchong Dispensary stock. Inventory team is preparing a normal domain-service synthetic transfer; no direct ledger edits or case bypass.
- Billing before fulfilment appropriately showed no draft-build action. Daily cash closing initially showed RM0 and zero receipts.

Each captured state was checked at390/1440 pixels in light/dark with no horizontal overflow. Images use disabled animations for settled captures. Legacy case-page styles are outside DSP-CL1's Index-only restyle; its dark390 screenshot is readable, fields/action panels contained. This is a selected workflow smoke, not exhaustive clinical or accounting UAT.

## Fixture notes

Presentation/DemoSetup seeders distribute actors/stock across branches. Dedicated Puchong actors avoid altering other reviewers' actors. Existing stock is in Cheras; Puchong transfer is fixture provisioning, not an application defect. Zero PaymentMethod records required a synthetic cash method via PaymentMethodAdministrationService create/publish and PaymentMethodClassificationService with normal Finance role. See actors.php, payment-fixture.php and respective fixture JSON files.

## Harness limitations

`ca-step.mjs` is an exploratory UI harness, not a product test. Some initial waits assumed immediate Inertia redirects and were corrected after observing actual dialogs/queue refresh; these were harness errors, not application findings. Persisted later pages confirm prior successful mutations. Storage-state JSON contains only synthetic local sessions and is not a review-bundle artifact.

## Final outcome

The selected end-to-end flow PASSED through Completed Visit. Inventory supplied exactly1 tablet through normal create/approve/dispatch/receive stock-request services (log `/workspace/inventory-review/ca-stock-provision.log`). CA selected DEMO-B001, saved the line, confirmed completion and was redirected to Billing. CA built/finalized invoice KPI-00000001 for RM30.30 (RM30 consultation + RM0.30 tablet), posted receipt KPR-00000001 for RM30.30 and confirmed Complete Visitation. Registration shows Completed1, Waiting/Dispensary/Awaiting Billing0. Final Billing UI is Completed Visit/read-only with outstanding and due now RM0.00.

CA recorded cash count RM30.30 against expected RM30.30, variance0. A different CA Supervisor sees Approve closing disabled because approval opens only after midnight in branch time. This expected temporal gate was preserved; server time and records were not changed to bypass it. Cash count remains pending approval, so approval itself is not claimed as browser-verified.

Read-only `structural-proof.json` confirms completed visit/case, removed queue, finalized3030sen invoice, posted3030sen receipt, ledger due_now0, one transfer_receipt1.000 followed by one dispense1.000, one cash count and no closing decision. Audit events include visit.created, visit.triage.recorded, queue.called, encounter.started/updated/allergy_reviewed, treatment_plan.sent_to_dispensary, dispensary.completed, inventory.dispensed, billing.draft_built/finalized/payment_recorded/cash_counted/visit_completed. Raw encounter status remains in_progress and TreatmentPlan ready_for_dispensing under the existing handoff model; the visit/checkout/case/queue lifecycle determines completed UI. No status was force-edited.

Final doctor View bill link measured36px high/14px type on desktop, confirming the integration sizing correction. Doctor's completed consultation shows its existing unavailable safety-profile notices; no clinical semantics were altered or asserted from them.

All selected capture states remain free of horizontal overflow at390/1440 in light/dark. Screenshot manifest contains named review checkpoints only; initial exploratory/transition captures are excluded. Final completed Billing screenshots were reloaded after asynchronous sections settled. No app source changes or permission bypasses were needed for this smoke.

Status: REVIEW READY — end-to-end completion/payment passed; daily-close approval remains correctly gated until after branch midnight.
