# Clean Look Registration screenshots (PR #1132)

Second pass after Daniel's review items 1, 2 and 4 (item 3 pending approval); PR head 24e0b092d.
Pages: Registration board (/registration), Register Visit (/registration/create), Create patient (/patients/create).
Sizes: 1440 and 390. Themes: light and dark. Accounts: demo.ca and demo.director (synthetic).
Taken on a task-owned PostgreSQL 18 database with the demo seeders (KPOnePresentationSeeder, KPOneDemoSetupSeeder),
frozen clock 2026-11-12 10:00 +08, using scripts/classic-compare/shoot.mjs.
Seven synthetic visits are seeded (waiting, urgent, serving, OTC). The header date "12 Nov 2026" is the browser clock frozen by the harness; "Arrived" shows the server date.
