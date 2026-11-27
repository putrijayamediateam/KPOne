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

Mock-up designers (owner request, 2026-11-18; added to the Design team by Daniel). Four named helper sessions that
B.E.R.N.I.S.S starts when a batch of new-look boards is needed. They draw boards on the new-look canvas
(`https://claude.ai/artifact/Jyr8UV231y4roWRyyqxxRJ`) from `docs/design/NEWLOOK-PILOT.md` and never touch the code or the
repository. Role: **mock-up designer (canvas only)**. Daniel's look still decides every real screen; a board is a
picture to compare against, not an approved design.

| Mock-up designer | Boards |
|---|---|
| Zara | Today and Front desk: Approvals, Me, QR check-ins, Patient profile |
| Jun Hao | Consultation, Pharmacy and Billing: My queue, Issue MC, Dispensary case, Cash closing |
| Deepa | Panel and Stock: Claims, Panel payments, Stock count, Receiving |
| Haikal | HR, Reports and Settings: Leave, Payroll, Reports, Staff and access |

Rules for the mock-up designers: fictional data only (no real names, numbers or figures as if real); follow the pilot
note's tokens, one black button per view, status words with dots, contrast, and the space rules; mark any content that
is not an existing feature as "idea, not built"; never copy the mock-up's rejected patterns (section 7 of the note).
Boards go to Daniel through B.E.R.N.I.S.S for a look before the owner sees them as a proposal.

**Update 2026-11-20:** the New look pilot these boards were for was retired with Version 2 (owner decision 19 Nov,
"Classic only"). The four mock-up designer roles stay on the team for any later mock-up work the owner asks for;
until then they have no open boards.

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

## 8. Handover to a fresh session (27 Nov 2026)

Owner decision 27 Nov (relayed by B.E.R.N.I.S.S): every team gets a fresh session on a new model, one slice per session.
This section is what the next Daniel needs.

**Current assignment: Clean Look replaces Classic** (owner, 25 Nov). Restyle every page in place with the shared
components (`resources/js/components/ui/*`, AppShell, AppSidebar, AppHeader, `resources/css/app.css`); no V2-style switch,
no duplicate pages. Deadline Mon 30 Nov 08:30 MYT (live test at Puchong). Stage 0 (spec, tokens, shell) Thu 26 Nov;
Stage 1 (Puchong CA flow) Sat 28 Nov; Stage 2 (everything else, the "Sunday sweep") Sun 29 Nov 18:00. Process: Daniel's OK
plus the Reviewer's OK, green CI, then B.E.R.N.I.S.S merges; the owner gives one ship-it per group.

**Spec:** `docs/design/CLEAN-LOOK-SPEC.md` is on `main` (reconciled 26 Nov by the coordinator; my original draft is
branch `docs/clean-look-spec`, pushed, no PR, superseded by the reconciled version on main). Owner answers recorded in it:
accent blue #007aff for non-text interactive graphics, #006ae0 for text and fills (contrast 5.07:1); KPOne pink on the
logo only; TV stays English; Queue board details panel as the canvas shows it; every canvas feature built, including the
red zone alert, zone ordering and bulk selection (selection only, the canvas defines no bulk action).

**Canvas:** `https://claude.ai/artifact/Jyr8UV231y4roWRyyqxxRJ` (about 180 boards, light only). The "Viewing as <role>"
switcher and Proposal / Being built tags are demo only and are not built.

**Reviews given in the last stretch (all verdicts sent; owner ship-its still needed):** DSP-27, DSP-28 (Checked tab
re-shots OK, `validation/dsp-28-shots`), Q-16 (OK; note the panel is modal where the canvas panel sits beside the list),
HR-22e/22f, STK-46/47-60/48/49/61 design notes, REL-STK shots. STK-47/60: I accepted "No use in 30 days" instead of
"No dispensing data"; shots at 1440 and 390 (light and dark) from Zulkifli are still to review.

**Pending follow-ups from Leads:** Nurul AUTO-1c re-shot at 1440 light with shorter chip labels; Syafiq PNL-25 identity
line; Irfan CHAT-2b padding re-shot; Kavitha APPR-1a/DAY-1 and the closed-rail re-shot; Farah BIL-37 duplicate-line fix;
Aisyah DSP-27 minor icon parity at 390; Zulkifli STK-46/48/49/47-60/61 shots when built.

**Debt rows to write (not yet in `DEBT.md`, last row is DD-118), on a `docs/...` branch for B.E.R.N.I.S.S to open:**
DD-119 the shared Sheet Close X is 16 px (needs 44 px below 1024 px); a row for shared select option and Register Visit
button heights (36/32/40 px below 1024 px); optional: the Director seeing Edit and Cancel Visit on the board, and the
"N to check" count on the Removed tab.

**Next slice:** review Stage 1 and Stage 2 page PRs against `CLEAN-LOOK-SPEC.md` section 10 (per-PR checklist), update the
spec's board table if a Lead's gap list corrects a row, then write the debt rows above.

**Rules that still bind:** one filled button per view; status as dot plus word; reasons start empty; 44 px targets below
1024 px; text 12 px minimum; read-only pages render no controls; never open a PR unless the owner types it; never merge.

## 9. Session notes (27 Nov 2026, Clean Look Stage 1 reviews)

Slice: review Stage 1 Clean Look screens for the Puchong CA test against `docs/design/CLEAN-LOOK-SPEC.md`. All verdicts went to B.E.R.N.I.S.S.

**Verdicts given**
- **#1135 role homes (Kavitha): OK with changes**, from the shots at 1440 and 390 (CA, CA Supervisor and Resident Doctor My day, light and dark; not every file opened).
  1. Show the branch name once (it appears three times). 2. Make Clock in the one filled blue pill; Add (routine) becomes outline. 3. Replace the blue, green and amber tinted tiles (leave, claims, roster, attendance) with neutral `--card` tiles. 4. "This week" title to 15/600, and stack its right-hand text at 390.
  Not blocking: the spec section 7 greeting and "Needs you" card are missing (accepted for Monday); confirm that the rail stopping at 900 px in full-page shots is a capture artefact; populated lists unseen (the seed has no visits).
- **#1139 Dispensary case (Aisyah): OK with changes, from the diff only.** Tokens replace palette classes, row buttons are outline, one filled button, allergy card has icon plus words, the `xl` three-column exception is honoured. Needs shots (1440, 1280, 390, light and dark: normal, allergy recorded, return needed, confirm panel open, OTC allergy card) and a check of the outline "Save all lines" edge on the warning wash. The final OK waits on those shots.
- **DSP-28 Quarantined chip: OK** (info and info-wash, dot plus word, other outcomes neutral). A 390 shot of the returns table is wanted, not blocking.

- **#1132 Registration (Nurul): OK with changes**, from 7 of the 24 shots (`validation/clean-look-registration-shots`, PR head `2beb44539`): board CA light 1440 and 390, board Director dark 1440, Register Visit CA light 1440 and dark 390, Create patient Director dark 1440. Not opened: the other CA and Director shots and the Create patient shots at 390.
  1. "RECENT PATIENTS" to sentence case. 2. Create patient: drop the pink icon tile and use `PageHeader` (26/30). 3. Phone country select and the unknown-date-of-birth checkbox are native; use the shared select and checkbox (DD-73 family). 4. At 390 the board's empty state is a box inside the card (use one surface) and the tab strip has no right-edge fade. 5. Confirm that the missing Dispensary and Awaiting Billing count chips on the Director board are a permission difference.
  Asked Nurul to confirm the frozen "12 Nov 2026" date, that the Create patient footer has exactly one filled button, and to send a populated board (the seed has no visits).

**Not yet reviewed:** Q-16c fixes (Amirul) are a later slice and #1253 (Q-16d) is backend only.

**Debt:** DD-119 to DD-122 are on `docs/design-debt-dd119` (`948bdd0a`), written from the handover notes and not re-checked in code. B.E.R.N.I.S.S opens the PR after the Monday merges.

**Process notes:** review from diffs when shots are missing, and say so in the verdict. The cross-session `SendMessage` to the coordinator returned 401; the `mcp__claude-code-remote__send_message` tool works.
