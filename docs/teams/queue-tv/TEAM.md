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

All are Claude sessions working for the owner through the coordinator session (LUCY). Names chosen by the
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
| Q-2 | Treatment progress: CA, CA Supervisor or the attending doctor mark a controlled step (8 steps) started then done on a Serving consultation; blue/green chip on the Queue board and consultation page; attending-doctor pop-up and count; cleared by Resume or opening the consultation; append-only evidence (`app/Domain/Queue/Treatment/`, migrations `2026_11_08_000100` and `000200`); TV shows nothing | this PR |

## 6. Owner decisions already made (never ask again)

1. The TV shows called numbers, rooms and times for its own branch only, never patient, clinical or financial
   data; name mode (full names) is a per-branch manager choice and a privacy risk to review before go-live (2026-10-26).
2. The TV screen is English (2026-10-07); "Panggil semula" stays on the consultation page.
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

## 7. Open items and known limits

- Sound, real fullscreen and staying signed in overnight are verified on a real TV only by the owner's report (2026-10-13).
- Name mode shows full names to the waiting room (privacy review before go-live).
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
3. **Q-3 Gentler TV name mode.** Add "first name + initial" beside "number" and "full name" in the branch call
   display mode, used on the TV list, current call and spoken call. Safer for waiting-room privacy.
4. **Q-4 Call next.** One button for the doctor (and on the branch board for `queue.call.branch` holders) that
   calls the next eligible patient in the existing order (Urgent first), skipping entries with an active
   not-ready flag; same Call In rules, room requirement and audit as today.
