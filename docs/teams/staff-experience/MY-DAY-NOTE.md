# DAY-1: the "My day" first page (design note)

Status: **DRAFT for the owner, not built.** Owner decision 31 (20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`):
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
2. **Waiting for me** (reuses the APPR-1 count endpoint): "N waiting" and up to four areas with their counts, each an Open button
   to the page that owns the decision. Hidden when nothing is waiting ("Nothing is waiting for you."). Until APPR-1a is built this
   block is not shown at all.
3. **My routine** (new, section 4): the person's own checklist for today, with a progress line ("3 of 5 done").
4. **My work** (shortcuts, no counts of its own): the person's department spaces and the three most used pages for their role, taken
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
- `my_routine_items`: `id`, `user_id` (restrict), `title` (varchar 120), `position`, `hidden_at` (nullable), `created_at`; unique
  `(user_id, id)`; a PostgreSQL CHECK on title length and position range; at most 20 visible items per person enforced in the
  service under the person's row lock and by a trigger.
- `my_routine_ticks`: `id`, `item_id`, `user_id`, `done_on` (date), `created_at`; **unique `(item_id, done_on)`**, so a double tap
  or a retry cannot create two ticks. The user on a tick must equal the item's user (composite foreign key).
- Ticks older than 31 days are removed when the person next ticks something (no scheduler). Items belong to the person and may be
  hard-deleted when the person's account is removed; they are not clinical, financial or audit evidence and are not in `ai_read`.
- Titles are free text typed by a person, so they are **not logged in audit** (audit records only that a list changed, with counts).

### 4.3 Rules every write follows (as AGENTS.md requires)
Permission is "own" (the actor's id only; there is no route that takes another user's id). `DB::transaction`, the person's `users`
row lock, `lock_version` on the list for add, rename and reorder, an idempotency key on add, and the unique tick key for ticking.
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
| DAY-1a | the `/my-day` page with blocks 1, 2 (hidden until APPR-1a) and 4, an account-menu link, and the landing rule of section 5 | `WorkspaceTest`, `SecurityHardeningTest`, `LocumSignInTest` and `QueueDisplayTest` pin where each role lands; the Main Menu roles change from `dashboard` to `my-day`; the account-menu link list assertion; none for the roles that keep their board |
| DAY-1b | the routine checklist (block 3): tables, `MyRoutineService`, the add / rename / reorder / hide / tick routes, the page block | none expected |
| DAY-1c | (optional, after APPR-1a) connect block 2 to the inbox count | none |

Tests Staff Experience writes: every role lands where the table says; a person can never read or change another person's items (id
swap, the same item key, a shared account); tick is idempotent and resets on a new branch-local date (frozen clock across Kuala Lumpur
midnight, which has failed tests before, so dates come from the branch time zone, never the UTC clock); the 20-item limit holds under
two concurrent adds (a real two-process PostgreSQL test in the serial Contention suite); titles never reach audit or any report; the
TV account gets 403; no patient value appears in the payload.

## 8. Questions for the owner (plain words; my recommendation first)
1. **Which roles land on "My day"?** Recommend the table in section 5: only the roles that today land on the Main Menu, so the
   doctor, CA and CA Supervisor keep their boards. (Alternative: everyone, with the board one click away.)
2. **Is the routine strictly personal?** Recommend yes: private to the person, no manager view, no completion reports.
3. **Also a clinic-set shared routine** (for example a CA opening list set by the CA Supervisor)? Recommend not in this slice; the
   monthly compliance checklist already covers shared lists, and a shared list needs its own rules and audit.
4. **Keep the Main Menu?** Recommend yes, as a link in the account menu, so nobody loses a way to the full list.
5. **Reset time.** Recommend midnight in the branch's time zone (a person working past midnight sees a fresh list).
6. **Routine size.** Recommend 20 items of up to 120 characters each; say if you want more.

## 9. Risks
- **Landing change** touches four existing tests; each change is a one-line expectation per role and is listed for the owner first.
- **Cost:** block 1 reuses own-data reads the Me page already makes; block 2 is one cached count; block 3 is two small indexed queries.
- **Scope creep:** people will ask for shared lists, reminders and manager views. Each is a different feature with different privacy
  rules and stays out of DAY-1.

## 10. After this note
Owner answers the six questions; Daniel looks at the page sketch (section 3); Aiman reviews; DAY-1a is built, then DAY-1b. The
Approvals inbox (APPR-1a) can be built first or in parallel; DAY-1a works without it.
