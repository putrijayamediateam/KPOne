# Next bounded gap: Visit Edit change summary

Read actual `VisitEdit.dc.html` lines176–192: a list of changed visit fields and no-change state, separate from its simulation save/modal. Current `resources/js/pages/Registration/Edit.vue` has real editable visit type, doctor, ordered structured reasons (legacy free text stays immutable), coverage/panel/member reference/note and priority, but no preview of changes.

Proposed first slice: a read-only local summary, using existing loaded visit/options and current form values, no new control, undo, mandatory confirmation, dialog, route, payload or backend logic. Show changed-field labels and truthful resolved current value; no invented assignment/panel eligibility/clinical state. Legacy reasons must not appear changed or become submitted. Optional panel note describes added/changed/cleared without pretending a save. Preserve stale/refusal errors and existing submit transform/watchers byte-for-byte; summary cannot reset key/version/dirty state.

Need precise peer contract before implementation: compare only actual submission-relevant fields, ordered reason IDs, selected doctor/panel ID label lookup (fallback unavailable), inactive panel fields follow existing payload rather than assumptions; no false no-change state after field edits. Existing full-page route remains. Scope should stack on reviewed Registration foundation #1132 rather than unrelated Patient/settings work. Modal and demo synthetic queue numbers remain excluded.

Plan not implemented. Next inspect current foundation head and actual update service to resolve inactive panel fields before final contract. Standing delegation permits bounded read-only composition after peer review; no new clinical rule.
