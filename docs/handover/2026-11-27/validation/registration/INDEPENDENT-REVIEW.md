# Independent Staff Experience review — Registration

Reviewer: staff_design under B.E.R.N.I.S.S, 2026-11-26. Findings only: no source edits. Reviewed diff of four Registration Vue pages against base, approval-scoped assertion changes, team review pack, and six representative desktop/mobile light/dark screenshots. Coordinated findings with patients_registration.

## Result

No new functional blocker identified in the scoped restyle. The visible Registration h1, branch/date subline and dedicated primary action improve hierarchy. Changes stay in presentation markup/classes; routes, payloads, permissions and form handlers remain intact. Existing accessible labels and error associations survive the diff. Semantic success tokens replace hard-coded selected-patient/queue colours.

This is not full design acceptance. Screenshots use main shell, so Stage0 integration still needs a combined smoke check. Empty-board screenshots do not prove populated rows, long patient names or row actions. Team is adding Quick create and error-state captures. Its interrupted TypeScript run remains an unpassed gate until a coordinated successful retry.

## Scoped follow-ups

1. Show retains two filled actions (Edit and Send to Waiting). Inherited, not introduced by this patch; to satisfy one-primary-action spec, propose Edit as outline while Send to Waiting remains primary when available. Review the existing action condition before implementation.
2. Mobile Select controls in captured Index/Edit remain approximately36px while raw Create fields were changed to44px. Shared control consistency requires integration review; avoid page-specific overrides without assessing the shared component.
3. Index desktop dark screenshot shows light-grey filter fills and grey labels, unlike dark Edit. Asked owning team to check theme-transition timing and recapture after transitions settle; do not infer a source regression solely from this image.

## Validation boundary

Independent source and visual review only; no duplicate heavyweight suite launched. Owning team's exact results are in REVIEW.md. Original five assertion groups were owner approved; this review requests no new assertion changes. No emergency-contact schema or new mandatory data requirement is part of this restyle.

Update: owning team supplied28 refreshed screenshots after600ms theme settling. Independently viewed index-populated-1440-dark: filter fills/text are correct and the earlier grey was a transition artifact; that observation is resolved. Team reports populated/error/Quick create states captured. Root then authorized targeted action/touch improvements; advised conditional Edit outline and mobile44px Select triggers. Owning team reports25 focused tests pass unchanged, with country-code40px control explicitly deferred because its exact-class assertion requires separate approval. Build/screenshot refresh for those later edits remains outstanding; this note does not substitute for that gate.

Combined snapshot review: /workspace/KPOne-registration-preview, port3023, frozen Stage0 plus final Registration restyle. Independently viewed populated1440 dark, selected Create390 light and Show390 dark captures. Shell/content spacing, visible header, semantic selected-patient card,44px doctor select and conditional single filled Show action look correct; no visual blocker in these reviewed states. Builder reports28 states with no JS error/overflow. This is bounded independent evidence, not exhaustive device/screen-reader signoff.
