# REG-WORKSPACE-1 — first navigation slice

Authorized by coordinator for owner on 26 Nov 2026. Isolated branch feature/registration-workspace, tree /workspace/KPOne-registration-workspace. Baseline is current origin/main c1e5fc05c plus read-only CA source snapshot (baseline.json); only delta from that snapshot belongs to this slice.

Files: new RegistrationWorkspaceNav.vue, Registration/Index.vue, Patient/Index.vue, new frontend test. Existing routes remain explicit: Today /registration; Patients /patients; QR /registration?tab=qr-intake. Demo Registration:board/patients/qr establishes grouping/order. No Follow-up placeholder, aggregate, invented counts or new business data. Existing registration handlers remain unchanged; status strip stays within Today, QR retains its existing table/errors/actions. Patient search remains POST with masked responses.

Permission visibility uses shared current permissions and branch context; QR additionally uses actual Registration payload availability. Backend routes/policies remain authoritative. No migrations/grants or contact/family changes. New navigation is not full demo workspace completion: inline directory, four-stage modal, allbranch and followup remain separate work.

Risks/gates: navigation away from an existing search resets its page-local state just as existing route navigation does; no caching of patient results. Back/forward and QR active state need browser evidence. Links use aria-current, keyboard focus, wrapping,44px below1024 and36px desktop. Independent Staff design accepts this bounded plan. Existing assertions are untouched; any necessary assertion update will be proposed separately. Run component SSR permission/active route tests, existing Registration tests, types/lint/format/build and actual visual comparison.
