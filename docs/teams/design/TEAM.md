# Design team (UI/UX)

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: the design rules, what the owner
decided, and what to do next. Every builder team's designer reads it before designing a screen.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead UI/UX Designer | **Daniel** | Owns the KPOne design guide (`docs/design/GUIDE.md`), approves the look of new or changed screens, coaches the team designers |
| Team designers | one per builder team (below) | Sketch each screen from the guide before the Lead builds it, then check the screenshots before hand-off |

Team designers (each works inside their own builder team and follows Daniel's guide):

| Builder team | Designer |
|---|---|
| Dispensary & OTC | Iman |
| Inventory | Wei Jie |
| Billing | Siti |
| Panel | Arjun |
| Patients & Registration | Balqis |
| Agent platform | Ezra |
| Queue & TV | Yasmin |
| Catalogue & settings | Nadia (team set up 2026-11-05: Adlina, Lead; Ravi, Reviewer) |
| HR | Hui Min (team set up 2026-11-05: Liyana, Lead; Suresh, Reviewer; KPOne HR) |
| Staff Experience | Kavitha's team is itself the UX team; it builds the shared shell to Daniel's guide |

All are Claude builder sessions or roles working for the owner through the coordinator session, B.E.R.N.I.S.S (formerly LUCY). Daniel is
the "Daniel, UI Designer" shown in the agent office. The Patients & Registration reviewer formerly called Daniel is
now **Hafiz** (renamed 2026-11-05 to avoid two Daniels).

## 2. What this team covers

- `docs/design/GUIDE.md`: colours and tokens (light and dark), type scale, spacing, layout grid, page header
  pattern, tables and their phone card layout, forms and errors, dialogs and confirmations, toasts, empty and
  loading states, status badges, icons, focus and accessible names, print rules, wording style (plain English or
  Bahasa Melayu as each screen's rule says), and the phone rules (320, 390, 768 px, no sideways scroll).
- Written from the real screens and components in `resources/js` (Tailwind 4, the `components/ui/*` set, the
  workspace shell). It describes what KPOne already does well and names the patterns to stop using.
- Review: Daniel checks the screenshots of any PR that adds a new page or changes a page layout, and replies to
  LUCY with OK or the changes needed. Small fixes inside an existing pattern need only the team designer.

**Not in scope:** backend, permissions, data, the waiting-room TV look (already owner-approved), the public website
design profile (owner-approved soft pink, white and black), and invoice, receipt and label print layouts (owner and
pharmacist decisions).

## 3. Where the code lives

`resources/js/components/ui/*`, `resources/js/components/workspace/*`, `resources/js/layouts/*`,
`resources/css/app.css` (Tailwind tokens), the pages under `resources/js/pages/*`. The guide lives in `docs/design/`.

## 4. Owner decisions already made (never ask again)

1. Every builder team gets a UI/UX designer, all under a Lead UI/UX Designer (owner, 2026-11-05).
2. Registration and Patient screens use English labels; "Panggil semula" stays (2026-11-05).
3. The TV screen is English with the owner-approved theme; the public website uses the fixed soft pink, white and
   black profile (2026-10-26).
4. Prints stay white in dark mode (PRINT-1).
5. A "ship it" or decision relayed by B.E.R.N.I.S.S (formerly LUCY) counts as the owner's own (owner rule 2026-11-05).
6. **Backup push** (owner rule for all teams, 2026-11-05, relayed by B.E.R.N.I.S.S): whenever the team stops to wait for the
   owner (a "ship it", a question, or the end of a turn) with work not yet on GitHub, it commits that work to its own
   branch for the piece and pushes it (`git push -u origin <branch>`). It is only a backup: no PR, never to `main` or
   another team's branch, never a merge, never a force-push (add new commits). A branch with no PR runs no CI. If the
   branch already has an open PR, a push updates the PR and re-runs CI, so push there only when the change is ready.
   The PR still opens only after "ship it", from the same branch, with the latest `origin/main` merged first. After a
   session restart on a fresh machine, fetch the branch before continuing.
7. **Owner away** (owner message to B.E.R.N.I.S.S, 2026-11-05 22:12 UTC): while the owner says they are not around,
   B.E.R.N.I.S.S approves for the owner. Such answers are recorded as "B.E.R.N.I.S.S for the owner", not as the
   owner's own words, and go on the owner's board for review. A "Not OK" from Daniel still holds a layout PR.

## 5. Rules

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md` and this file first. Never open `.env`. Synthetic data only.
- The guide documents and steers; it never changes a screen by itself. Screen changes are made by the owning
  builder team in its own PR.
- No new runtime dependency or font without the owner's approval.
- Screenshots for any screen change: 390 and 1440 px, light and dark (Staff Experience adds 320 and 768).
- Open a PR only after the owner says "ship it"; until then, backup-push to the piece's own branch (decision 6).
  Never merge. Never edit `docs/BASELINE.md`.

## 6. Backlog

1. **DES-1 KPOne design guide (merged in PR #245 as `8014a6d`).** Survey the current screens (headless Chromium on a task-owned server with seeded
   synthetic data, at 390 and 1440 px, light and dark) and write `docs/design/GUIDE.md` with examples and
   do / don't pairs, plus `docs/design/CHECKLIST.md`: a one-page checklist each team designer runs before
   hand-off. Docs only.
2. **DES-2 Screen review loop (this PR).** Agree with LUCY how PRs that change layout reach Daniel for a look (screenshots
   in the PR body), and keep a short "design debt" list of screens that break the guide, for the Staff Experience
   team and the owning teams.
   The loop is written in `docs/design/REVIEW.md` and the list in `docs/design/DEBT.md`.
