# DAY-1: the "My day" first page (design note)

Status: **owner decisions recorded (22 Nov 2026), not built.** Owner decision 31 (20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`):
"A 'My day' page as each role's first page, with work items and a personal routine checklist that resets daily."
This note says what the page shows, what it replaces, where the routine checklist is stored, and how it is built in slices. It
authorises nothing by itself. Synthetic data only. Written by Staff Experience (Kavitha, Aiman's review to come) on 22 Nov 2026
from the code on `main`. It builds on the Approvals inbox note (`APPROVALS-INBOX-NOTE.md`, decision 30) and reuses it.

## 1. What exists today (facts)

- **Sign-in lands on `/workspace`**, which redirects by role (`WorkspaceLandingService`): the TV account to its screen; a locum to My
  roster; a Resident Doctor with `queue.view.own` to the Consultation queue; a CA or CA Supervisor with `visits.view.branch` to
  Registration; **everyone else to `/dashboard`**, which is only the "KPOne Main Menu": a list of links, no work, no numbers.
- **The Me space** (`/spaces/me`, `MeSpace`) is the person's own HR data: clock-in ring, this week, leave, claims, credentials due,
  latest payslip. It has no "waiting for me" and no routine.
- **Department spaces** (Front desk, Pharmacy, Billing, Consultation, Panel, Stock, HR, Management) each show their own work
  widgets and counts. They are reached from the account menu, not as a landing page.
- **There is no personal routine or to-do list anywhere.** The only checklists are the clinic's monthly compliance checklist
  (`Compliance/ChecklistService`, set by managers, audited) and HR onboarding checklists; neither is personal or daily.
- **There is no "waiting for me" number** until the Approvals inbox (APPR-1) exists.

## 2. What "My day" is, and is not

- **Is:** one calm page per person, opened by default after sign-in for the roles whose current landing is only the Main Menu, and
  reachable from the account menu for everyone. It answers three questions in order: *Where am I today?* (clock, roster), *What is
  waiting for me?* (the inbox number and the areas), and *What do I do every day?* (my routine).
- **Is not:** a new work screen. Registration, the doctor's queue, Dispensary and Billing stay exactly as they are and stay the
  landing page of the people who work in them all day (CA, CA Supervisor, Resident Doctor, locum, TV). "My day" links into them.
- **Is not:** a place to decide, a notification system, a manager's view of staff, or a performance tracker (see section 6).
- **No new permission.** Every person may open their own "My day". Each block appears only if the person already holds the right
  behind it.

## 3. The page (one column on a phone, two from 1024 px; Daniel's look before build)

Shared `PageHeader`: "My day", a greeting line with the date in the branch's time zone and the active branch.

1. **Today** (reuses `MeSpace`'s existing pieces, no new query): the clock-in ring or "Clock in" button when the person may clock,
   today's shift from the approved roster, leave today, and the credentials due warning. Left out if the person holds none of the
   own-HR permissions.
2. **Waiting for me** (reuses the APPR-1 per-area totals from the same count endpoint, so there is **no second count path**): "N
   waiting" and up to four areas with their totals, and one **Open the inbox** button (the per-source Open buttons live on the inbox). Hidden when nothing is waiting ("Nothing is waiting for you."). This block is built in DAY-1c, after APPR-1a; until then the page simply has no such block.
3. **My routine** (new, section 4): the person's own checklist for today, with a progress line ("3 of 5 done").
4. **Shortcuts** (no counts of its own): the person's department spaces and the three most used pages for their role, taken
   from the existing space list. It is a convenience, not a second menu: nothing appears that the Main Menu would not already offer.

No patient name, IC, phone, amount or reason appears anywhere on the page.

## 4. The personal routine checklist

### 4.1 Behaviour
- Each person keeps their **own** list, for example "Check the fridge log", "Count the till float", "Review yesterday's Panel claims".
  Items are plain text, up to 120 characters, at most 20 items, in the order the person chooses.
- **It resets every day**: a tick belongs to one date (the branch-local date of the active branch), so tomorrow every item shows
  unticked without any scheduled job. Today's ticks can be undone the same day.
- The person adds, renames, reorders and removes their own items. Removing hides the item (it can be restored for 7 days), so a
  tap by mistake loses nothing.
- **Private to the person.** No manager, HR or administrator can see the list or the ticks. Nothing is reported, exported or counted
  in any report or in the agent platform. (Open question Q3 asks whether a clinic-set shared list is also wanted.)
- A visible line under the box: "Do not write patient names, IC numbers or passwords here."

### 4.2 Data (additive migration; Staff Experience range to be recorded, proposed from `2026_11_22_000100`)
- `my_routine_items`: `id`, `user_id` (restrict), `title` (varchar 120, **stored encrypted** like other personal text in KPOne; the 120 limit is checked on the plain text before encrypting), `position`, `hidden_at` (nullable), `created_at`; unique
  `(user_id, id)`; a PostgreSQL CHECK on title length and position range; at most 20 visible items per person enforced in the
  service under the person's row lock and by a trigger.
- `my_routine_ticks`: `id`, `item_id`, `user_id`, `done_on` (date), `created_at`; **unique `(item_id, done_on)`**, so a double tap
  or a retry cannot create two ticks. The user on a tick must equal the item's user (composite foreign key).
- **Retention (stated, not implied):** a small daily command, `my-routine:purge` (scheduled like `hr-documents:purge`), removes ticks
  older than 31 days, items hidden for more than 7 days, and the whole list of an account that has been deactivated for 30 days.
  Accounts are deactivated, not deleted, so there is no hard-delete on account removal. Items are not clinical, financial or audit
  evidence and are **excluded from `ai_read` and from every agent data source** (a privacy test pins both).
- **Audit:** one structural event, `my_routine.changed`, with the person's id, the action (`added`, `renamed`, `reordered`, `hidden`,
  `restored`) and the item count; never a title and never a tick. Ticking is not audited.
- **Free text guard:** besides the visible warning line, the server refuses a title that looks like an IC number, email address or
  phone number or contains a long digit run (the same best-effort rule the agent code uses), with the message "Do not enter patient or
  personal details." It is best-effort, not proof.
- **No other reader:** there is no route, report, support tool or impersonation path that reads another person's list (to be
  confirmed against the code at build, with a test that no route takes another user's id).

### 4.3 Rules every write follows (as AGENTS.md requires)
Permission is "own" (the actor's id only; there is no route that takes another user's id). `DB::transaction`, the person's `users`
row lock, `lock_version` on the list for **add, rename, reorder, hide and restore**, an idempotency key on add and restore, and the unique tick key for ticking.
Branch scope is used only for the date (the active branch's time zone); the list itself is the person's, not the branch's.
A person with no effective branch can still keep and tick the list; the date then follows the organisation default (Asia/Kuala_Lumpur).

## 5. Which first page, per role (this is the owner's main choice)

| Role | Today | Proposal |
|---|---|---|
| TV account | the TV screen | unchanged |
| Locum | My roster | unchanged |
| Resident Doctor | Consultation queue | unchanged; "My day" one click away |
| CA, CA Supervisor | Registration board | unchanged; "My day" one click away |
| Director, Finance Officer, Panel Officer, Administrator, Technical Admin, HR Manager, Marketing, Business Development, **Branch Manager, Account Assistant, Store Admin** | Main Menu (links only) | **"My day" becomes the first page**; the Main Menu stays as a link in the account menu |

**Precedence stays as it is today.** `WorkspaceLandingService` still checks, in order: TV, locum, Resident Doctor, CA or CA Supervisor; only the final fallback changes, from `dashboard` to `my-day`. So a person who also holds the doctor or CA role (for example a Director who is also a Resident Doctor, pinned by `WorkspaceTest`) keeps landing on their board, not on My day. The three new roles hold none of the doctor or CA roles, so they land on My day. `workspace.defaultUrl` stays `/workspace`.

Why: the people who work in a live board all day should not lose it; the people who today land on an empty link list gain the
most. Option B (owner's call): make "My day" the first page for **every** role, with the board a one-click button at the top of it.

## 6. Privacy, safety and scope limits
- Nothing here is shown to anyone but the person. The routine is not an attendance, discipline or appraisal input; no screen, report
  or agent reads it. If the clinic later wants shared routines (opening and closing lists), that is a separate, audited feature owned
  by HR or the compliance team (the monthly checklist already exists for that).
- No messages, reminders or push are sent. The page is read when the person opens KPOne.
- "Today" reads only data the person already sees on their own pages (own HR, the inbox count). No new permission and no new
  cross-person data.

## 7. Slices (each its own PR, with the owner's ship-it and Daniel's look)

| Slice | What | Existing assertions I expect to touch (listed for the owner first) |
|---|---|---|
| DAY-1a | the `/my-day` page with blocks 1 and 4, an account-menu link, and the landing rule of section 5 (the last fallback becomes `my-day`) | **One test changes:** `WorkspaceTest::test_workspace_landing_is_role_aware_without_implying_clinical_authority` (lines 16-18): `director`, `technical_admin` and `hr_manager` change from `'dashboard'` to `'my-day'`, and the new roles and the other Main Menu roles are added with `'my-day'`. `SecurityHardeningTest` (the Main Menu page still opens), `LocumSignInTest` and `QueueDisplayTest` (locum and TV landing) pin behaviour that does not change, so they should pass untouched; I will confirm by running them before the PR. The account-menu link list may need one line if a test pins it (checked at build time). `AuthenticationTest`, `EmailVerificationTest`, `VerificationNotificationTest` and `RegistrationTest` assert a redirect to `workspace`, which still holds. Many tests open `route('dashboard')` directly (BillingWorkTest, InventoryDirectoryTest, DashboardTest and others); they are unaffected because the Main Menu route stays. |
| DAY-1b | the routine checklist (block 3): tables, `MyRoutineService`, the add / rename / reorder / hide / tick routes, the page block | none expected |
| DAY-1c | after APPR-1a: block 2 "Waiting for me", from the inbox's per-area totals | none |

Tests Staff Experience writes: every role lands where the table says; a person can never read or change another person's items (id
swap, the same item key, a shared account); tick is idempotent and resets on a new branch-local date (frozen clock across Kuala Lumpur
midnight, which has failed tests before, so dates come from the branch time zone, never the UTC clock); the 20-item limit holds under
two concurrent adds (a real two-process PostgreSQL test in the serial Contention suite); titles never reach audit or any report; the
TV account gets 403; no patient value appears in the payload.

## 8. Questions for the owner (answered 22 Nov 2026; see section 11)
1. **Which roles land on "My day"?** Recommend the table in section 5: only the roles that today land on the Main Menu, so the
   doctor, CA and CA Supervisor keep their boards. (Alternative: everyone, with the board one click away.)
2. **Is the routine strictly personal?** Recommend yes: private to the person, no manager view, no completion reports.
3. **Also a clinic-set shared routine** (for example a CA opening list set by the CA Supervisor)? Recommend not in this slice; the
   monthly compliance checklist already covers shared lists, and a shared list needs its own rules and audit.
4. **Keep the Main Menu?** Recommend yes, as a link in the account menu, so nobody loses a way to the full list.
5. **Reset time.** Recommend midnight in the branch's time zone (a person working past midnight sees a fresh list).
6. **Routine size.** Recommend 20 items of up to 120 characters each; say if you want more.

## 9. Risks
- **Landing change** changes one existing test (`WorkspaceTest`, three expectations plus the new roles); listed for the owner first. Three other tests that mention the landing are expected to pass unchanged and are run before the PR.
- **Cost:** block 1 reuses own-data reads the Me page already makes; block 2 is one cached count; block 3 is two small indexed queries.
- **Scope creep:** people will ask for shared lists, reminders and manager views. Each is a different feature with different privacy
  rules and stays out of DAY-1.

## 10. After this note
Owner answers the six questions; Daniel looks at the page sketch (section 3); Aiman reviews; DAY-1a is built, then DAY-1b. The
Approvals inbox (APPR-1a) can be built first or in parallel; DAY-1a works without it.

## 11. Owner decisions, 22 Nov 2026 (the owner's own words, relayed by B.E.R.N.I.S.S)
"My day: 1 Main Menu roles, 2 yes, 3 no, 4 yes, 5 yes, 6 yes."
1. **Landing:** only the roles that land on the Main Menu today, plus the three new roles; the Resident Doctor, CA and CA Supervisor keep their boards (section 5 table, option A).
2. **The routine is strictly personal** (no manager view, no completion reports).
3. **No shared routine now.**
4. **The Main Menu stays** as a link in the account menu.
5. **The routine resets at midnight in the branch's time zone.**
6. **20 items of 120 characters.**
Next: Aiman's review, Daniel's look at the page sketch, then DAY-1a. The one existing test to change is listed in section 7 for the owner before any edit.

## 12. Daniel's design points, 22 Nov 2026 (OK with changes; sketches in `docs/design/sketches-2026-11-22/`, files `myday-*` and `sketch.html#myday`)
- **Routine rows** are 44 px checkbox rows (the whole row is the tap target). The progress line ("3 of 5 done") is plain text: no
  confetti, no streak count, no red "overdue". The unchecked box border measures at least 3:1 in dark mode at 390 px (avoid the faint
  dark ring of DD-107).
- **Reorder** uses Up and Down buttons (44 px), not drag only, so a phone and a keyboard can do it. **Add** has a visible label
  ("Add to my routine") and a field that shows the 120-character limit.
- **Recently removed** sits under a closed disclosure with a Restore button on each row (this is where the 7-day restore lives).
  Hiding needs no confirm sheet because it can be restored.
- **First visit:** an empty routine says "Add what you do every day. Only you can see this." with one outline Add button, not a large
  call to action.
- **"My work" is renamed "Shortcuts"** and must not look like a second menu. The header line shows the date and the active branch, so
  a person working past midnight can see why the list reset.
- **Blocks hide when the person has nothing in them, but the page is never empty:** if every block would be hidden, it shows one line
  with a link to the Main Menu.
Every sketch is a first picture for Daniel; the build shots after DAY-1a and DAY-1b are reviewed again.

## 13. Owner approval of the test change, 22 Nov 2026
"approve the My day and Approvals inbox test changes, inbox first": the single `WorkspaceTest` landing change in section 7 is approved as
listed. Build order: APPR-1a first, then DAY-1a and DAY-1b. Both slices still need the owner's ship-it before a PR opens.
