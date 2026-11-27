# Patient directory workspace composition

The Patients workspace now uses native search-type radio pills and an explicit Clear action, with consistent Registration heading/Patient directory context and responsive search guidance. The existing masked POST search endpoint, five search-type values, passport country, server create/merge gates, saved-patient WhatsApp gate, patient links, errors and pagination remain.

Clear aborts and invalidates pending search/page requests, resets local draft/results and restores search focus. Generation checks prevent late success/error/finally callbacks from overwriting the current state; unmount aborts pending work. Changing radio type remains local until explicit Search. Pagination continues to submit the current form, matching pre-existing behavior. No backend, migration, grant, clinical rule, Emergency Contact or Family change.

Five files: Patient/Index.vue, one new runtime-test file, and three exact reviewed existing presentation-test adaptations (see DELEGATED-DECISIONS.md). Stack base is Registration composition1166 at5fb59c4ba733518f9f7a9ca01d6e878855a02736. No unrelated main refresh or functional feature bundle.

Validation:31 initial focused tests and86 reviewed affected tests pass, including four new actual compiled-SFC race/payload cases. Types, production build, full lint/format pass. Initial full suite exposed four obsolete widget/formatting pins; exact independent-reviewed replacements are applied. Final full suite passes2,216/2,216 with no failures or skips (exit0). Actual browser17captures cover initial/populated/passport/error390/1440light/dark plus doctor omission. Radio arrow semantics, no automatic requests, Enter search, actual masked result, Clear no-request/focus, passport country payload and real422 response all pass with no document overflow/JavaScript errors. Final visual review found no blocker; exact five-file manifest is96b501d4f7525b31e6eecab557dd3d17d42940e743121ad1fe26171cdc567cfc; final independent hash note is pending.

Scope is partial demo composition. No embedded route, last-visit projection, per-patient Register shortcut, Today-at-branch metrics, modal or all-branch authority was invented. Phone/identity remain masked; guidance is truthful prose. Patient directory backend tests were not rerun because services/permissions/routes are unchanged; frontend/browser evidence verifies the changed request lifecycle and view.

Synthetic own preview only; no production. Uncommitted/unpushed pending reviewed publication step.

Publication authorized under standing delegation after exact final review: draft1171 https://github.com/putrijayamediateam/KPOne/pull/1171, headbd00307b1a82c4d675bd149ba5cc05e2dba5aa8a, stacked on1166. Exactfive-file candidate committed/pushed normally; no merge. Earlier unpublished status is superseded. Staff final hash review is CLEAR in INDEPENDENT-REVIEW.md.
