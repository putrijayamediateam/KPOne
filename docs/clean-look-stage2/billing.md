# Billing — CL2 Clean Look billing pages

You are Farah (Lead), Kumar (Reviewer), Siti (designer), KPOne Billing. Coordinator: B.E.R.N.I.S.S (session_01MHFmnXJkhBcSBRZ7VAPGDH).

SLICE CL2-BILLING: area pages in the demo: Billing, PatientBilling, InvoiceView, FamilyBill, ManagePayments, CashClosing, TerminalCheck, BillsToCollect, VisitHistory.
KPOne files (start here, confirm by reading the code): resources/js/pages/Billing, PaymentReconciliations, FinancialWork (only the billing parts). Money logic is untouchable: look only.
Your team file: docs/teams/billing/TEAM.md on docs/billing-handoff-2026-11-27 (ace0c99). main is 9d663157b.

CLEAN LOOK STAGE 2 (owner decision, 27 Nov 2026): on Monday 30 Nov 08:30 MYT the owner's boss tests EVERY role's account, and wants every page to look like the demo. Make your area's pages match the demo as closely as you can before Monday. Many teams are working at the same time, so stay strictly inside your area.

The demo is https://claude.ai/artifact/KUkn7hc8xW9k2REtRUCFbG. Its page files are under project/<Name>.dc.html, listed below for your area. Read them with the Artifact tool (action "read", with `path`). The spec is docs/design/CLEAN-LOOK-SPEC.md on main. Stage 1 examples already on main: #1132 Registration, #1135 homes, #1139 Dispensary. Copy how they used the shared tokens and components.

Hard rules:
- Restyle in place: the same Vue pages, routes, props and server calls, with no second version and no feature flag. Layout, spacing, tokens and component choice only.
- Do NOT change: PHP, routes, controllers, policies, permissions, migrations, validation, money logic, server payloads, or any existing test assertion. If you believe one must change, stop and ask the coordinator; the owner decides by tick box.
- Do NOT edit shared files: AppShell/layout, PageHeader, the shared components under resources/js/components/ui, app.css tokens. If your page needs a shared change, ask the coordinator first, because another team may need the same thing.
- If the demo shows a feature KPOne does not have, do not build it. List it in your report as "demo-only".
- Wording stays as it is unless the demo only changes case or punctuation. Staff screens are English; patient-facing pages are Malay.
- Keep it working at 390 px and in dark mode.

Process:
- Branch feature/cl2-<area> from the newest main. Run the relevant frontend tests while you work. Before the PR, run the frontend gate: npm run types:check, lint:check, format:check and build, and node --test tests/Frontend/*.mjs. Frontend tests that call PHP need a php shim to kphp first on PATH. Run php artisan test --filter for any page whose PHP tests touch your props.
- Screenshots: populated synthetic data (create .test records through the app, services or factories; the seeders make few visits), 1440 light, 1440 dark and 390 light, for each page. Push them to validation/cl2-<area>-shots. Your designer persona compares each page with the demo, and Daniel spot-checks through the coordinator.
- Your Reviewer persona reads the diff and checks the hard rules above.
- Then report to the coordinator: branch, SHA, pages done, pages not done, demo-only items, and tests run. The coordinator tells you when to open the PR. Open it as ready (not draft), then call subscribe_pr_activity on it. The coordinator auto-merges PRs that meet the owner's standing approval (look only; no assertion, permission, migration or money change; Reviewer OK; designer OK). main has a merge queue, so do not merge main into your branch again and again; only do it when the coordinator asks.
- Ship in pieces: if the area is big, open the first PR with the most-used pages (the role's home and main page), then continue with the next PR in the same session.

Session rules: no polling; no third-party tools; never merge, never push to main, no force-push. You may commit and push to your own feature/, validation/ and docs/ branches. If your permission guard blocks a push, tell the coordinator the exact text and keep the work. Never read, print or use the real .env, and never connect to kpjaya.com or a real database. Use a task-owned Postgres 18 container, and check the DB host, port and name before the first HTTP request. Keep messages to the coordinator short, via send_message to session_01MHFmnXJkhBcSBRZ7VAPGDH. English in code, commits and docs. When your last PR merges, write your TEAM.md notes on a docs branch, push them and tell the coordinator.

Read first: your team file (branch named below); docs/HANDOFF-2026-11-25.md on branch claude/relaxed-edison-t8klk2; CLAUDE.md; AGENTS.md; docs/design/CLEAN-LOOK-SPEC.md.
