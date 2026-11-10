# Queue & TV builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Amirul** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Mira** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |
| Designer | **Yasmin** | Sketches the layout of any screen change before it is built, and checks the screenshots before hand-off (under Lead UI/UX Designer Daniel, Design team) |

All are Claude sessions working for the owner through the coordinator session, B.E.R.N.I.S.S (formerly LUCY). Names chosen by the
coordinator (2026-11-05); Yasmin added by owner decision 2026-11-05 (every builder team gets a designer).

## 2. What this area covers

**In scope (the team builds and maintains):**
- Consultation queue: Queue entries, A numbers, Urgent-first order, Send to Waiting, Call In, Call Again /
  recall, previous-day carry-over, the doctor's own queue, waiting counts.
- The not-ready-to-call flag (call readiness): set, clear, doctor override, ready alert.
- OTC waiting list (B numbers) and Call to Dispensary.
- Doctor room choice for the day; branch rooms.
- The waiting-room TV: screen, feed, chime and spoken call, posters, video, scrolling text, call display mode
  (number or name), Queue Display settings.
- Treatment-room calls.

**Shared with other teams (change only through a small agreed change, told to LUCY first):**
- The Registration board (`resources/js/pages/Registration/Index.vue`, `components/patient-board/PatientBoard.vue`)
  and its Waiting tab: **Patients & Registration** team (Nurul, Daniel).
- Consultation Hold / Resume (`app/Domain/Clinical/Services/ConsultationHoldService.php`), encounter pages:
  no clinical team yet; ask LUCY before changing.
- Dispensary case and OTC dispensing (`OtcDispensaryService`, Dispensary pages): **Dispensary & OTC** team.
- Public QR status page queue insight (`PublicQueueInsightService`): QR check-in area (no team yet; ask LUCY).
- Permissions catalogue: changed only by an owner-approved migration.

## 3. Where the code lives

| Kind | Files |
|---|---|
| Domain | `app/Domain/Queue/` — `Services/` (`QueueEntryService`, `OtcQueueService`, `QueueDirectoryService`, `QueueNumberGenerator`, `QueueWaitingCountService`, `PublicQueueInsightService`), `Display/` (`QueueCallRecorder`, `QueueDisplayFeedService`, `QueueDisplayAccess`, `QueueDisplayAdministrationService`, `RoomCallService`, `DoctorRoomService`), `Readiness/` (`CallHold`, `CallHoldService`, `CallHoldProjection`, `CallHoldPermissions`), `Models/`, `Policies/QueueEntryPolicy.php`, `QueueNumberFormat.php` |
| Controllers | `QueueController`, `QueueReadinessController`, `RoomCallController`, `QueueDisplayController`, `QueueDisplaySettingsController`; requests `CallQueueEntryRequest`, `SearchQueueEntriesRequest`, `SendToWaitingRequest` |
| Pages | `resources/js/pages/Queue/Index.vue`, `resources/js/pages/QueueDisplay/Screen.vue` (no layout), `resources/js/pages/QueueDisplay/Settings.vue`; components `resources/js/components/queue/*`; `resources/js/lib/queue-display.ts`, `lib/queue-polling.ts`, `composables/useCallReadinessAlerts.ts`, `types/queue.ts` |
| Tests | `tests/Feature/Queue/*`, `tests/Feature/PostgresQueueRegressionTest.php` (+ `tests/Support/PostgresQueueWorker.php`), `tests/Feature/Clinical/RoomCallTest.php`; frontend `tv-1-queue-display`, `tv-2-room-calls`, `tv-5-last-called`, `ds-02b-call-display-mode`, `call-readiness`, `consultation-waiting-counts`, `p1a-queue-performance` |

## 4. Permissions (who can do what today)

| Permission | Holders | Use |
|---|---|---|
| `queue.view.branch`, `queue.enter.branch` | Director, CA, CA Supervisor | See the branch queue, send to waiting |
| `queue.call.branch` | Director, CA Supervisor | Call In from the branch board |
| `queue.view.own`, `queue.call.own`, `queue.room.select.own` | Resident Doctor | Own queue, Call In, choose room |
| `queue.call_hold.set.branch`, `queue.call_hold.clear.branch` | CA, CA Supervisor | Not-ready-to-call flag |
| `queue.call_hold.override.own` | Resident Doctor | Override the flag on own entry |
| `queue.display.branch` | `queue_display` (TV account) only | The TV screen |
| `queue_display.manage.organisation` / `.branch` | Director, Administrator / CA Supervisor | TV settings |

`app/Domain/Access/PermissionCatalogue.php` is the source of truth; check it before relying on this table.

## 5. What is built (history)

| Slice | What it did | PR |
|---|---|---|
| 1C | Consultation Queue, Call In, Serving, carry-over | merged |
| P1A | Queue performance | #14 |
| HC-01 | At most three held consultations per doctor, 30-minute warning | #44 |
| TV-1 / TV-2 | Waiting-room TV, rooms, consultation, dispensary and treatment-room calls | #57, #60 |
| TV polish, TV-3, TV-4 | Fullscreen, theme, logo; earlier calls stay listed; ding-dong chime | #62, #64, #65 |
| QUEUE-02 | Refused Call In returns to the page it was pressed on | #66 |
| DS-02a / DS-02b | OTC B-number list; call by number or full name | #85, #86 |
| Not-ready flag | Structured not-ready-to-call flag with doctor override | #177 |
| THROTTLE-1 | Per-route throttle counters (polling no longer blocks Call In) | #193 |
| TV-5 | "Last called" after 15 minutes | #202 |
| Q-1 | Waiting time on the Queue board and the Registration board (All and Waiting tabs, A and B numbers) turns amber from 30 min and red from 60 min (frontend only, from the existing `queued_at`; shared `PatientBoard.vue` gains one optional `waitingMinutes` row field; rule in `resources/js/lib/waiting-time.ts`) | #240 |
| Q-2 | Treatment progress: CA, CA Supervisor or the attending doctor mark a controlled step (8 steps) started then done on a Serving consultation; blue/green chip on the Queue board and consultation page; attending-doctor pop-up and count; cleared by Resume or opening the consultation; append-only evidence (`app/Domain/Queue/Treatment/`, migrations `2026_11_08_000100` and `000200`); TV shows nothing | #256 |
| Q-2b | Shared board (Queue and Registration): Status column widened from 9% to 15% and Payment narrowed from 24% to 18%, so status, not-ready and treatment chips stop wrapping; Payment keeps its two-line Panel name and note tooltip; 390 px cards unchanged. The consultation dropdown's "ready to resume" count line is `font-medium` (dark-mode legibility). Frontend only | #264 |
| Q-3 | Gentler TV name mode: a third branch call display mode "Number and short name" (`short_name`) beside number and full name. The server builds the short name (`app/Domain/Queue/Display/ShortDisplayName.php`): the first two words, only the first if the pair is over 24 characters, skipping bin, binti, bt, bte, a/l, a/p, s/o, d/o and "@"; no usable word falls back to the number. The full name never reaches the TV in this mode. The TV list, current call ("A-012 · Ahmad Ismail") and spoken call (number, name, room) use it; number and full-name modes are unchanged. Settings explains each mode's privacy. Migration `2026_11_08_000300` widens the PostgreSQL mode check. TVs need a reload after deploy | #274 |
| Q-3b | On a 720p-class TV (1024 px or wider and at most 800 px tall) the short-name current call keeps the number and name on one line ("A-001 · Ahmad Ismail"): the normal heading size is the largest, and the line shrinks only as much as the card needs, down to 24 px; a name that still does not fit wraps rather than losing letters. On those TVs the room line is never larger than the call line (Daniel), so the call stays the largest text and the room line stays on one line. Measured at 1280 × 720: "A-001 · Ahmad Ismail" 38 px on one line (was four lines); "B-005 · Nurul Syafiqahsyahirah" 26 px with the room at 26 px; room line and earlier calls visible. Larger TVs (1920 × 1080 stays at 96 px), phones, number mode and full-name mode unchanged. Frontend only (`QueueDisplay/Screen.vue`) | #290 |
| Q-4 | Call next: one button on the Consultation queue board that calls a doctor's next eligible patient through the ordinary Call In (same permission, branch, lock-version, room, busy and audit rules). The doctor's own queue calls their own next patient; a branch caller (`queue.call.branch`) first applies a doctor filter. Today's Waiting first (Urgent, then arrival), carry-over only when nobody today can be called; entries with an active not-ready flag are skipped (no override here). The person confirms a named patient; if that patient is no longer next the server refuses (`next_changed`) and the dialog shows the new one and asks again, never calling on its own. Disabled with a muted reason when no doctor is chosen, the doctor cannot work at the branch today, is already seeing a patient, has no room, nobody is waiting or everyone is not ready. `app/Domain/Queue/Services/CallNextService.php`, `POST /queue/call-next`, a `callNext` block on the queue poll, `components/queue/CallNextControl.vue`. No migration or permission | #282 |
| DD-30 | Queue board tabs on phones: below 640 px the "Live · every 3 seconds" line moves to its own line under the tab strip, so the strip uses the full width and scrolls (390 px shows All active, Waiting and Serving Now in full, with Consultation Completed cut at the edge as a swipe hint; 320 px shows two), and the tabs are 44 px tall. Choosing a part-hidden tab (tap, or arrow, Home and End keys) scrolls the strip sideways until that tab is fully visible (Daniel). While more tabs are hidden to the right, the strip's right edge fades and phone tabs use 10 px side padding, so the start of the next tab peeks in as a hint (Daniel). The active tab keeps `aria-selected`, the correct mark for tabs. 1440 px is pixel-identical before and after. Page classes only (`pages/Queue/Index.vue`); the shared `OperationalTabs` component is unchanged | #296 |
| Q-4b | Call next on a held consultation: while a doctor's own consultation is On Hold, the held notice on the consultation page offers the Q-4 Call next button and dialog (same server check, same Call In rules and audit). It reads the doctor's own Call next block from a new read-only `GET /queue/call-next` (`queue.call.own`, active branch only, no-store, polled every 10 s while the tab is visible). A successful call goes to the Queue board; a refused call returns to that held consultation (only the actor's own visit, otherwise the board) so the dialog can show the latest patient. "You can call your next patient meanwhile." shows only when the button can be pressed. The held notice now uses the warning tokens, so it has a dark-mode colour. The "next patient changed" line in the Call next dialog is now shown in the warning tone with an icon, on the board as well (Daniel). Not offered outside a hold (the doctor is busy) or to branch callers. `CallNextService::ownBlock`, `QueueController::callNextBlock`, `components/queue/HeldCallNext.vue`. No migration or permission | #305 |
| DD-38 | Board and consultation chips in dark mode (Yasmin's tokens): on the Queue and Registration boards (`PatientBoard`) the status chips use theme tokens with a border: On Hold is the warning tone, Serving the accent tone, Waiting neutral (the Q-1 time chip keeps the long-wait amber and red), and the quiet states (neutral, cancelled, removed) are outline only; Urgent is the danger tone on the boards and the consultation page; Returned is neutral; the "Not ready to call" panel moved from a hard-coded orange to the warning tokens (Daniel). Measured: 4.58:1 or better everywhere, Urgent 5.20:1 light and 4.85:1 dark, the not-ready panel 6.2:1 light. Frontend classes only | #331 |
| Q-4c | The `queue.called` audit row says when Call next was used and where: `via: call_next` and `call_next_source: board` or `held_consultation` ("held consultation" only when the server sees the actor's own consultation on hold at that branch, checked inside the call's transaction; anything else counts as the board). The mark is set only by `CallNextService`, never from request input, and is structural only (no patient, visit or queue number). An ordinary Call In keeps exactly today's audit row. The Audit log page shows a short muted line under the event, "Via Call next, from the Queue board" or "Via Call next, from a held consultation" (Daniel's wording) (a new optional `detail` field, null for every other event). Rows recorded before Q-4c have no mark. No migration or permission | #312 |
| Q-5 | Consultation Completed tab (owner decision 2026-11-06, approved by B.E.R.N.I.S.S for the owner, then confirmed by the owner in the team session): the tab count stays the server count of every completed entry at the branch (all days, with the board filters); the list still shows the latest 25, and a muted line under it says "Showing the latest 25 of N" when more exist. Frontend only | #352 |
| Q-5b | Serving Now tab, the same as Q-5 (B.E.R.N.I.S.S for the owner, 2026-11-06): the list shows the latest 25 serving entries while the count is every serving entry at the branch (a patient left in Serving from an earlier day still counts), so a muted "Showing the latest 25 of N" line shows under the list when more exist; none while the tab has no rows yet. `servingShownLine` sits beside the Q-5 helper in `lib/completed-tab.ts`; the Q-5 code and test are unchanged. Frontend only | #358 |
| DD-34, DD-35, DD-36 | Dark mode on the consultation page (Yasmin's sketch; DD-36 from Mira and Daniel): the "My Queue" link uses `text-success` instead of `text-emerald-800`; the "Record changed elsewhere" panel now uses the warning tokens (as the held notice), and the unsaved-work and hold error panels use `border-destructive/20 bg-destructive/5 text-destructive`. Measured on the page: 5.66:1 and 5.46:1 light, 12.50:1 and 5.10:1 dark. The Call next dialog uses `bg-popover` with a full-strength border in dark (`dark:border-border`) instead of the hard-coded `dark:bg-slate-900`; the same change was asked of Staff Experience for the shared `OperationalConfirmDialog` so the two dialogs stay identical. Frontend classes only | #316 |
| Q-2c | Treatment progress: the next step can start while a finished one still waits for the doctor (owner decision 2026-11-06). The finished step keeps its doctor pop-up and "done" count; at most one step is in progress per patient; Resume or opening the consultation clears every waiting step together; leaving Serving closes them all. The board and consultation chip show the main step (in progress, else the latest finished) and up to 2 more finished steps as extra lines in the same chip (then "+N more done"), with "Ready to resume" once, on the last line; one screen-reader sentence (Yasmin's sketch). On a finished step the button reads "Start next step" (was "Cancel step…"); Cancel stays under "Entered in error?". The doctor's done count counts patients, not steps. Migration `2026_11_08_000400_allow_next_treatment_step_while_done_waits` changes the PostgreSQL rule from one open step to one started step per queue entry. No permission change | #329 |
| DD-08 (Queue & TV part) | Queue dark-mode leftovers: the unused `components/queue/QueueRows.vue` (nothing imported it; its pale palette was one of the DD-08 candidates) is removed; Cancel Visit in the board row menu uses the shared menu's destructive variant instead of a fixed `text-red-700` (dark 3.06:1 before, 5.24:1 now, 4.31:1 on the focused dark row, the shared menu's own style); the "My room today" notice on the Queue board uses the warning tokens (5.89:1 light, 12.56:1 dark). Frontend classes only | #336 |
| Q-6 | Two time-dependent Queue test flakes fixed (B.E.R.N.I.S.S for the owner, 2026-11-06), test setup only: `PublicQueueInsightTest` now builds its sample times from one clock reading (a second ticking over between `now()` calls made the wait 20 min 1 s and the upper bound 53), and five tests that move the clock after setting up "today" (`QueueDisplayTest` call again, `ShortNameModeTest`, three `RoomCallTest` cooldown tests) start at 10:00 on the branch-local date, so a jump never crosses the branch's midnight. Proven by anchoring at 23:59:50, which reproduced CI's "has not chosen a consultation room for today". Lesson: build test times from the branch time zone and one clock reading | #368 |
| Q-7 | Staff screens and staff-facing messages in English (owner decision 2026-11-10, via B.E.R.N.I.S.S; supersedes the older "Panggil semula stays" decision). Consultation page: "Call again", "Call to treatment room", "Treatment room" placeholder and three error messages; Dispensary "Call"; the QR intake list page (now only reached by redirect to the Registration board's QR Intake tab) and its menu entries ("QR Intake"); sidebar "Branch QR"; the old-QR notice on the QR links page; the TV settings examples; the HC-01 fourth-hold message and the doctor-busy message; and the TV fallback room words (Counter, Pharmacy, Treatment room) in `lib/queue-display.ts`, which a Malay-voice TV now also speaks in English. Kept in Malay: the public QR form and status pages, careers form, MC, referral and attendance wording, website drafts, the drug label, the TV's optional Malay spoken digits, docs/guides/ms. Guard: `tests/Frontend/q-7-staff-english.test.mjs`. Test lines changed with the owner's approval: the `Panggil semula`/`Ke bilik rawatan`/`Panggil` label checks, the `Sila rotate sekali` check, the `3 pesakit On Hold` and doctor-busy messages, and the `Kaunter` spoken fallback | #TBD |

## 6. Owner decisions already made (never ask again)

1. The TV shows called numbers, rooms and times for its own branch only, never patient, clinical or financial
   data; name mode (full names) is a per-branch manager choice and a privacy risk to review before go-live (2026-10-26).
2. The TV screen is English (2026-10-07); "Panggil semula" stays on the consultation page (superseded by decision 20: staff screens are English).
3. At most three held consultations per doctor; one active consultation per doctor (2026-10-03).
4. The not-ready flag: controlled reasons, no free text, CA and CA Supervisor set and clear, doctor overrides
   with a recorded reason, English wording (2026-10-27).
5. Boards poll; no websocket service without approval.
6. The backlog in section 9 was chosen by the owner (2026-11-05).
7. A "ship it" or decision relayed by LUCY counts as the owner's own (owner rule 2026-11-05).
8. Q-1 waiting-time limits: amber from 30 minutes, red from 60 minutes, fixed for every branch (no setting) (2026-11-05).
9. Every builder team has a UI/UX designer under Lead Designer Daniel; ours is Yasmin. The waiting-room TV look is
   owner-approved and stays as it is (2026-11-05).
10. Q-2 treatment progress (2026-11-05):
    - Steps: Nebuliser, Wound dressing, Injection and observation, IV drip, Observation after medicine, ECG,
      Blood test (point-of-care), Other procedure. Each step has started and done.
    - CA and CA Supervisor (branch) and the attending Resident Doctor (own patient) mark steps. New permissions
      `queue.treatment_progress.set.branch` and `.set.own` are approved.
    - "Ready to resume" clears on Resume or when the doctor opens that consultation.
    - Defaults taken: pop-up to the attending doctor only; no overdue warning and no Registration chip in v1.
    - The TV shows nothing.
    - Design note: [Q-2-DESIGN.md](Q-2-DESIGN.md).
11. Backup push (owner rule 2026-11-05, relayed by LUCY): whenever the team stops to wait for the owner and has work
    not yet on GitHub, commit it to its own feature branch and `git push -u origin <branch>`. Backup only: no PR, never
    push to `main` or another team's branch, never merge, never force-push. If the branch already has an open PR, push
    only when the change is ready (it re-runs CI). The PR still opens only after "ship it", with latest `origin/main`
    merged first. On a fresh machine, fetch the branch before continuing.
12. Q-2b column widths (2026-11-05): Status 15%, Payment 18% on the shared board (Daniel's recommendation, agreed with
    Nurul for Patients & Registration). The owner approved the one existing assertion change in
    `tests/Frontend/panel-note.test.mjs` (`w-[24%]` to `w-[18%]`).
13. Q-3 short name (2026-11-05): show the queue number and a short name (the first two words of the registered name;
    only the first word if both together are over 24 characters), skipping bin, binti, bt, bte, a/l, a/p, s/o, d/o and
    "@". It is a per-branch choice like full-name mode; number mode stays the default. The coordinator session is now
    called B.E.R.N.I.S.S (formerly LUCY); earlier references to LUCY mean the same session.
14. Q-4 Call next (2026-11-05): the doctor's own queue, and the branch board for `queue.call.branch` holders once a
    doctor is chosen; today first, then carry-over; skip not-ready entries; confirm a named patient first and refuse a
    stale one. Carry-over can be called when everyone waiting today is marked not ready (owner, 2026-11-05). The
    ordinary `queue.called` audit row is kept; it does not say whether Call next or Call In was used.
15. Q-3b (2026-11-05): keep the number and the short name on one line for 720p (owner, after Daniel's review).
    Larger TVs and phones keep the Q-3 look and no backend changes (B.E.R.N.I.S.S for the owner, 2026-11-05; the owner
    can review it). Number 14 is Q-4.
16. DD-30 (2026-11-06): fix the Queue board tabs on phones with Yasmin's option A (live line under the tab strip,
    44 px tabs), after Daniel's look (B.E.R.N.I.S.S for the owner, 2026-11-06; the owner can review it).
17. Q-4b (2026-11-06, owner): Call next on the consultation page only while that consultation is held, for the
    doctor's own queue; after a call the doctor goes to the Queue board. The owner approved changing the one Q-4
    assertion that said the consultation page has no Call next control.
18. Q-4c (2026-11-06, owner): mark Call next on the `queue.called` audit row, with where it was pressed (Queue board
    or held consultation), and show it on the Audit log page. This replaces the last sentence of decision 14 for
    calls made after Q-4c; an ordinary Call In row is unchanged.
19. Q-2c (2026-11-06, owner): a finished treatment step keeps waiting for the doctor, and the next step can start
    beside it; Resume or opening the consultation clears every waiting step. The owner approved changing the one Q-2
    assertion that pinned the finished-step button label ("Cancel step…" becomes "Start next step").
20. Q-7 (2026-11-10, owner, via B.E.R.N.I.S.S): staff screens and the messages staff see are English. "Panggil semula" and "Ke bilik rawatan" are now "Call again" and "Call to treatment room". Patient-facing and document wording stays as it is (the public QR form and status pages, careers form, MC, referral and attendance wording, website drafts, the drug label, the TV's optional Malay spoken digits, docs/guides/ms). The TV fallback room words are English too.

## 7. Open items and known limits

- TV colour scheme (from Staff Experience's UX-8, 2026-11-06): the TV screen's CSS `color-scheme` follows the TV account's appearance setting, not the screen's own light/dark button. Nothing visible changes today because the TV has no form controls. If wanted, add `:style="{ colorScheme: theme }"` on the queue-display root. Optional.
- Q-2c (Mira): Cancel (entered in error) acts on the main step only, so an older finished step that is still waiting can be cancelled only after the newer one clears. The start refusal still reads "already has an open treatment step" (an existing assertion pins it); "a step in progress" would now be more accurate, an owner-approved wording change if wanted. No two-process PostgreSQL race test for two simultaneous starts; the `treatment_step_one_started` index enforces it.
- DD-30 sets the 44 px phone tab height from the Queue page (`max-sm:[&>button]:h-11`), reaching into the shared `OperationalTabs` buttons; if that component changes its buttons the height quietly stops applying. Yasmin suggests a phone-height option in `OperationalTabs` (Staff Experience) so other boards can use it, then removing the override. A tapped tab can keep a faint hover background on touch screens (existed before DD-30).
- Sound, real fullscreen and staying signed in overnight are verified on a real TV only by the owner's report (2026-10-13).
- Name mode shows full names to the waiting room (privacy review before go-live). Short-name mode (Q-3) shows and speaks part of the name, which is still identifying in a small waiting room; it is included in the same privacy review. The review pack, with the three modes side by side, is [TV-PRIVACY-PACK.md](TV-PRIVACY-PACK.md) (2026-11-06). In short-name mode at 1280 x 720 the current call is one line at about 38 px (Q-3b) and about two earlier calls fit in the list (whole rows only); an extreme 24-character pair wraps to two lines at 24 px; a long one-word name ends in "…" in the list.
- Q-1 behaviour: colours show on every Registration tab that lists waiting rows (All and Waiting), including OTC B-number rows waiting for the dispensary (counted from the OTC `queued_at`). A previous-day carry-over row counts from its original `queued_at`, so it is red at once. Held, serving, removed and cancelled rows are never coloured. The Queue board recomputes on each 3-second poll and at least once a minute; the Registration board on its 10-second poll; when polling backs off or the tab is hidden, a colour can change up to about a minute late. The TV and public QR pages are unchanged.

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only; no real names or phone numbers.
- The TV feed never carries patient identity except the existing name mode; call records stay free of patient data.
- Every state change: backend permission and branch scope, `DB::transaction`, row locks, `lock_version`,
  idempotency where retried, and a structural audit row with no identity or clinical text.
- Never widen who sees patient data without an owner decision in section 6.
- Do not change any existing test assertion without the owner's approval; list each one first.
- Full gate before hand-off, on a task-owned PostgreSQL 18 database (verify host, port and name first): backend
  tests, Pint, PHPStan, frontend types, lint, format, build, frontend tests, `migrate:fresh --seed`,
  `git diff --check`; screenshots at 1440 and 390 px, light and dark, for any screen change.
- PHPStan is not installed by the session hook yet. Stopgap: `git clone -q --depth 1 --branch 2.2.8
  https://github.com/phpstan/phpstan.git /tmp/phpstan-src && mkdir -p vendor/phpstan/phpstan && cp -r
  /tmp/phpstan-src/{phpstan,phpstan.phar,bootstrap.php,conf} vendor/phpstan/phpstan/`, then
  `kphp -d memory_limit=2G vendor/phpstan/phpstan/phpstan analyse --memory-limit=2G --no-progress <changed files>`.
  About 25 "str_starts_with expects non-empty-string" errors are known local noise; fix anything else.
- Independent review by the Reviewer (a subagent given the diff, this file and the rules, not the Lead's
  conclusions) before hand-off.
- Commit, push and PR only after the owner says "ship it"; never merge.
- Design (owner rule 2026-11-05): for any screen change, run Yasmin as a subagent before building, to sketch the
  layout (components, 390 px behaviour, empty, loading and error states, wording) from existing patterns and
  `docs/design/GUIDE.md` once merged. Before hand-off, Yasmin checks the 390 and 1440 px light and dark screenshots
  against `docs/design/CHECKLIST.md`. A new page or layout change also goes to Daniel for a look through LUCY. The
  TV look stays as approved.
- A "ship it" or owner decision relayed by LUCY (the coordinator session) counts as the owner's own (owner rule 2026-11-05); stop and ask if it conflicts with something the owner told you directly.
- Never edit `docs/BASELINE.md`; record finished pieces here and flag any authorisation change in the PR description. The coordinator updates BASELINE in one docs PR per batch (owner rule 2026-11-05).
- Migrations: pick a timestamp no other open PR uses (ask LUCY); today's taken slots include 2026_11_05_200000 to 240000 and 2026_11_06_0001xx.
- Record every new owner decision in section 6 and every finished piece in section 5, in the same PR.

## 9. Backlog (next pieces, in order)

Chosen by the owner on 2026-11-05; built one by one in this order, each its own PR. Choosing them is the owner's
authorisation to build them; the coordinator records it in `docs/BASELINE.md`.

1. **Q-1 Waiting time on the boards.** Each waiting patient on the Queue board (and, by a small agreed change
   with the Patients & Registration team, the Registration Waiting tab) shows how long they have waited since
   they joined the queue, turning amber then red past set limits, so forgotten patients stand out. Display only,
   from existing timestamps; propose the two limits (for example 30 and 60 minutes) to the owner, or make them
   a branch setting if the owner prefers. No change to queue order.
2. **Q-2 Treatment progress.** A doctor or CA marks a treatment step on a patient (for example "Nebuliser
   started" / "Nebuliser done"), shown on the board with an alert when done (owner's parked staff-communication
   idea (b), BASELINE section 6 item 12). Write a short design note first (controlled step list, who sets and
   clears, append-only evidence, what the TV shows: nothing) and show the owner before building.
3. **Q-3 Gentler TV name mode.** Add "number and short name" (the first two words of the name, see decision 13)
   beside "number" and "full name" in the branch call display mode, used on the TV list, current call and spoken
   call. Safer for waiting-room privacy. Built in #274.
4. **Q-4 Call next.** One button for the doctor (and on the branch board for `queue.call.branch` holders) that
   calls the next eligible patient in the existing order (Urgent first), skipping entries with an active
   not-ready flag; same Call In rules, room requirement and audit as today. Built in #282 (decision 14).
