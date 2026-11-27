# Integrated treatment-call browser proof — 26 November 2026

Live tree `/workspace/KPOne-ca-integration`, preview3036, explicit `APP_ENV=testing`, verified PostgreSQL127.0.0.1:55439/kpone_ca_preview_test before HTTP. Presentation/DemoSetup seeders and dedicated synthetic MA/doctor/supervisor actors; actors provisioned through StaffProvisioningService, visits/queue/encounter/Hold through their real services. Existing actors/cases were preserved.

Actual MA session opened the held patient's row menu and treatment-room dialog, chose the treatment room and submitted the announcement. Browser report:12 checks passed,4 screenshots1440/390 light/dark, no horizontal overflow or JavaScript errors. Screenshots inspected: dialog readable, mobile actions44px, held status visible behind the modal. Supervisor also sees the held treatment action; ordinary doctor without the narrow permission does not. MA has no doctor Call In/Recall action.

Persisted verification: exactly1 treatment announcement by the MA and1 queue.treatment_called audit. Visit/queue/encounter states and versions plus the active hold ID exactly match the pre-click snapshot. No consultation mutation.

Puchong check uses only the synthetic MA's secondary branch assignment created via BranchAssignmentService; Cheras remains primary. Actual mobile selector switches to Puchong, hides Cheras's held patient, and switching back restores it. No patient identity crosses branch in the page. This confirms branch-scoped behavior, not a claim that a full Puchong operational setup is complete.

Root-authorized Family migration2026_11_26_020000 applied additively only by exact --path after Dispensary pause; batch2 Ran. No fresh reset or Treatment-record migration applied to preview. All reserved treatment cases remain intact.

Evidence: browser-report.json, persisted-state-proof.json, puchong-browser-report.json and PNGs alongside this file. Fixtures and browser scripts are external validation artifacts, not application source changes. Server session74558 remains running for coordinator/Dispensary work.
