# HR-8 Announcements and memos: design note

Status: **agreed by Liyana (HR) on 2026-11-10 with nine changes, folded in below; owner decisions in section 10** (written by the Queue & TV team, Amirul Lead, 2026-11-10, because the
owner asked B.E.R.N.I.S.S to give HR-8 to Queue & TV: "i want to start all that"). Suresh (HR) reviews the PR. Follows `HR-PLAN.md` (HR-8, about line 288) and
`TEAM.md` sections 4, 6 and 8.

## 1. What the clinic gets

A Director or HR Manager writes a memo (title, plain text, optional PDF), chooses who it is for, and publishes it.
Each person in the audience sees it under **Memos**, opens it, and presses **I have read this**. The publisher sees
**who has not read it yet**. A memo is never edited after publishing; a correction is a new memo that says it
replaces the old one. In KPOne only: no email, push, SMS or WhatsApp. HR-8 sends nothing and never calls `StaffMessenger`
(TEAM.md decision 5 is amended: HR-14 can send email, off by default).

## 2. Who is in the audience (the rules to agree)

| Choice | Who is expected to read |
|---|---|
| Nothing ticked ("All staff") | every active person with a human role |
| Branches ticked, no roles | active people with an **effective assignment** at any ticked branch (today, branch time zone) |
| Roles ticked, no branches | active people who hold any ticked role, at any branch |
| Both ticked | people who match a ticked branch **and** a ticked role (AND, not OR) |

- "Human role" is `RosterPermissions::HUMAN_ROLES` (never the TV account), taken from the account's **current** roles. Inactive accounts are not expected.
- "Effective assignment" is read from `staff_branch_assignments`: valid today in that branch's own time zone.
- The page says **"expected now"** ("3 of 12 expected now have read"), because the list is live.
- A person working at two branches is expected for a memo to either branch. The branch shown beside a name is their
  primary effective branch, as on the SOP list.
- A person with no effective branch assignment is expected only for "All staff" and for role-only memos.
- The expected list is **computed live** when a page opens (as SOPs do), so people who join, change role or change
  branch later become expected for memos that are still open. No recipient snapshot (HR-5c made the same choice).

## 3. Acknowledging

- The person opens the memo first (the open is recorded), then **I have read this** becomes available. Disabled
  button hint: "Open the memo first." Same mechanic as HR-5c.
- If the memo has a PDF, the PDF must be opened too ("Open the PDF first."). A text-only memo needs only the
  memo itself to have been opened.
- One acknowledgement per person per memo, append-only. A double click is an idempotent retry.
- The audience check happens in `MemoService::acknowledge()` under the account lock, not in PostgreSQL. PostgreSQL refuses an
  acknowledgement unless the memo is not withdrawn, not replaced, not past `expires_at`, and the opens exist
  (the memo, and the PDF if there is one).
- A withdrawn, replaced or closed memo accepts no new acknowledgements; the reads already given stay.
- Opens: one row per person, memo and kind (`insertOrIgnore`, so a second open adds no row); the audit row is written on every open.
  The open is recorded in the same transaction before any text or PDF byte is returned.

## 4. Lifecycle

- **Published**: shown to the audience, in "Memos to read".
- **Replaced**: a new memo can name the memo it replaces ("Corrects the memo of 3 Nov"). The old memo stays and shows
  "Replaced by a newer memo". It takes no new acknowledgements, like a closed memo. Its "who hasn't read" list is labelled
  "Replaced" and tells unread people to read the new memo. The list is not carried to the new memo. A memo can be replaced
  once, and a withdrawn memo cannot be replaced.
- **Withdrawn**: the publisher withdraws a memo (a reason from a short fixed list). Staff see "Withdrawn"; every
  read already given is kept; nothing is deleted.
- **Optional "Open until" date**: after it the memo is **closed** (no new reads, listed under Closed). Without a
  date the memo stays open until withdrawn. Entered as a date, closing at the end of that day in the publisher's
  active branch time zone. `expires_at` is stored in UTC, computed once at publish, and shown in the viewer's branch time.

## 5. Screens (one page, `/hr/memos`, reached from the Clinic management hub)

Tabs shown only when the person has more than one (as `Sops.vue` does):

- **My memos** (everyone with the read permission): "To read" first (newest first), then "Read", then "Closed or
  withdrawn". Each row: title, who published it, date, an "Open" action. Opening shows the full text and the PDF
  link; the acknowledge button sits under the text. Empty state: "No memos for you."
- **Manage** (publishers only): the publish form (title, plain text up to 4,000 characters, optional PDF, audience
  with branch and role ticks and a plain "Who will see this: N people" line before publishing, optional
  "Replaces", optional "Open until"), then a list of published memos with "N of M have read", and per memo a plain
  **"Who hasn't read yet"** list (name, role, branch, published date; no red, no ranking, as HR-5c).
- **My HR details** gets a small "Memos to read: N" card linking to `/hr/memos`, the same pattern as SOPs.
- Accessibility and phone rules of the HR design rules apply (44 px targets, no sideways scroll at 320, 390 and
  768 px, light and dark). Hui Min (HR) or Yasmin (Queue & TV) sketches before building; Daniel looks at every
  screen.

## 6. Data (PostgreSQL 18, migration range `2026_11_13_2xxxxx`)

| Table | Purpose | Rules |
|---|---|---|
| `hr_memos` | one row per memo: `public_id` uuid, `organisation_id`, plain-text `title` (hint on the form: "Don't put a staff member's name, health or pay in the title."), encrypted `body`, optional PDF through the HR-5c SOP path (10 MB, content check, HR-4b vault under a generated name, no original filename kept; `pdf_path`, `pdf_bytes`, `pdf_sha256`), `published_by_user_id`, `published_at`, nullable `expires_at`, nullable `replaces_memo_id`, nullable `withdrawn_at`, `withdrawn_by_user_id`, `withdrawn_reason`, `lock_version` | composite `(id, organisation_id)` keys; never deleted; title, body, PDF and audience cannot change after publishing; a withdrawn memo stays withdrawn; `replaces_memo_id` must be an earlier memo of the same organisation |
| `hr_memo_branches` | audience branches (none = no branch limit) | append-only; organisation-scoped foreign key to `branches` |
| `hr_memo_roles` | audience roles (none = no role limit) | append-only; role allowlist check |
| `hr_memo_opens` | `memo_id`, `user_id`, `kind` (`memo` or `pdf`), `created_at` | append-only; one per person, memo and kind |
| `hr_memo_acknowledgements` | `memo_id`, `user_id`, `created_at` | append-only; unique per person and memo; a guard trigger refuses an acknowledgement unless the memo is not withdrawn, not replaced, not past `expires_at` and the needed opens exist (the audience is checked in the service) |

Append-only and truncate guards reuse `guard_hr_records_append_only()` (HR-5c). Audit events carry ids, counts and
codes only, never the title or text: `hr.memos.published`, `.withdrawn`, `.opened`, `.acknowledged`.

## 7. Domain and permissions

- `app/Domain/HR/Memos/Services/MemoService.php`: `publish`, `withdraw`, `open`, `acknowledge`; `MemosPageService`
  builds the tabs and the "who hasn't" list. Every write is a `DB::transaction` with a fresh permission and scope
  check under lock, `lock_version`, an idempotency key and an audit row (TEAM.md decision 9).
- Routes `hr/memos` (`sensitive.no-store`, throttled as SOPs are), explicit redirects after each action.
- **Two new permissions, additive and narrow** (for the owner's list):
  - `hr.memos.read.own`: every human role (`HUMAN_ROLES`); never the TV account.
  - `hr.memos.publish.organisation`: Director and HR Manager.
  Granted by an additive migration (`firstOrCreate`, give only what is missing, a missing role logged and skipped,
  `down()` a no-op), mirrored in `PermissionCatalogue`, in neither people-and-access authority set.
- Hub: one row in `ClinicManagementNavigation.php`.
- My HR details: one prop (`memosToRead`) and one card in `RecordsController`/`Records.vue`; Liyana is copied on that diff.

## 8. Tests

- Feature: exact grants; the audience matrix of section 2 (all, branch only, role only, both, two-branch person, no
  effective branch, inactive, TV account, a person who joins later); open before acknowledge (memo and PDF); no
  acknowledgement after withdrawal or closing; replaces link rules; PostgreSQL refuses edits, deletes and truncates;
  another organisation's memos are not found; audit rows carry no text; double click is idempotent; acknowledgement refused
  outside the audience (service level); acknowledgement refused after expiry at branch-local midnight with the clock frozen at
  16:00 UTC; a replaced memo takes no new acknowledgement.
- Frontend: `hr-8-memos.test.mjs` (compile, button states, "who hasn't" list, My HR details card).
- **One existing assertion changes** (needs the owner's approval, as HR-5c had in decision 51):
  `tests/Feature/ClinicManagementTest.php` exact hub map gains
  `'/hr/memos' => ['hr.memos.read.own', 'hr.memos.publish.organisation']`.
  The staff-authority pin test and permission-count checks are run to confirm they are unaffected.

## 9. Not in this slice

Email, push, SMS or WhatsApp (HR-14 may later offer an opt-in "email me when a memo is published" through its own
settings; HR-8 never calls `StaffMessenger`); recipient snapshots; comments or replies; scheduling a memo for later;
editing a published memo; attachments other than one PDF.

## 10. Owner decisions (B.E.R.N.I.S.S for the owner, delegation on, 2026-11-10)

1. **CA Supervisor publishing branch memos: no in this slice.** Director and HR Manager only; it can come later (it
   would need `hr.memos.publish.branch` and a branch check).
2. **Optional "Open until" date: yes.**
3. **Technical Admin and Administrator are expected readers of "All staff" memos: yes** (they are staff).
4. **Branch and role ticked together means AND: yes.**
5. **Permissions approved, both additive:** `hr.memos.read.own` (all human roles, never the TV account) and
   `hr.memos.publish.organisation` (Director and HR Manager).
6. **The one existing assertion change is approved:** `tests/Feature/ClinicManagementTest.php` exact hub map gains
   `'/hr/memos' => ['hr.memos.read.own', 'hr.memos.publish.organisation']`.
   List 5 and 6 in the PR as "approved by B.E.R.N.I.S.S for the owner, 2026-11-10".
7. **Build once Liyana and Suresh (HR) agree this note.**

Flag passed to HR by the coordinator: `TEAM.md` line 487 and decision 5 say HR-14 sends no email, but the HR-14 code
(`app/Domain/HR/Messages`, `HR_MESSAGES_EMAIL_ENABLED`) can send email.
