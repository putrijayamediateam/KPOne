# V2 Clinical/Show shell: department note (Queue & TV)

Status: note for Daniel and B.E.R.N.I.S.S. Nothing is built yet. Clinical content is not part of this step.

## Scope: the shell only
`Clinical/Show` (the consultation page, `/visits/{visit}/encounter`) is the doctor's working page, in focus mode (no top bar, no sidebar content; the V2 shell already supports `focusMode`). Version 2 draws the page chrome and the clinical-record column on V2 blocks. It reuses, **unchanged**, the four Classic Partials as they are today: `TreatmentPlanPanel` (1291 lines), `AllergyProblemPanel` (780), `ClinicalHistoryPanel` (301) and `DispensaryAttentionPanel` (109). Their restyle is a later step with its own review (medicines, allergy review and orders are the clinical-safety core). Same props, routes, permissions, lock versions, idempotency and payloads. No backend change. Classic `Clinical/Show.vue` stays as is.

## What the V2 page draws (new V2 file `Clinical/V2/Show.vue`)
1. Slim toolbar (focus mode, like Classic): back to My Queue, doctor, visit number and status, waited/active/held minutes; document links (MC, referral, attendance: shared Button, "(opens in a new tab)"); waiting-patients dropdown; Call again; Call to treatment room; treatment step; On Hold / Resume; branch switcher and user menu. One filled button on the page (the page's primary action, Save clinical record, only while there are unsaved changes; Complete Consultation stays in the Partial).
2. Patient card (name, age, KP number, queue number, View profile, payment method and Panel note) and the allergy and problems summary, using the existing Partials.
3. Clinical record column: vitals, clinical note with counter, diagnoses (preset picker, primary, remove), Save clinical record, record-drift banner. Same form state and adopt/drift rules as Classic.
4. Right column: Treatment plan / Patient history switch (shared tabs) with the Partials inside.
5. Dialogs on `V2Dialog`: unsaved-work guard (Save and hold / Discard and hold / Cancel), hold, call-next-while-held, treatment-room call.

## How it is kept equal to Classic
The page logic (form, drift/adopt, unsaved-work guard, hold/resume, recall, treatment call, held Call next) is a copy in `composables/clinical/useConsultationV2.ts`, with a parity test (shared handlers equal Classic's by block extraction, same endpoints and payload keys, no Classic dialogs in the V2 page), the same pattern as the Queue board and TV settings. Registered in `config/v2-pages.php`.

## Risks and how I handle them
- Clinical safety: no behaviour change; the Partials are untouched; the allergy-review gate and order rules stay in them. Full Daniel review plus Mira twice, as this is a clinical and queue-control page.
- 23 frontend tests read `Clinical/Show.vue` as text. They keep passing because Classic is untouched; the V2 file gets its own parity tests. No existing assertion changes.
- Toasts: the consultation page keeps the approved bottom-left toast exception (UX-5) in V2.
- Mixed look: the Partials stay Classic-styled inside the V2 page until their own step. I will show this honestly in the shots.

## Proposed build order (one PR, three commits)
1. Composable copy + toolbar + patient card + record column.
2. Right column shell with the Partials inside, plus the V2 dialogs.
3. Parity tests, shots (real V2 shell, seeded data: long name, 30 and 60+ minute waits, held, treatment step done, record drift, empty and error states; 1440 and 390, light and dark, plus 1024 and 1280), V2-CHECKLIST ticked.

## Questions for Daniel
1. Focus mode in V2: keep no sidebar and no top bar (as Classic and as `V2AppShell` supports), with the slim toolbar? I propose yes.
2. Is a V2 page with Classic-styled Partials acceptable for this step, if the Partials are restyled next, or do you want a Partial (the allergy/problem summary) done together?
3. Toolbar on a phone: one scrollable row, or a "More" menu for the document links and room calls?
