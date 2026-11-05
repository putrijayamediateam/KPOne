# Screen review loop (DES-2)

How a screen change gets a design look from Daniel (Lead UI/UX) before it merges. Version 1, 2026-11-05.
Rules come from [GUIDE.md](GUIDE.md); the per-PR list is [CHECKLIST.md](CHECKLIST.md); accepted gaps go in
[DEBT.md](DEBT.md).

## 1. Which changes need Daniel

| Change | Who checks |
|---|---|
| A new page or route with a screen | Daniel: sketch first, then screenshots |
| A changed page layout: new section, columns, grid, header, table to cards, new sticky bar, a new dialog | Daniel: screenshots |
| A new shared component, or a change to `components/ui/*`, `components/workspace/*` or `layouts/*` | Daniel: sketch and screenshots |
| A small fix inside an existing pattern (wording, one field, one badge, spacing, a colour token) | The team designer only, using the checklist |
| Backend, permissions, data, tests only | Nobody |
| TV screen, public website profile, invoice, receipt or label print layout | Out of scope; the owner decides |

If unsure, ask Daniel: a look costs less than a second PR.

## 2. The two steps

**Step 1: sketch (new pages and new components only, before building).** The team designer sends LUCY a short
text sketch with:
- the page and where it sits (route, menu or link, roles that see it);
- each state: empty, normal, busy, error, refused, and any status chips with their wording;
- the phone layout at 390 px (what stacks, what becomes cards, what is in the row menu);
- every colour or badge, named by token or `StatusBadge` tone, not by palette;
- the exact wording, in the screen's language.

Q-2 (treatment progress) and UX-2 (Medical Certificates link) are good examples of a sketch at the right size.

**Step 2: screenshots (before hand-off to LUCY's review).** In the PR body, under a `## Design` heading:
- the checklist, ticked or with a reason against each unticked line;
- the head SHA the screenshots were taken from;
- which demo accounts and URLs show each state;
- the screenshots: 390 and 1440 px, light and dark (Staff Experience also 320 and 768 px), with synthetic `.test` data
  only. If the session cannot attach images, list the accounts, URLs and states and Daniel takes them himself (below).

## 3. How a look reaches Daniel

1. The team sends LUCY: "Design look: PR #n, head `sha`, pages, accounts, states" (or the sketch).
2. LUCY forwards it to Daniel's session with `send_message`.
3. Daniel checks out the PR head on a task-owned server and PostgreSQL 18 database (host, port and name verified
   before the first request; never a command that loads `.env`), seeds `migrate:fresh --seed` plus the
   presentation and demo-setup seeders, and takes his own screenshots at the four combinations. He never edits the
   PR branch.
4. Daniel replies to LUCY with one of the three verdicts below, keyed to guide section numbers.
5. After the team's changes, LUCY sends the new head SHA; Daniel re-checks only the lines he raised.

Daniel aims to answer within the same working session. A sketch look never blocks the team from starting backend
work.

## 4. Verdicts

| Verdict | Meaning | LUCY does |
|---|---|---|
| **OK** | Follows the guide | Proceeds with the normal review and merge |
| **OK with changes** | Small fixes; list given | The team fixes them in the same PR; Daniel re-checks only those lines |
| **Not OK** | One or more blockers (below) | No merge until fixed, or the owner accepts it as debt |

**Blockers** (always Not OK): sideways page scroll at 320, 390 or 768 px; unreadable text or invisible controls in
dark mode; a page with no `h1`; a form field with no label; an icon-only button with no accessible name; a toast or
dialog that covers the main action; a hard-coded palette colour with no dark style; a print layout change without the
owner; mixed languages in one sentence.

Everything else is a change request, not a blocker.

## 5. Design debt

When a team keeps something that breaks the guide on purpose (time, owner choice, waiting on another team), Daniel
adds it to [DEBT.md](DEBT.md) with an ID, the owner team and the guide section. The owner can accept a blocker as
debt; only the owner, not LUCY or a team, can do that. Debt is reviewed when the owning team picks its next slice.

## 6. What Daniel does not do

Daniel does not merge, does not edit another team's files, does not edit `docs/BASELINE.md`, and does not change a
screen himself. Fixes are made by the owning team in its own PR.
