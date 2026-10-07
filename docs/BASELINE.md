# KPOne Baseline

This file is the authoritative delivery status and supersedes the overview in `PROJECT.md`. Merged milestones
below reflect the latest recorded `main` baseline, through PR #67 (QR intake review redirect fix). Every row marked merged is
merged only; none is production-approved.
For `main`'s current tip, run `git log -1` — a line here recording it goes stale the instant it is committed.

Yezza remains the operational source of truth. Nothing below is production-approved; all data is synthetic.

## 1. Delivery status

| Phase / slice | Scope | Status |
|---|---|---|
| 0A | Organisation, 3 branches (CHERAS, SUNGAI_BESI, PUCHONG), 9 departments, RBAC, auth, audit, shell | Merged |
| 0B | Staff provisioning, identity admin, branch assignments | Merged |
| 1A | Patient Master (KP-number, NRIC/passport history, masked search) | Merged |
| 1B | Patient Registration → Visit | Merged |
| 1C | Consultation Queue & CA console | Merged |
| 2A | Clinical Encounter (vitals, note, diagnoses) | Merged |
| 2B.0 | Allergy profile & Problem list | Merged |
| 2B | Treatment Plan & medicine/service orders | Merged |
| 2C | Operational shell & clinical workspace | Merged |
| 3A-Core | Dispensary handoff & minimal inventory | Merged (PR #5) |
| 3B | Completed Patient v1: checkout → dispensary → billing → complete visitation | Merged (PR #6) |
| R1A / R1B | Registration validation, visit-reason catalogue | Merged (PR #7, #8) |
| R1C1–R1C5 | Global UX, registration/consultation, billing/panel/finance UI, main menu, responsive/a11y | Merged (PR #9–#13) |
| P1A | Queue performance | Merged (PR #14) |
| UI polish | Dropdown/dark mode, login confirmation, clinical confirmation | Merged (PR #15–#17) |
| Q1-B1 | Public check-in link foundation (QR link issue/rotate/revoke) | Merged (PR #18) |
| M1-B1 | Medicine catalogue governance | Merged (PR #19) |
| I1A / I1B | Inventory reference governance, inventory demo workspace | Merged (PR #20, #21) |
| P0 | Pricing reference governance | Merged (PR #22) |
| CI1 | Exact-SHA validation dispatch | Merged (PR #23) |
| I1C | Payment method governance & visit-completion safety | Merged (PR #24) |
| I2 | Inventory operations demo v1 + 4 remediation PRs | Merged (PR #25–#29) |
| Q1-B2-D3 | One authorisation, one branch (`fix/q1-b2-d3-registration-qr-hold`): (a) registration-integrated QR intake — public form → Pending Intake → CA review in Registration → Patient/Visit/Queue; (b) visit-reason picker fix; (c) Consultation On Hold / Resume (one active consultation per doctor) | Merged in PR #30 |
| Q1-B2-D3-R1 | Remediation of smoke + review findings: mojibake, permission rollout by migration, retention scheduling, privacy-notice correction fix, QR list performance, mobile cards, shared disclosure component, On Hold visibility, small defects, release notes | Merged in PR #30 |
| Q1-B2-D3 UAT | Human UAT, 43 cases, 2026-09-23 (Haziq): 41 pass, 1 skip (QR-02, not applicable), 1 fail (OH-06 clinical note not shown after Hold/Resume) | Done; OH-06 fixed, short re-test pending |
| Merge with `main` | `main` merged into the branch (`6960453`), sole conflict `resources/js/app.ts` resolved in favour of `readInitialInertiaPage()` from `main`; frontend bootstrap test updated. PostgreSQL 18 validation of `6960453`: 649 tests, 647 passed, 2 expected Fortify skips, 0 failures, 7,390 assertions, no contention test skipped. Preview refreshed to this SHA by migrate alone | Merged in PR #30 |
| OH-06c / OH-06d | UAT follow-ups: unsaved-work guard before Hold (Save and hold / Discard and hold / Cancel, reusing the operational confirm dialog, also saving a dirty treatment-plan draft), and Hold/Resume navigation fixed at the controller — `to_route('encounters.show')` instead of `back()`, including the blocked-Resume path via `redirectTo()` plus an error toast, so the doctor stays on the consultation | Merged in PR #30 |
| CI parity fix | `ac462bf`: the test suite no longer depends on ambient environment. CI copies `.env.example`, whose `PUBLIC_PATIENT_INTAKE_ENABLED=false` beat PHPUnit's `<env force>` because Laravel's `env()` reads `$_SERVER` first — fixed by pairing `<env>` with `<server>` in `phpunit.xml`. `.env.example` keeps `false` as the correct production default | Merged |
| **Q1-B2-D3 shipped** | PR #30 merged into `main` as `8ef519b` (merge commit, no squash). Post-merge CI green. Branch `fix/q1-b2-d3-registration-qr-hold` kept | **Merged 2026-09-24** |
| OH-06 / OH-06b | Inertia `preserveState` left `useForm` state stale after Hold/Resume. Data was never lost in the database; the form was not re-synced. Fixed in `Clinical/Show.vue` (`e796620`) and `TreatmentPlanPanel.vue` (`a376069`): clean form adopts content and lock version together, dirty form is never overwritten and shows a drift banner | Merged in PR #30 |
| **RB-01 / RB-02 shipped** | Registration board: stale rows after a failed or thrown search cleared; each visit's own registered date projected. PR #31 merged into `main` as `bc29c28` (merge commit). Post-merge CI green | **Merged 2026-09-24** |
| TH-01 | PostgreSQL contention harness: replaced ~30 hardcoded worker deadlines/timeouts across 10 `Postgres*RegressionTest.php` files with `Tests\Support\ContentionTimeouts` (overridable via `KPONE_CONTENTION_READY_TIMEOUT`/`KPONE_CONTENTION_PROTOCOL_TIMEOUT`). No assertion or contention protocol step changed | Merged PR #33 as `e8751b6` (merge commit); post-merge CI green; branch kept |
| AC-01 | Staff authority: no role could provision or administer a `ca_supervisor`. Administrative authority changed from a `.manage.` substring match to a declared set (staff, branches, access); `canAssignRoles` untouched. Found by human walkthrough of UI-1 at step 2 | Merged PR #34 as `ac9a0e5` (merge commit); post-merge CI green; branch kept |
| **UI-1 shipped** | Reference Data UI so the patient journey is walkable by a person: Medicine Catalogue and Clinical Service Catalogue screens (the latter backed by a new `ClinicalServiceCatalogueAdministrationService`, mirroring `MedicineAdministrationService`, and one new additive permission `clinical_services.manage.organisation`), Pricing (Charge Definition, Price Book, versioned publish), and thin stock-setup screens (Inventory Item/SKU/Location/Batch/medicine↔SKU mapping, plus an Opening Balance form) over the existing, previously UI-less Inventory Reference and Movement routes. Frontend and thin-controller work only; the one schema-adjacent change is the authorised permission migration. PR #36 merged into `main` as `0a26449` (merge commit, no squash) | **Merged 2026-09-28** |
| **DOB-01 shipped** | `PublicIntakeReviewService::ageOrNull()` parsed a stored date of birth with the lenient `Carbon::parse()`, so text such as `'2023'` (read as the clock time 20:23) produced an age of 0 for the last 3 h 37 min of every UTC day. Now parsed strictly as `Y-m-d`; anything else is unavailable. Frozen-clock regression test added. PR #35 merged into `main` as `da3c66a` (merge commit, no squash). Post-merge CI green. Branch `fix/dob-01-strict-date-parse` kept | **Merged 2026-09-28** |
| **PX-01 shipped** | Supervisor pricing: `ca_supervisor` granted `pricing.references.manage.organisation` and `prices.publish.organisation` (owner decision 2026-09-27, reversing an earlier separation of duties); additive migration; two existing assertions changed to encode the new rule. PR #38 merged into `main` as `c5e96a5` (merge commit, no squash) | **Merged 2026-09-28** |
| **PRICE-01 shipped** | Charge definitions could not be created through the real UI for any type: `PricingChargeStoreRequest` validated `medicine_public_id`/`service_public_id` with `uuid` but no `nullable`, so the id irrelevant to the selected type (always sent by `chargeForm`) failed silently; `Pricing/Index.vue` bound no `InputError` to those fields or to the synthetic `scope` key from the one-Price-Book-per-scope rule. Fixed with `nullable`, a payload transform sending only the relevant id, and the missing `InputError` bindings. Human UAT passed in the browser 2026-09-27: charge created, RM 30.00 published, draft invoice on KPV-00000002 built at RM 65.00 across consultation, service and medicine lines. PR #39 merged into `main` as `4381e94` (merge commit, no squash) | **Merged 2026-09-28** |
| **NAV-01 shipped** | Reference-data and operational Inventory forms redirected via bare `back()`, which for an Inertia SPA resolves to the last *full page* GET the session recorded, not the page the request came from — the owner hit it at step 12 of the UI-1 walkthrough (creating a Medicine sent her to Pricing). Recurrence of OH-06d. Fixed with explicit `to_route('inventory.index')`, both for success and for a caught validation failure, across 30 actions in 3 controllers. PR #40 merged into `main` as `9a4aabb` (merge commit, no squash) | **Merged 2026-09-28** |
| **BS-01 shipped** | Adds `DirectorBootstrapService` for one-time, actorless creation of the first Director from existing organisation, department, branch, and role records; creates a permanent primary-branch assignment and writes an audit event. The service is not exposed through a UI route or Artisan command; `KPOneDevelopmentSeeder` remains a local/testing technical-admin bootstrap. PR #42 is present in this worktree at merge commit `4f5506f` | **Merged** |
| **Insights shipped** | Read-only aggregate reports for Today, Sales, In-clinic, Payments, Inventory, and Patients. All staff roles receive the explicit organisation-scoped view permission. Supports branch/doctor filters where available, date presets/custom calendar, comparisons, charts, and per-table ranking search. No appointments, exports, package billing, patient drill-down, or patient-level debt ranking | **Merged in PR #43 as `a08d099`. Exact merged candidate `f1dac42` passed PostgreSQL 18.6 (743 tests, 741 passed, 2 expected Fortify skips, 0 failures, 8,747 assertions), fresh migration/seeding, and post-merge CI. Owner completed a fresh-database walkthrough with a completed synthetic visit and non-zero data across all six reports on 2026-10-02 and confirmed results. Not production-approved.** |
| **Unified Catalogue Setup shipped** | One Medicine setup flow creates or reuses its linked Inventory Item/SKU, supports default and per-Panel tariffs, and optionally records a batch, branch opening stock, location, supplier, and purchase unit cost. Clinical Services receive categories and the same tariff tiers. The Clinical Service Catalogue manages the dedicated Consultation tariff consumed by Billing, while Pricing routes and governed records remain available. Descriptive dropdown values persist; governed Panel, supplier, and location can be added inline. Purchase orders retain estimated unit cost and goods receipts retain actual unit cost. Existing records are reused; no historical stock or catalogue backfill is performed. Catalogue lists show Self-pay and default Panel prices | **Merged in PR #43 as `a08d099`. Merged candidate `f1dac42` passed PostgreSQL 18.6, frontend checks, and post-merge CI. Owner confirmed catalogue editing, stock setup, compact tables, and the fresh-database end-to-end synthetic UI walkthrough on 2026-10-02. Not production-approved.** |
| **BS-02 (authorised 2026-10-02)** | Controlled interactive Artisan invocation of `DirectorBootstrapService` for the first Director, with hidden optional password input and verified-Google-email guidance; no unauthenticated web setup route | **Merged in PR #45 as `99c5c93` (implementation commit `eea8340`); post-merge CI green. Fresh-database synthetic UI acceptance completed 2026-10-02; final independent review of the combined commit found no significant issues. Do not weaken staff-authority rules.** |
| **Manual terminal reconciliation (authorised in current session)** | Branch-scoped manual daily close compares terminal-reported approved sales count/gross total with already-posted KPOne receipts by local business date and payment method. Captures reported refund/void figures, requires explanations for variances, retains immutable revisions, and is restricted to Director, Finance Officer, CA, and CA Supervisor. It does not integrate with a terminal, create payments, issue refunds, or confirm settlement | **Merged in PR #45 as `99c5c93` (implementation commit `eea8340`); post-merge CI green. Covered by the full PostgreSQL 18.6 candidate suite (763 tests, 761 passed, 2 expected Fortify skips, 0 failures, 9,031 assertions) and feature tests. Independent review of the combined commit found no significant issues. Owner browser UAT passed on 2026-10-05 with synthetic data on a local acceptance database; not production-approved.** |
| **QR coverage handoff** | Public QR intake lets a patient report self-pay or select an active Panel and optionally provide a member reference. Patient-supplied values remain encrypted in Pending Intake; CA corrections remain unverified until explicit coverage confirmation. Only CA-selected coverage and independently verified values are projected to the Visit; no Patient, Visit, or Queue exists before acceptance | **Merged in PR #45 as `99c5c93` (implementation commit `eea8340`); post-merge CI green. Synthetic browser UAT on 2026-10-02 covered self-pay and Panel acceptance, confirmed patient-reported references are not copied as verified Visit data, and confirmed no Patient/Visit/Queue is created before acceptance. Full PostgreSQL 18.6 candidate suite passed (763 tests, 761 passed, 2 expected skips, 0 failures). Independent review found no significant issues. Not production-approved.** |
| **BP-01 (authorised 2026-10-02)** | CA Panel approval within Director-configured branch limits; same-actor approval allowed within configured limits; audited Director-only limit administration | **Merged in PR #45 as `99c5c93` (implementation commit `eea8340`); post-merge CI green. Agent-run synthetic browser UAT on 2026-10-03 covered setting and clearing a limit, the Director-only access boundary, approval of the RM55 Panel responsibility on KPV-00000003, and completion with RM0 due and no cash receipt. PostgreSQL 18.6: 763 tests, 761 passed, 2 expected skips, 0 failures, 9,031 assertions; fresh migration/seeding and quality checks passed. Independent review found no significant issues. Owner UAT is deferred at the owner's direction; not production-approved.** |
| **Payment Method setup screen** | Audited, permissioned administration screen (`PaymentMethodController`, `PaymentMethods/Index.vue`) over the payment methods governed since I1C | **Merged in PR #45 as `99c5c93` (implementation commit `eea8340`); feature-tested and covered by the same PostgreSQL 18.6 candidate suite; post-merge CI green. No dedicated owner UAT recorded; not production-approved.** |
| **HC-01 (authorised)** | A doctor may have at most three active held consultations. A fourth manual or automatic hold is refused with a Bahasa Melayu validation message; the queue board flags a patient held for more than 30 minutes | **Merged in PR #44 as `c566530` (implementation commit `dfee85e`); post-merge CI green. Pre-merge full PostgreSQL 18.6 suite: 746 tests, 744 passed, 2 expected Fortify skips, 0 failures, 8,753 assertions. Independent review completed with no actionable findings. Owner UAT passed on 2026-10-05 (fourth hold refused). Not production-approved.** |
| **QR status Phase A** | Accepted-intake status page polls every 10 seconds and shows, only after CA acceptance, the patient's first name (first word of the submitted name, max 40 characters), queue number and queue state; progress stepper; call-in banner when the queue entry is called; opt-in browser chime (Web Audio) and vibration. No new public data before acceptance; no messaging, SMS or push notification | **Merged in PR #46 as `dae30a1` (commit `988d732`); post-merge CI green. Owner saw the call-in alert in the laptop preview on 2026-10-05 (synthetic data). Not production-approved.** |
| **QR status Phase B** | `PublicQueueInsightService`: patients ahead (same branch and operational day, Waiting, queued earlier); a labelled wait range (average queued-to-called time over the last 14 days, needs at least 5 samples, multiplied by patients ahead, widened to 70–130%; otherwise unavailable); aggregate Waiting counts per active branch; branch public address and map link. Optional nearby-branch sorting: browser geolocation only after a user action, straight-line distance computed in the browser, coordinates never sent to KPOne, persisted or sent to a geolocation service. Choosing another branch does not transfer the registration or queue. Additive migration `2026_10_05_000100_add_public_location_to_branches` (nullable address, map URL, latitude, longitude) populates the three branches' owner-supplied public details, mirrored in `KPOneReferenceSeeder` | **Merged in PR #47 as `92decc3` (commits `b214cf9`, `88f75e0`, `40a9f39`); PR CI and post-merge CI green. Previewed on the laptop; no explicit owner confirmation of the browser GPS permission flow is recorded. Agent-run UAT on 2026-10-05 (headless Chromium at 390 px, disposable PostgreSQL 18, synthetic data, a made-up test location near Puchong; blocked permission simulated): 10 of 11 GPS cases passed first time; G11 (status page 624 px wide on a 390 px phone) failed and was fixed in PR #52, then re-checked. Owner browser UAT outstanding. Not production-approved.** |
| **QR intake form polish (Phase C)** | Public QR intake form: per-step client-side checks mirroring `PublicIntakePayloadValidator` (required fields, length limits, strict non-future date of birth, minor-requires-guardian, Panel requires a panel) before moving to the next step; every guardian field shows its own error; a read-only review summary with per-row edit links before consent; a visible 15-minute session countdown with a 3-minute warning. An elapsed client clock warns but never blocks submission; the server remains the only validation authority. No draft or patient data is kept in browser storage. Frontend only (`resources/js/lib/public-intake-form.ts`, `PublicCheckIn/Show.vue`) | **Merged in PR #49 as `aba4ab9` (commit `bf75bb0`); PR CI and post-merge CI green on PostgreSQL 18. Local frontend tests 198, 197 passed; the one failure (`i2-inventory-operations` #19, missing Wayfinder-generated routes) fails identically on `main` in the same environment. PHP suite, Pint, PHPStan and `npm run build` ran only in CI. Agent-run UAT on 2026-10-05 (headless Chromium at 390 px, disposable PostgreSQL 18, synthetic data): 16 of 16 cases passed; the session-expiry cases moved only the browser clock. Owner browser UAT outstanding; not production-approved.** |
| **QR status page overflow fix** | On a 390 px phone the public status page was 624 px wide and scrolled sideways: the centred grid sized its column to the full width of the truncated branch address. `Status.vue` now gives the grid a shrinkable `minmax(0, 1fr)` column (`grid-cols-1`) and the card `min-w-0`. Frontend only; found by agent-run UAT case G11 | **Merged in PR #52 as `36c6078` (commit `d121f05`); PR CI green. Regression test fails on the old page and passes on the fix; frontend tests 199 of 199; re-checked in the browser at 320, 390 and 768 px with no sideways scrolling. Not production-approved.** |
| **Cloud session start hook (tooling)** | `.claude/hooks/session-start.sh`, registered under `SessionStart` in `.claude/settings.json`, prepares Claude Code cloud sessions only (`CLAUDE_CODE_REMOTE=true`; local Herd sessions are unaffected). It starts Docker, pulls `serversideup/php:8.4-cli` and `postgres:18`, installs a `kphp` wrapper for PHP 8.4 (the cloud container has 8.3), installs Composer packages from git sources at their locked commits (the proxy blocks GitHub archive downloads), runs `npm ci` and generates Wayfinder routes. It never creates or reads a real `.env`. PHPStan is not installed there (archive-only package); CI still runs it. No application, migration or test change | **Merged in PR #54 as `c93af4a` (commit `373e2a5`); PR CI green. Validated in a cloud session from a clean state: exit 0 in about 5¾ minutes with 144 Composer packages, re-run 1.2 s; Pint, ESLint, Prettier, one PHPUnit file (2 tests) and one frontend test file (10/10) pass. Development tooling only; no production effect.** |
| **TV-1 queue display (authorised 2026-10-05)** | Branch waiting-room TV, slice 1 (consultation calls). Branch rooms (consultation, dispensary, treatment) managed per branch; each doctor chooses a consultation room per day in the Consultation queue (`queue.room.select.own`). Every consultation Call In writes an immutable `queue_calls` record (number, room snapshot, time; no patient data). Once a branch has an active consultation room, Call In is refused until the doctor has chosen a room for the day (a branch without rooms still calls, and the TV says "counter"). "Call Again" on the queue board, or "Panggil semula" on the consultation page (which returns the doctor to the consultation), on a patient being served (not held) writes a recall record without changing the Queue entry, at most once per 20 seconds, audited as `queue.recalled`; the TV announces it again, labels it "Panggilan semula" and lists the number once. New `queue_display` role (`queue.display.branch` plus own-profile and branch basics, no Insights, no patient, queue-list or clinical access) signs in on any TV/cast browser at its branch and lands on a full-screen page: now calling, recent calls with room and time, branch clock and date, rotating posters, a muted looping YouTube video (stored as a validated video id, played from youtube-nocookie.com) and scrolling text, with a chime and a spoken announcement (Web Speech, the number one character at a time then the room: "P, zero, eight, two. Consultation Room 2."; digits in Malay — "kosong, lapan, dua" — when the TV has a Malay voice) and a screen wake lock. Polls every 5 seconds. TV accounts are always signed in with remember-me (password and Google), so the screen survives session expiry and overnight power-off; signing the TV account out on any device revokes that, and a lost sign-in is retried every 30 seconds. Sign-out on the TV asks for confirmation. Settings screen (`queue_display.manage.organisation` for `director`, `queue_display.manage.branch` for `ca_supervisor` at assigned branches): rooms, text, video, up to 8 posters stored on the private disk and served only to that branch's display or managers. Every change is permissioned, branch-scoped, transactional, versioned and audited. Additive migrations `2026_10_06_000100` (tables) and `2026_10_06_000200` (permissions and role). Dispensary and treatment-room calls are the next slice | **Merged in PR #57 as `3a32c1a` (commits `06f4422`, `c7775b5`); PR CI green on `c7775b5`; post-merge CI on `main` green. The first PR CI run failed on 12 PHPStan type errors in the new code; `c7775b5` fixed them with no behaviour change, verified locally first with the locked PHPStan 2.2.8. PostgreSQL 18.6 (disposable `kpone_tv1_test`, `06f4422`): 788 tests, 786 passed, 2 expected Fortify skips, 0 failures, 9,314 assertions, no contention test skipped. 20 new feature tests and 12 new frontend tests; frontend suite 211 of 211. Six PostgreSQL contention tests gained `queue_calls` in their cleanup (no assertion changed). No browser walkthrough yet, agent or owner. Not production-approved.** |
| **TV-2 dispensary and treatment-room calls (authorised 2026-10-06)** | Second slice of the waiting-room TV. Dispensary: staff holding `dispensary.start.branch` press "Panggil" on a waiting or dispensing case; with one active dispensary room it is used, with several a room must be chosen, with none the TV says "Farmasi". Treatment room: the attending doctor (from the consultation page, "Ke bilik rawatan") or staff with `queue.call.branch` (from the queue board, "Call to Treatment Room") call a patient being served and not held to an active treatment room. A call only announces: the Queue entry, consultation and dispensary case are unchanged, and the consultation stays with the doctor. Each call writes an immutable `queue_calls` record (`service` dispensary or treatment), at most once per 20 seconds per patient and service; a repeat is a recall. Audited as `queue.dispensary_called` / `queue.treatment_called`. The TV feed gains the call's `service` so a call without a room is labelled for its service; the recent list still shows each number once, at its latest call. `QueueCallRecorder` now holds the call record and cooldown for all three services. Additive migration `2026_10_07_000100` widens the PostgreSQL service check. No new permission or role | **Merged in PR #60 as `cf8574d` (commit `faf14d9`); PR CI green. PostgreSQL 18.6 (disposable `kpone_tv1_test`, `faf14d9`): 794 tests, 792 passed, 2 expected Fortify skips, 0 failures, 9,387 assertions, no contention test skipped. 6 new feature tests and 4 new frontend tests; frontend suite 215 of 215. Pint, ESLint, Prettier, vue-tsc, `npm run build` and `git diff --check` pass; PHPStan (locked 2.2.8, run locally) reports nothing in this change. One TV-1 test pinning the feed's exact fields now lists `service`, an intended new non-identifying field. No browser walkthrough yet. Not production-approved.** |
| **Queue display fullscreen, theme and branding (TV-1 follow-up)** | Presentation changes to the waiting-room TV screen (`QueueDisplay/Screen.vue`, `queue-display.ts`); no backend, permission, migration or data change. A separate Full screen button, so fullscreen can be re-entered after Escape; a light/dark theme button in the Klinik Putrijaya white/pink/black palette, labelled with the theme it switches to ("Dark mode" while light, "Light mode" while dark); Klinik Putrijaya logo; all static on-screen text and the date in English (the TV display is an English screen by owner decision, overriding the Bahasa Melayu default); the current call's room name wraps instead of being clipped. | **Merged in PR #62 as `8344037` (commits `91dc7ea`, `e43f063`, `f9ea8c8`; merge commit); PR CI green on all three commits. Frontend tests for the TV screen and TV-2 room calls pass. Agent-run headless walkthrough on 2026-10-07 (headless Chromium, disposable local PostgreSQL 16 rather than 18, synthetic `.test` data, one visit): TV sign-in and landing, a long room name wrapping to three lines at 1920 px, logo, theme toggle in both modes, no sideways scroll at 1280 and 390 px, still signed in after a reload, Call Again labelled Recall, and a treatment-room call shown on the TV while the Queue entry stayed Serving. **Not verified:** sound, chime and spoken voice; real fullscreen with Escape and re-entry on a real TV or browser (headless only); the dispensary call; staying signed in overnight. Owner walkthrough on a real branch TV outstanding. Not production-approved.** |
| **TV-3: earlier calls stay on the TV list (owner walkthrough fix)** | The TV list kept one row per patient, so a treatment or dispensary call replaced the patient's earlier consultation call and left no history. `QueueDisplayFeedService` now keeps one row per patient, service and room: a recall to the same room still shows once at its newest call, and a call to another room or service stays as an earlier row under the current call. Backend feed only; no migration, permission or data change, and the feed still carries no patient data | **Merged in PR #64 as `3c16156` (commit `17aeaab`); PR CI and post-merge CI green. One new `RoomCallTest` case; two existing `RoomCallTest` assertions that expected the old single row now expect two rows, newest first (a deliberate rule change, not a weakened test). Not production-approved.** |
| **TV-4: ding-dong call chime (owner choice)** | The three rising beeps are replaced by a soft falling E5-C5 two-note ding-dong (triangle wave, about 1.5 s) that fades before the spoken call, which starts 1.4 s after the chime. The owner chose it from three options auditioned on 2026-10-07. Frontend only (`QueueDisplay/Screen.vue`) | **Merged in PR #65 as `af0ee62` (commit `7a4a0f9`); PR CI and post-merge CI green. One frontend test pins the notes, wave and speech delay. Heard only through a laptop; real TV speakers and room volume are not confirmed. Not production-approved.** |
| **QUEUE-02: a refused Call In returns to the page it was pressed on** | Found in the 2026-10-07 owner walkthrough: Call In from the Registration board was refused (the branch had consultation rooms and the doctor had not chosen a room for the day), and the refusal redirected `back()`, which resolved to an unrelated Dispensary page. `QueueController::call` now catches the refusal and redirects to the board that sent it: Registration when `from=registration` is sent (the Registration board now sends it), otherwise the queue. `CallQueueEntryRequest` accepts the optional `from`. The refusal itself is unchanged and correct. Same bug class as OH-06d and NAV-01 | **Merged in PR #66 as `49a335e` (commit `51ece57`); PR CI and post-merge CI green. New `QueueDisplayTest` case with a stale `_previous` URL: fails without the fix, passes with it. Frontend suite 218 of 218. Not production-approved.** |
| **INTAKE-01: QR intake review actions stay on the review page** | Found in the 2026-10-07 owner walkthrough: Save corrections and a refused Accept sent the reviewer to `/check-in/status`, the patient's own status page, because the patient and staff pages shared one browser session and `back()` resolved to the last full page. `PublicIntakeReviewController` start, correct, correction-required, reject and accept now return to the intake's own review page on success and when refused; a successful accept or reject still goes to the QR intake tab. This fixes the three `back()` rows recorded for this controller in the NAV-01 table | **Merged in PR #67 as `c547578` (commit `223d438`); PR CI green; post-merge CI not yet recorded here. New `PublicPatientIntakeTest` case (stale `_previous` URL set to the status page): fails without the fix, passes with it; existing intake tests pass (1,405 assertions). Not production-approved.** |


End-to-end synthetic UI flow: on 2026-10-02 the owner completed a fresh-database walkthrough in one sitting:
Director bootstrap, synthetic staff and catalogue setup, branch stock, one synthetic visit through payment and
completion, then all six Insights reports with branch/date filters. The owner confirmed the results. This closes
the UI-1/BT-01 walkthrough gap for synthetic data only; it is not production approval.

## 2. Domain map (`app/Domain`)

Organisation (branches, public check-in links, Inventory) · Identity · Access (`PermissionCatalogue`, branch access) ·
Audit · Patient (Patient Master, public intake) · Visit · Queue · Clinical (encounter, allergy, treatment plan,
checkout, hold, Dispensary) · Shared.

## 3. Roles

`director`, `resident_doctor`, `ca`, `ca_supervisor`, `panel_officer`, `finance_officer`, `business_development`,
`marketing`, `hr_manager`, `technical_admin`, `queue_display` (shared waiting-room TV account; display only). Source of truth: `app/Domain/Access/PermissionCatalogue.php`
(+ `BillingPermissions`). Technical admin never gains clinical or patient-content access by implication.

## 4. Not authorised yet

Do not build or store data for these without an explicitly approved phase:

- Appointments; patient portal / patient login; WhatsApp, SMS, OTP, email to patients
- Procurement, receiving, stocktake, production stock migration
- Panel claims submission and advanced finance; HR workflows and staff roster
- Website integration; marketing modules; management analytics beyond the separately authorised Insights reports below
- Yezza integration or data migration
- Splitting Q1-B2-D3 into separate PRs — it ships as one authorised scope
- Production deployment and go-live (needs a security, privacy/PDPA and legal gate first)

### Insights report authorisation (owner decision, 2026-10-01)

The owner authorised aggregate-only reports for **Today**, **Sales**, **In-clinic**, **Payments**, **Inventory**,
and **Patients**. The dedicated `insights.view.organisation` permission is granted to every catalogue role.
Appointments, exports, package billing, patient drill-down, and patient-level financial rankings are outside the
approved scope. The implementation status, filter behavior, metric definitions, privacy limits, and unavailable
measures are maintained in [INSIGHTS.md](./INSIGHTS.md). The reports merged in PR #43 as `a08d099`; exact
candidate PostgreSQL 18.6 validation and post-merge CI passed. The owner completed the fresh-database browser
walkthrough with non-zero synthetic data on 2026-10-02 and confirmed results. This is not production approval.

## 5. Key decisions

- Modular monolith, one Laravel app, one PostgreSQL database. No microservices or queues/brokers without approval.
- Patients are organisation-level; Visits, Queue entries and Encounters are branch-owned.
- Public QR submissions only ever create a **Pending Intake**; a CA must review and accept before any Patient,
  Visit or Queue record exists.
- QR bearer token travels in the URL fragment (`/check-in#token`), is exchanged for httpOnly cookies, and is
  stored hashed (plus an encrypted copy for re-display of the active QR). Links expire after 90 days by default.
- Intake payloads are encrypted, retained 30 days, then purged by `public-intakes:cleanup`.
- The public QR status page shows identifying detail (first name, queue number) only after CA acceptance, and
  otherwise only aggregate, non-identifying queue figures and public branch details. Patient GPS is optional,
  requested only after a user action, used only in the browser, and never sent to KPOne or any external
  geolocation service, or persisted.
- Every mutation: backend permission + branch scope, transaction, row lock, `lock_version`, idempotency where
  retried, and an audit event without secrets.
- PostgreSQL 18 validation of an exact frozen SHA is mandatory before merge.

## 6. Open issues and decisions

Resolved in R1 (`a29a3ad`): mojibake; permission rollout now runs from a migration; `public-intakes:cleanup`
scheduled daily and unused intake sessions pruned; correction under a changed privacy-notice version;
QR list pagination and N+1; mobile card layout; shared disclosure component; On Hold button visibility;
dispensary UUID route, age guard, cookie `secure`; release notes in `docs/releases/Q1-B2-D3.md`.

Still open:

1. **Production release gate:** complete access review, recovery/restore proving, security review, privacy/PDPA
   assessment, and legal approval before any go-live. No delivered feature or synthetic UAT implies production
   approval.
2. Before enabling public intake in production, deliberately set `PUBLIC_PATIENT_INTAKE_ENABLED=true`, configure
   `PUBLIC_PATIENT_INTAKE_TRUSTED_PROXIES` in every deployed environment, run the scheduler, rotate and reprint
   every QR link, and replace the `uat-draft-2026-09-19-v1` privacy-notice version with a legally approved value.
   Existing QR links use the old URL format; no data backfill is planned.
3. **Known operational defects, not fixed:** hold/resume lock-order inversion (PostgreSQL retries it with
   `DB::transaction(..., 3)`); single-branch "doctor busy" check; `resident_doctor` hard-coded in Hold; and
   minors must supply their own mobile number. Assess and authorise each separately before changing behaviour.
4. **Feature-specific owner UAT:** terminal reconciliation owner UAT passed on 2026-10-05 (synthetic data, local acceptance database). BP-01 owner UAT is deferred
   at the owner's direction after agent-run synthetic UAT; HC-01 owner UAT passed on 2026-10-05 (fourth hold
   refused). QR status Phase A call-in alert was seen by the owner on 2026-10-05; the Phase B browser GPS
   permission and nearby-branch sorting flow has no explicit owner confirmation yet. The Payment Method setup
   screen has no dedicated owner UAT recorded. QR intake form polish (Phase C, PR #49) owner browser UAT is
   outstanding. Agent-run UAT of Phase C and the GPS flow on 2026-10-05 passed 27 of 27 cases after the PR #52
   fix; it is supporting evidence, not owner UAT. TV-1 queue display (PR #57) has had no browser walkthrough yet:
   the TV screen on a real TV or cast browser (sound tap, Malay/English voice, wake lock, staying signed in), the
   settings screen, the doctor's room choice and the Call In block, and Call Again / Panggil semula. TV-2 (PR #60)
   adds the dispensary Panggil, the treatment-room call from the consultation and the queue board, and their TV
   labels and announcements, also not yet walked. The fullscreen, theme and branding follow-up (PR #62) had only an agent-run
   headless walkthrough on 2026-10-07 (see section 1); real-device fullscreen, sound and voice remain outstanding. On 2026-10-07 the owner also ran the TV screen in Chrome on a Mac against a synthetic demo database (consultation call, Call Again, treatment-room call, theme button, long room name). The owner reported it worked well and that walkthrough found the defects fixed in PRs #64-#67. No explicit owner confirmation is recorded for spoken voice, real fullscreen with Escape and re-entry, the dispensary call, staying signed in overnight, or a real TV. Deferrals and
   synthetic UAT are not production sign-offs.
5. Housekeeping: `PREVIEW_README.txt` release marker still says D3 — outside this repository and out of scope.
   `AGENTS.md` remains the governing authorisation boundary; this file is the authoritative delivery status.

### Recommended delivery order

1. HC-01 (PR #44), BS-02/QR coverage/terminal reconciliation/BP-01/Payment Method setup (PR #45), and QR status
   Phases A and B (PRs #46, #47), QR intake form polish (PR #49) and the status page overflow fix (PR #52) are
   merged with post-merge CI green on PostgreSQL 18. TV-1 queue display (PR #57) and TV-2 dispensary and
   treatment-room calls (PR #60) are merged, followed by the TV display polish (PR #62), the TV follow-ups (PRs #64
   and #65), and the Call In and QR intake review redirect fixes (PRs #66 and #67).
2. Terminal reconciliation and HC-01 owner UAT are done. Confirm the QR Phase B browser GPS permission flow with
   the owner, and walk the Phase C intake form (step checks, review summary, session countdown); the agent-run
   checklist and screenshots from 2026-10-05 can guide that walk. BP-01 owner UAT
   may remain deferred as directed, but the deferral is not acceptance evidence. Walk TV-1 and TV-2 together on a
   real branch TV with synthetic data: consultation, dispensary and treatment-room calls, sound and voice.
3. Resolve the reported operational defects above under separately approved scopes.
4. Keep production deployment last, after all security, privacy/legal, access, recovery, trusted-proxy,
   scheduler, QR-rotation, and privacy-notice gates are closed.

### Registration board — branch `fix/registration-board-stale-rows-and-dates` (base `main` `8ef519b`)

**RB-01 — stale rows after a failed search (`f91c8cc`) — confirmed and fixed, with red-green evidence.**
The defect: `Registration/Index.vue` set an error on a non-OK response, and on a thrown/aborted request,
without ever clearing `rows`, `total` and pagination — so the previously selected tab's rows stayed on screen
under the newly selected tab. Fixed with a shared `clearBoard()` on both paths; the failing status is logged
and the error is surfaced through `PatientBoard`'s `emptyMessage`. The pre-existing generation-based
out-of-order guard is untouched.
Evidence: four frontend tests. Tests 1 and 2 (failed response; thrown/aborted request) **fail** against the
pre-fix `Index.vue` from `8ef519b` (`actual: ['SERVING-1']` vs `expected: []`) and pass after the fix — they
reproduce the authorised symptom. Tests 3 and 4 (superseded responses) already passed before the fix, because
the generation guard predates this work; they are regression cover, not red-green evidence.

**Unresolved — the mechanism actually observed in UAT.** DevTools showed **both** `/registration/search`
requests returning **200**, yet the wrong tab's rows were displayed. None of the four tests reproduce that, and
neither the pre-fix nor the post-fix code has an identified path that would produce it. RB-01 is therefore a
defensive fix: it removes a real defect, but it does not explain the incident that was reported. This
"two 200s, stale tab" mechanism remains **unreproduced and unresolved** — never confirmed against a captured
live status code, only inferred from the code-level defect. If the wrong-tab rows appear again, capture the
status codes, the response bodies and the tab sequence before changing any code.

**RB-02 — date filter showed the range label on every row (`0191d6f`) — fixed.**
The backend row projection carried no per-row date, so `boardRows` fell back to the global filter range label
for every row's `arrivedDate`, and `PatientBoard.vue` rendered it `whitespace-nowrap`, overlapping the Visit
Notes column. `VisitDirectoryService` now projects `registeredAtDate` (branch-local `Y-m-d`), `arrivedDate`
derives from it, the range label moved to the results bar, and the Arrived cell truncates.

PostgreSQL 18 validation of `0191d6f`: 656 tests, 654 passed, 2 expected Fortify skips, 0 failures,
7,417 assertions, re-run identically before the push. Preview `KPOne-Preview-D2` refreshed to `0191d6f`.
Human verification of RB-01 and RB-02 on the preview passed on 2026-09-24 (owner): each row shows its own
registered date, the range label appears once in the results bar, no overflow at 1440x900 or 390x844, and rapid
tab switching keeps rows matching the active tab.
**Shipped: PR #31 merged into `main` as `bc29c28` (merge commit, no squash) on 2026-09-24. Post-merge CI green.**
Branch `fix/registration-board-stale-rows-and-dates` kept; `validation/rb-01-rb-02-registration-board` kept, in
line with all 21 earlier `validation/*` branches, which are retained permanently as release evidence.

### PostgreSQL contention harness (TH-01)

Ten `tests/Feature/Postgres*RegressionTest.php` files run worker subprocesses, each with its own copy of the
wait helpers; there is no shared trait. Roughly 30 hardcoded deadlines existed between them. Nine files used
10 seconds; `PostgresBillingRegressionTest` used 12, the most generous in the codebase, and it is the one that
errored during the `docs/claude-setup` PostgreSQL gate on 2026-09-24 ("Phase 3A worker did not report READY",
both workers alive). Measured startup on a rested, idle machine: 10.63s cold, then 6.91s and 5.40s warm — about
1.4 seconds of margin against the 12-second deadline when cold. The failure occurred on a loaded machine, after
a full suite run and a build. The project history already held five commits spent stabilising this harness;
this was the sixth occurrence.

TH-01 replaced every hardcoded deadline and worker process timeout with `Tests\Support\ContentionTimeouts`, a
single source of truth: `readyTimeoutSeconds()` and `protocolTimeoutSeconds()` each default to 45 seconds,
overridable via `KPONE_CONTENTION_READY_TIMEOUT` and `KPONE_CONTENTION_PROTOCOL_TIMEOUT`; `processTimeoutSeconds()`
is enforced in code to be at least 3x both. No assertion, test name or contention protocol step changed.

On 2026-09-24, the same test on the same machine measured 10.63s worker boot when cold that morning, and under
1 second once the machine had run dozens of suites that day and was warm. A forced-failure check using a
1-second `KPONE_CONTENTION_READY_TIMEOUT` override could not be made to fail on demand as a result. More than
ten times variance within one day is why the old 10-12 second deadlines sat inside the noise band rather than
above it.

Still open, not acted on: the ten duplicated worker classes in `tests/Support/` (`PostgresBillingWorker`,
`PostgresClinicalEncounterWorker`, `PostgresClinicalSafetyWorker`, `PostgresDispensaryInventoryWorker`,
`PostgresPatientCreationWorker`, `PostgresPrimaryChangeWorker`, `PostgresQueueWorker`, `PostgresTreatmentPlanWorker`,
`PostgresVisitReasonWorker`, `PostgresVisitRegistrationWorker`) remain unconsolidated.

Owner decisions (settled 2026-09-24):

- **Held patients per doctor: maximum 3, plus a 30-minute warning (owner decision, 2026-10-03).** A doctor may
  hold at most three patients at a time; a fourth Hold is refused by the backend with a clear Bahasa Melayu
  message, and a patient held for more than 30 minutes is flagged on the queue board so nobody is forgotten.
  At most one active consultation stays unchanged. Authorised as its own scoped branch.
- **Marketing and Business Development keep organisation-wide staff-directory access.** The clinic group is
  small and BD genuinely coordinates across all three branches. This is the widest people-data grant outside
  HR and is accepted deliberately; it is directory data only (name, role, branch) and never clinical or
  patient content. Revisit if the group grows or if a Marketing module changes what those roles can see.

### UI-1 — Reference Data UI — branch `feature/ui-1-reference-data-ui` (base `main` `e8751b6`)

**The finding that started this phase.** No UI anywhere in the application could create a medicine, a clinical
service, or a price. A doctor had nothing to prescribe, so the Treatment Plan had no orders, so Dispensary had
nothing to hand over, so Billing's Build step produced an empty invoice. The entire synthetic end-to-end flow
that tests exercised (Registration → Queue → Consultation → Treatment Plan → Dispensary → Billing → Completed
Visit) only ever worked because tests supplied their own factory data; it was not reachable by a person. This
had not been identified as a go-live blocker in the original roadmap.

**A deeper gap surfaced during the survey: the Clinical Service Catalogue had no creation path at all.**
`ClinicalServiceCatalogueItem` existed as a schema and was already priced (phase P0) and already orderable on a
Treatment Plan (phase 2B), but nothing outside its test factory could ever construct one — a gap between
phases 2B and P0 that went unnoticed because both phases' tests supplied their own data. This was authorised as
a narrow, explicitly scoped exception (UI-1A) to UI-1's own "frontend and thin controller only" boundary:

- A new `ClinicalServiceCatalogueAdministrationService`, built by mirroring `MedicineAdministrationService`
  exactly (`create`/`update`/`activate`/`deactivate`, no delete method; organisation locking, case-insensitive
  uniqueness, an identity lock once operational/pricing dependents exist, and an audit record on every write).
  It deliberately omits `MedicineAdministrationService`'s case-preservation special case for an already-corrected
  code, since Clinical Service codes are always created uppercase — the one place the mirror intentionally
  differs, and it is behaviourally equivalent.
- A hard-delete guard added to `ClinicalServiceCatalogueItem`, mirroring `MedicineCatalogueItem::booted()`.
- One new permission, `clinical_services.manage.organisation`, shipped by an additive migration following the
  exact R1-02 pattern (`firstOrCreate` per permission, `givePermissionTo` on the diff per existing role, a
  missing catalogue role logged and skipped, `down()` a no-op). Granted to exactly the same roles as
  `medicines.manage.organisation` — `director` and `ca_supervisor`, confirmed by test, not by inspection alone.

**How pricing reaches the invoice, and why the UI keeps two permissions separate.** `ChargeDefinition` (a
charge's identity — code, type, source) and `PriceEntry` (its amount) are already modelled separately, with
`PriceEntry` append-only/immutable and versioned per `PriceBook`. Reference management
(`pricing.references.manage.organisation`, held by `finance_officer` and `director` and, since PX-01, `ca_supervisor`) creates Charge Definitions
and Price Books; publishing an amount (`prices.publish.organisation`) is a **separate** permission held by
`finance_officer` and, since PX-01, `ca_supervisor` (originally `finance_officer` only) — `director` can reach the Pricing screen and manage references but cannot publish a price.
The new `Pricing/Index.vue` reflects this: the publish column and action are gated by a server-computed
`canPublish`, distinct from page access, and publication carries the existing optimistic-concurrency
`expected_version`/`expected_branch_id` exactly as `PricePublicationService::publish()` already required. No
plain overwrite was added; the existing versioned-publish design was followed as found.

**What UI-1 delivered:** Medicine Catalogue and Clinical Service Catalogue list/create/edit/activate/deactivate
screens (never a hard delete); a Pricing screen for Charge Definitions, Price Books and publish; and thin
stock-setup screens added to the existing `Inventory/Index.vue` (`InventoryReferenceDataPanel.vue`) — new Item,
new SKU, new Location, new Batch, and medicine↔SKU mapping forms over the already-existing, already-permissioned
`InventoryReferenceAdministrationService`, plus the first-ever frontend form for the pre-existing Opening
Balance route. Create-only for the deeper inventory reference records (item/SKU/location/batch/mapping) was
judged sufficient to pass the acceptance test; update/activate/deactivate UI for those was not built and is a
disclosed scope limitation, not an oversight. Purchase orders, goods receipts, stock requests and stocktake UI
remain out of scope, backend-only, pending UI-2. A new "Reference Data" main-menu group makes all three new
screens reachable for the roles holding their permissions; `Clinic/Placeholder.vue` was confirmed to be the
generic "not built yet" stub and was not used as a home for any of this.

**Verification, on the UI-1 tree at `903d929` (before `main` was merged in).** All automated gates green: Pint,
PHPStan/Larastan (0 errors), `vue-tsc`, ESLint, Prettier, `npm run build`, `git diff --check`. PostgreSQL 18
(disposable `kpone_ui1c_test`, port 55493, dropped after use, final code including the SKU-batches endpoint):
682 tests (`main` at `e8751b6` is 656, plus UI-1's 26 new tests), 680 passed, 0 failures,
7,691 assertions, 2 skipped (the two `RegistrationTest` cases named above) — no PostgreSQL contention test
skipped. `node --test tests/Frontend/*.mjs` on that tree: 171 tests, 171 passed, 0 failures. New coverage added: the permission-mapping, create/update/audit, duplicate/foreign-tenant,
unauthorised/inactive-actor, identity-lock, and hard-delete-guard tests for
`ClinicalServiceCatalogueAdministrationService`; the SKU-batches endpoint's 403, cross-tenant 404, happy-path
and no-batches-in-props tests; the permission migration's fresh-grant, already-seeded,
up→down→up idempotency, and (director/ca_supervisor)-hold/other-roles-do-not-hold tests; and 403-without-permission
plus happy-path HTTP feature tests for all four new controller areas, including a direct test that `director`
can reach Pricing but is refused at `publish` while `finance_officer` is not.

**Test counts, by tree.** `main` at `e8751b6`: 656 tests (the figure recorded above). `main` at `ac9a0e5`
(AC-01 merged): 667 (656 plus AC-01's 11). The UI-1 tree at `903d929`, before `main` was merged in: 682 (656 plus
UI-1's 26). **The merged tree, `a23d177` (UI-1 plus `main` at `ac9a0e5`), measured on PostgreSQL 18 on
2026-09-25: 693 tests, 691 passed, 2 skipped, 8,045 assertions, 0 failures** — exactly 656 + 26 + 11. The two
skips are the two `RegistrationTest` cases named in the AC-01 section. `node --test tests/Frontend/*.mjs` on the
merged tree: 171 tests (163 from `main` plus UI-1's 8), all passing. Pint, PHPStan, `vue-tsc`, ESLint, Prettier,
`npm run build` and `git diff --check` all pass on the merged tree.

**Opening Balance Batch picker.** The first build asked the operator to paste a Batch UUID; review rejected
that as unusable. Batch is now a picker scoped to the selected SKU, loaded on demand from one read-only
endpoint, `GET /inventory-references/skus/{sku}/batches` (permission `inventory.references.manage.organisation`;
a SKU from another organisation returns 404, not 403; no status or expiry filtering — the domain service stays
the authority). Batches are not in the Inventory page props: an interim version that shipped every batch in
`referenceData` was unbounded and was removed, not kept alongside.

**Known issue, pre-dates UI-1, not fixed here.** `InventoryController::referenceData()` projects `items`, `skus`
and `locations` organisation-wide with no limit, so every Inventory page load ships all of them to anyone
holding `inventory.references.manage.organisation`. To be bounded in their own change. UI-1 fixed only `batches`,
the one projection it introduced.

**Director and price publishing — reviewed, deliberate.** Publishing an amount (`prices.publish.organisation`) is
an execution permission held by `finance_officer` and, since PX-01, `ca_supervisor` (originally `finance_officer`
only — see the PX-01 section), consistent with roughly ten permissions that split
oversight (`director`) from execution. An owner who needs to publish holds the `finance_officer` role rather
than widening the permission.

**Test skips.** The two skipped tests are `Tests\Feature\Auth\RegistrationTest`, both cases (Fortify
registration is intentionally disabled). Named by the TH-01 verbose run and unchanged at 2 in every run since;
the UI-1 run outputs record only the count.

**BT-01 completed:** the owner ran the acceptance flow on a freshly migrated database on 2026-10-02 using only
the UI, then reviewed all six Insights reports with non-zero synthetic data and confirmed the results (see
section 1). This proves the synthetic acceptance walkthrough, not production readiness or approval. UI-1
merged into `main` as `0a26449` (PR #36).

### NAV-01 — explicit Inventory redirects — branch `fix/nav-01-explicit-redirects` (base `feature/ui-1-reference-data-ui` `ac5bdda`)

**The defect, found by the owner's hand at step 12 of the UI-1 walkthrough, while 700 tests passed.** Creating a
Medicine Catalogue reference record (an Inventory Item, then a Location) in the Stock Setup panel sent her to the
Pricing page instead of back to Inventory. The records were created correctly — this was never a data-loss bug —
but the resulting confusion led her to use the browser Back button to return to Inventory, and the SKU-creation
dropdown then showed only the pre-existing catalogue, not the item she had just created.

**The mechanism, traced rather than guessed.** `Illuminate\Session\Middleware\StartSession::storeCurrentUrl()`
only records the session's `_previous.url` for a `GET` request that is **not** AJAX. Inertia sends
`X-Requested-With: XMLHttpRequest` on every navigation, so almost none of an Inertia SPA's browsing ever updates
`_previous.url` — it stays wherever it was last set by a genuine full/hard page load. Laravel's `back()` resolves
to that frozen value, which is very often not the page the request actually came from. The owner's own session row
(decrypted read-only, with her explicit authorisation for that one read) showed `_previous.url` pinned at
`/pricing`, confirming the mechanism directly rather than by inference.

**Why the stale dropdown was a second symptom of the same bug, not a separate one.** Inertia's browser Back/Forward
handling (`handlePopstateEvent` in `@inertiajs/core`) restores the page snapshot cached in `history.state`, without
a network request. Landing unexpectedly on Pricing led her to press Back to return to Inventory; Back therefore
restored the *pre-creation* snapshot of the Inventory page — a client-side cache read, not a stale query.

**A recurrence, not a new defect class.** OH-06d (2026-09-23, already in this document) fixed the identical
`back()` problem for the Hold/Resume path with `to_route('encounters.show', ...)`. UI-1 reintroduced the same
pattern in five new actions without knowing that history. NAV-01 follows OH-06d's exact shape rather than inventing
a new one: an explicit `to_route()` on success, and a caught `ValidationException` re-thrown with an explicit
`->redirectTo()` on failure, so a blocked or invalid attempt lands on the same page too.

**The invisible-validation-error risk, proven and closed.** A failed create has two distinct failure stages, both
of which defaulted to `back()`'s broken resolution before this fix: the request's own field validation (handled by
Laravel *before* the controller method runs, via `FormRequest::failedValidation()`) and a business-rule failure
thrown by the domain service *inside* the controller method. Both are now redirected explicitly to
`inventory.index` — the request-level failures via a small shared trait
(`App\Http\Requests\Concerns\RedirectsInventoryValidationFailuresToIndex`, applied to all 7 affected
`FormRequest` classes), the service-level failures via a private `stayOnInventory()` helper on each controller,
mirroring OH-06d's `stayOnConsultation()`. Because the redirect target is the same page the form lives on (unlike
OH-06d's target, a different page with no form), the existing per-field `InputError` bindings already display the
message once the redirect lands correctly — no separate error toast was needed.

**Scope: fixed in reference-data and operational Inventory forms; everything else surveyed and left.** Every
`return back();` in `app/` was found and classified — file, line, its intended target, and whether it is reached
by an Inertia (AJAX) request, which every one of them is:

| File:line | Trying to return to | Fixed |
|---|---|---|
| `InventoryReferenceController.php`:29,43,56,74,115 | Inventory Stock Setup panel | **Yes** |
| `InventoryMovementController.php`:18,26 | Inventory Stock Setup panel (Opening Balance / Transfer) | **Yes** |
| `InventoryOperationsController.php`:192 (shared `success()`, 23 actions) | Inventory Operations panel | **Yes** |
| `BranchContextController.php`:23 | wherever the branch switcher was opened (global header, every page) | No — reported |
| `DispensaryController.php`:72 (`acknowledge`) | the clinical page embedding `DispensaryAttentionPanel.vue` | No — reported |
| `PublicIntakeReviewController.php`:42,51,60 (`start`/`correct`/`correctionRequired`) | the Registration Review page | No at NAV-01; **fixed later in PR #67** (INTAKE-01) |
| `Settings\SecurityController.php`:35 | the Security settings page | No — reported |

One finding worth flagging on its own: **`PublicIntakeReviewController` already carries a partial fix.** Its
`reject` and `accept` actions were already changed to `to_route('registration.index', ['tab' => 'qr-intake'])`
at some earlier point, while `start`, `correct` and `correctionRequired` in the very same file were not. This is
the clearest evidence that the lesson from OH-06d did not travel with the pattern - the fix landed in some call
sites and not their neighbours in the same class.

**Guarded so a fourth occurrence is caught immediately, not by a human at step 12 again.**
`tests/Feature/Clinical/NAV01ExplicitRedirectsTest.php` (9 tests) asserts the exact redirect target -
`assertRedirect(route('inventory.index'))` - for every one of the five reference-data actions (success and a
domain-thrown duplicate-code failure), both movement actions, and a representative Operations action on both its
success and its inline-`validate()` failure path. **Proven red first**: with the fix's ten tracked files stashed
back to their original `back()` code (the new test and trait files left in place), 8 of 9 failed on the exact
assertion the fix repairs, for example:

    Failed asserting that two strings are equal.
    --- Expected
    +++ Actual
    @@ @@
    -'http://localhost/inventory'
    +'http://localhost'

This is exactly the failure item 4 predicted: a feature test's session carries no `_previous.url` at all, so
`back()` resolves to `/` even in the test environment, not merely to the wrong page in the browser. The ninth
test (the `FormRequest`-level validation-failure case) also failed, with a different symptom (an internal
`TypeError` from asserting session errors against the unfixed code's response shape) - a second, independent
piece of evidence that the old code was broken on that path too. The existing `InventoryReferenceControllerTest`
and `PricingControllerTest`-style assertions only ever called `assertRedirect()` with no argument, which accepts
any 3xx response and therefore could never have caught this - the gap the new tests close.

**A cheap build-time guard was considered, not built, as instructed.** A PHP-CS-Fixer or a small static-analysis
rule could flag any new bare `return back();` inside `app/Http/Controllers`, forcing a reviewer to either name an
explicit route or add a suppression with a reason. This is not implemented here and would need its own approval.

### AC-01 — staff authority — branch `fix/ac-01-staff-authority` (base `main` `e8751b6`)

**The defect.** A `director` could not provision a `ca_supervisor`: creating the account and assigning a branch
failed with HTTP 403 ("You may not change this staff member's branch access."), and the transaction rolled back.
The same rule blocked every later action on such an account — profile edit, branch assignment, role change and
deactivation — for every role. **An existing `ca_supervisor` could not be deactivated by anyone**, which is an
offboarding hazard, not just a provisioning inconvenience.

**Why.** `StaffAuthorityService::canManage()` allowed an actor to administer a target only if the actor held every
permission the target held that `PermissionCatalogue::isAdministrativeAuthority()` classified as administrative.
That method matched any permission containing `.manage.`. `ca_supervisor` holds `inventory.reorder.manage.branch`
and `public_checkin_links.manage.branch`, which `director` does not, and `technical_admin` additionally lacked four
organisation-level ones. Nobody covered the set, so nobody could manage the role.

**It predated UI-1 by three phases.** The rule dates from Phase 0B (`4490c72`, 2026-08-20); the two permissions
that locked out `director` arrived later, in I2 (`b756154`, 2026-09-13) and Q1-B2 remediation (`dbbad27`,
2026-09-20). UI-1 changed no outcome: `director` already held every other permission UI-1 added.

**Why the existing tests never saw it.** The 656 tests on `main` at `e8751b6` (654 passed, 2 skipped; the
figure recorded above for `0191d6f`, and re-measured on this branch as 667 tests, 665 passed, 2 skipped, with
its 11 new tests) never covered it. The 2 skips, named from a PostgreSQL 18 run of the Auth tests, are
`Tests\Feature\Auth\RegistrationTest::test_registration_screen_can_be_rendered` and
`::test_new_users_can_register`: `setUp()` calls `skipUnlessFortifyHas(Features::registration())`, and
`config/fortify.php` deliberately omits the registration feature, so public registration stays disabled.
No other test skips on PostgreSQL. The gap was that no staff test file mentioned `ca_supervisor`, so no
test provisioned or administered one. It was found by a human walkthrough of UI-1, at step 2, account
provisioning.

**The rule as changed.** Administrative authority is no longer a substring match. It is a declared set,
`PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS`: `staff.manage.organisation`,
`branches.manage.organisation`, `access.manage.organisation` — authority over people, access and branches, not
capability over records. `canManage()` is otherwise unchanged (same organisation check, same protected-director
check, same subset comparison). The substring never matched the intent stated in `StaffAuthorityService`'s own
docblock: `director` and `technical_admin` hold an identical administrative set, and everything else they manage
(medicines, clinical services, inventory references and suppliers, reorder levels, pricing references, payment
methods, patient identifiers, check-in links) is operational. It is an allowlist by design: a substring silently
classifies every future permission, an allowlist forces a decision, and no new operational `.manage.` permission
can ever again change who can administer whom. A permission becomes administrative only by being added to the
declared set on purpose.

**Who can administer whom now.** Every role except `director` is administrable by `director`, `technical_admin`
and `hr_manager`. `hr_manager` (administrative set `staff.manage.organisation`) is administrable by `director`,
`technical_admin` and a peer `hr_manager`; `technical_admin` by `director` and a peer `technical_admin`;
`director` by `director` only. Self-management is refused for every ability. `hr_manager` gains profile edit and
activate/deactivate only: `manageAccess` and `manageRoles` also require `access.manage.organisation`, which
`hr_manager` does not hold, so HR cannot change branches or roles.

**Peer management — owner decision.** Peer `hr_manager` management is accepted, not blocked. Peer management was
already accepted for `technical_admin`, so blocking it for `hr_manager` alone would be arbitrary; and a
consistent peer-blocking rule would stop one `technical_admin` deactivating another — exactly the offboarding
hazard this change closes. It is recoverable denial, not escalation: no permission is gained, and a `director` or
`technical_admin` can reverse it.

**Owner decision — provisioning and administration are deliberately asymmetric.**
- **Provisioning a `ca_supervisor` stays with `director` alone.** `canAssignRoles` is unchanged and correct:
  `technical_admin` does not hold `medicines.manage.organisation` and the rest, and letting it grant that role
  would let it mint an account with clinical reach it does not have. That would be privilege escalation and would
  collide with the standing rule that technical admin never gains clinical or patient access by implication.
- **Administering an existing `ca_supervisor`** — profile, status, branch assignment, role sync — extends to
  `technical_admin` (and profile/status to `hr_manager`). This grants nothing and creates nothing.
- **Technical admin can demote, never promote.** Stripping a `ca_supervisor` to a role `technical_admin` may itself
  assign is one action; restoring it requires a `director`. Demotion is audited (`access.role.detached` /
  `access.role.attached`) with the acting user. This follows from `canAssignRoles` being unchanged. **No future
  phase should "fix" the asymmetry by loosening `canAssignRoles`.**

**Pinned by** `tests/Feature/StaffAuthorityBoundaryTest.php` (11 tests). Two guards: an *authority-set pin* (the
declared set equals exactly the three permissions; every other catalogue permission is non-administrative; it
fails closed for any permission added or removed at any scope) and an *administrability invariant* (a `director`
can provision and then administer every role except `director`; a `technical_admin` can administer every role whose
administrative set is empty — the test that would have caught AC-01 the day `inventory.reorder.manage.branch` was
added). Escalation tests: `technical_admin` cannot re-assign `ca_supervisor` after demoting it; cannot demote or
manage a `director` on either the manage or the demotion path; can demote a peer `technical_admin`; a peer
`hr_manager` can edit and deactivate another `hr_manager` but **cannot change that account's roles or branch
assignments**; an actor holding only operational `.manage.` permissions gains no authority over anyone; a
directly-granted `staff.manage.organisation` makes an account administrable only by actors holding it; and
self-management is refused for all four abilities. The tests were confirmed red against the old substring rule
(3 failures, 3 errors, including `director` provisioning `ca_supervisor`) and green against the new one.
`BillingBoundaryTest` asserts the outcome (`prices.publish.organisation` is not administrative), not the
mechanism, and is unchanged.

### PX-01 — supervisor pricing — branch `feature/px-01-supervisor-pricing` (base `ac5bdda`, the UI-1 tip)

**Owner decision, 2026-09-27.** `ca_supervisor` now holds `pricing.references.manage.organisation` and
`prices.publish.organisation`, and so manages price books, charge definitions and published prices, exactly as
`finance_officer` does. No permission was invented (both already existed) and no role lost one.

**This is a deliberate reversal, not an oversight.** UI-1 recorded that publishing a price was an execution
permission held only by `finance_officer`, part of a separation of duties between oversight and execution. The
owner has since chosen the Yezza pricing model, in which price lives on the item and whoever manages the catalogue
sets it. Withholding the Pricing menu from the role that manages the Medicine and Clinical Service catalogues
contradicts that direction, so the earlier separation is reversed for `ca_supervisor`. It is unchanged for every
other role: `director` can still reach Pricing and manage references but cannot publish, and `ca`,
`resident_doctor`, `panel_officer`, `technical_admin`, `hr_manager`, `marketing` and `business_development`
hold neither permission.

**What changed.** `BillingPermissions::roles()`, the file that owns both permissions and `finance_officer`'s grant
of them, gains the two permissions on `ca_supervisor`, so a fresh install and `PermissionCatalogue::roles()` agree.
An additive migration, `2026_09_27_000100_grant_ca_supervisor_pricing_permissions_additively`, brings an existing
database into line: `firstOrCreate` per permission, `givePermissionTo` on the difference, a missing role logged and
skipped, never `syncPermissions`, `down()` a no-op. It is scoped to this one role and these two permissions on
purpose, so it cannot change any other role as a side effect. `ca_supervisor` goes from 53 to 55 permissions
(fresh-install catalogue); every other role's count is unchanged; the catalogue total stays 93.

**Staff administration is unchanged, and pinned.** Neither permission is in
`PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS`, so `canManage`, `canAssignRoles` and the AC-01 model are
untouched: `technical_admin` can still administer but not assign a `ca_supervisor`.

**TWO EXISTING ASSERTIONS WERE CHANGED to encode the new rule — this is a deliberate policy change, not a test
weakened to go green.** Both encoded the old separation of duties, and each now asserts the new rule positively:
- `Tests\Feature\Billing\PricingReferenceAdministrationServiceTest` — renamed from
  `test_permission_mapping_is_limited_to_director_and_finance_officer` to
  `test_permission_mapping_is_limited_to_director_finance_officer_and_ca_supervisor`. `ca_supervisor` moved from the
  "must not hold" list to the "must hold" list, and a `ca_supervisor` calling `createConsultationCharge` is now asserted
  to **succeed** (previously asserted to throw `AuthorizationException`). The denied list for every other role is
  unchanged.
- `Tests\Feature\Billing\PricingControllerTest::test_reference_routes_require_pricing_permission` (added in UI-1) —
  `ca_supervisor` removed from the 403 loop and a positive block added asserting it reaches `/pricing` and can create
  and toggle a charge and a price book. No other role's assertions changed.

The principle applied: a test failing because the code is wrong is fixed in the code, never in the test; a test
failing because the owner changed the rule it encodes is updated to state the new rule, and the change is recorded
here with the reason.

**New coverage:** `tests/Feature/Billing/SupervisorPricingTest.php` (7 tests) — exactly which roles hold each
permission, the Pricing menu flag, a `ca_supervisor` creating a price book and charge and publishing a price
(audited), `finance_officer` and `director` unchanged, the grant not touching staff administration, and the migration
(restores a legacy database, idempotent, up→down→up, never removes, creates a missing permission but never a missing
role).

**Verification — the gate is NOT fully green, for one unrelated reason.** On the PX-01 tree (`ac5bdda` plus this
change), PostgreSQL 18, disposable `kpone_px01_test`: **700 tests, 697 passed, 2 skipped, 1 failed, 8,151
assertions**. 700 is the merged UI-1 tree's 693 plus the 7 new tests. The 2 skips are the two `RegistrationTest`
cases. Pint, PHPStan, `vue-tsc`, ESLint, Prettier, `npm run build`, `git diff --check` pass, and
`node --test tests/Frontend/*.mjs` is 171 of 171.

The one failure is `Tests\Feature\PublicPatientIntakeTest::test_qr_intake_listing_shows_no_age_for_an_implausible_stored_date_of_birth`
("Failed asserting that 0 is null"). It is **not caused by PX-01**: it fails identically on the unmodified base
(`ac5bdda`, no PX-01 change) when run at the same time. It is clock-dependent. The test stores the string `'2023'`
as a date of birth, and `PublicIntakeReviewService::ageOrNull()` passes it to `Carbon::parse()`, which reads `'2023'`
as the clock time 20:23 today rather than a year. That counts as "future" (so the age is correctly unavailable) only
before 20:23 in the app timezone (UTC); after it the parsed value is in the past, the age is 0, and the assertion
fails. It therefore passes from 00:00 to 20:22 UTC and fails from 20:23 to 23:59 UTC (04:23 to 08:00 in Kuala
Lumpur), every day. Earlier runs in this document passed because they ran inside the passing window. It is a
latent defect in existing code, recorded as open item 10 in section 6; it was not fixed in this change.

### DOB-01 — strict date parse — branch `fix/dob-01-strict-date-parse` (base `main` `ac9a0e5`)

**The bug.** `PublicIntakeReviewService::ageOrNull()` turned a stored date of birth into an age with
`Carbon::parse($dateOfBirth)`. A lenient parser reads the string `'2023'` as a clock time, 20:23 today, not a year.
That value counted as "future", so the age was correctly unavailable, only before 20:23 in the app timezone (UTC).
From 20:23 to 24:00 UTC it counted as already past and the age came out as 0. So a malformed stored date of birth
showed an age of 0 instead of "unavailable" **for 3 hours 37 minutes of every day** (20:23 to 24:00 UTC, which is
04:23 to 08:00 in Kuala Lumpur). Age drives clinical judgement, and a silently misparsed date can produce a
plausible-looking wrong age rather than an obviously wrong one, which is why a lenient parser on a date of birth is
the real defect and not the one string the test happened to use.

**How it was found: by the PX-01 gate, not by any test written for it.** The existing test
`test_qr_intake_listing_shows_no_age_for_an_implausible_stored_date_of_birth` stores `'2023'`, and it passed in every
run until the PX-01 full-suite gate happened to run at about 22:30 UTC and failed with "Failed asserting that 0 is
null". Earlier runs all fell inside the passing hours. The same test then failed identically on the unmodified base
(`ac5bdda`, no PX-01 change) when run at that hour, which is how it was shown to pre-date PX-01. Waiting for a
convenient hour was refused: a green obtained by choosing the hour is not evidence.

**The fix.** `ageOrNull()` now accepts only a string that matches `^\d{4}-\d{2}-\d{2}$`, parses it with
`Carbon::createFromFormat('!Y-m-d', …)`, and rejects any value that does not round-trip to itself (so
`2000-02-30` does not roll over into March). Everything else is unavailable. The strict pattern already existed in
`PublicIntakePayloadValidator` (`createFromFormat('!Y-m-d', …)`); this function was the outlier. The existing test and
its assertions are unchanged.

**The regression test does not depend on the hour.**
`test_a_malformed_stored_date_of_birth_never_yields_an_age_at_any_time_of_day` freezes the clock at five UTC times
(00:01, 10:00, 20:22, 20:24 and 21:00) and, at each, asserts that 13 malformed stored values
(`'2023'`, `'2026'`, `'1230'`, `'0930'`, `'20'`, `'today'`, `'tomorrow'`, `'not a date'`, `'2023-9-5'`, `'2000-02-30'`,
`'1990-01-01 10:00'`, `' 1990-01-01'`, `'1990/01/01'`) are unavailable, while `1990-01-01` is age 36, a date of birth of
today is age 0 and tomorrow is unavailable. Proven red first, against the unchanged code, at a real clock of
04:16 UTC (an hour at which the old test passes): `'2023' must be unavailable at 2026-09-26 21:00:00 — Failed
asserting that 0 is null.` Green after the fix (88 assertions), at any real hour.

**One bug, not a pattern.** Every other use of a lenient parser in `app/` reads a database value or a request value
already validated as `date_format:Y-m-d` at the edge (inventory expiry and received dates, movement filters,
dispensary and billing timestamps). No date of birth entry point (`PatientAdministrationService`,
`PublicIntakePayloadValidator`, `StorePatientRequest`, `UpdatePatientRequest`, `StoreVisitRequest`,
`CheckPatientDuplicatesRequest`) accepts unvalidated text. `ageOrNull()` was the one place that re-read stored data
without re-validating it. The lenient parsers that remain rely on validation happening earlier; that is a design
dependency worth knowing, not a defect found here.

**Closes open item 10** (the clock-dependent `PublicPatientIntakeTest` failure recorded on
`feature/px-01-supervisor-pricing`). That item lives on the PX-01 branch, not on `main`, so it is struck when this
change reaches `main` and PX-01 merges it in.

**Verification, on the DOB-01 tree (`ac9a0e5` plus this change).** PostgreSQL 18 (disposable `kpone_dob01_test`, port
55498, dropped after use): **668 tests, 666 passed, 2 skipped, 0 failed, 7,857 assertions** — `main` at `ac9a0e5` is 667,
plus the 1 new test. The 2 skips are the two `RegistrationTest` cases. Pint, PHPStan, `vue-tsc`, ESLint, Prettier,
`npm run build` and `git diff --check` pass, and `node --test tests/Frontend/*.mjs` is 163 of 163. That suite ran at
about 04:20 UTC, an hour at which the old defect was invisible, so the full run alone is not evidence of the fix; the
frozen-clock test above is.

## 7. Updating this file

Update this baseline in the same PR that merges a phase or changes an authorisation boundary.
