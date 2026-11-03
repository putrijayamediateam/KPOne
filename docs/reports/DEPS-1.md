# DEPS-1 — Composer runtime advisory patch

Date: 2026-11-03 (Asia/Kuala_Lumpur). Branch `fix/deps-1-composer-runtime`; isolated worktree `/Users/klinikputrijaya/Herd/KPOne-deps-1`. Initial fetched base: `d213653f5100436d3ce68f2fb11ab80188994ba3`. Main advanced during validation with FLOW-FIXES-1 (#201); the uncommitted branch fast-forwarded without conflict or a new commit to final base/HEAD **`b04e585c11f88325c1153d6ea22d060743faf1ae`**. The full gate and browser rehearsal were repeated on that tree. This is an uncommitted dependency candidate. No push or PR; no pull request was merged. Not production-approved. Synthetic data only.

## Scope and exact lock diff

Only `composer.lock` and this report change. No application source, test, permission, migration, route, payload, npm manifest/lock or Composer manifest change. No new package and no removed package. Existing constraints already admit all targets.

| Package | Before | After | Why included |
| --- | --- | --- | --- |
| `symfony/http-foundation` | v8.1.4 | v8.1.9 | Owner target; request-header parser security fix plus its whitespace-separator follow-up. |
| `laravel/framework` | v13.25.0 | v13.30.0 | Exact owner target; TokenGuard, JSON API depth and debug-renderer fixes. |
| `league/commonmark` | 2.10.0 | 2.10.2 | Exact owner target; table-parser DoS and raw-HTML filter fixes. |
| `league/flysystem` | 3.35.2 | 3.35.3 | Required transitive: old version blocked by Composer's unchanged advisory policy when resolving Laravel dependencies. Smallest patched version retained. |
| `symfony/mime` | v8.1.4 | v8.1.10 | Required transitive: old version blocked by the same policy; parameter-name injection fix. |

Targeted commands, with explicit task environment, isolated Composer home/cache and scripts disabled:

```sh
composer update symfony/http-foundation:8.1.9 --with-dependencies --minimal-changes --no-scripts --no-interaction --prefer-dist
composer update laravel/framework:13.30.0 league/commonmark:2.10.2 --with-dependencies --minimal-changes --no-scripts --no-interaction --prefer-dist
composer update league/flysystem:3.35.3 --with-dependencies --minimal-changes --no-scripts --no-interaction --prefer-dist
```

The second command initially selected Flysystem 3.36.0. A targeted dry-run pinning both old transitives failed with exit 2: Flysystem 3.35.2 was blocked by `PKSA-w9tt-7782-78jx`; Mime 8.1.4 by `PKSA-wtxr-p26d-nn42` / `PKSA-2n2k-66v2-bwg3`. No advisory ignore or blocking-policy override was used. A final targeted run retained the smaller patched Flysystem 3.35.3. Composer also refreshed two URLs on unchanged `vlucas/phpdotenv`; those unrelated metadata edits were restored, keeping its version, commit and complete lock entry unchanged. Final structural comparison identifies exactly the five entries above. `composer validate --no-check-publish` passes with the pre-existing warning about the exact libphonenumber version constraint.

## Upgrade survey, reported before the full backend gate

The version table, compatibility findings, focused results and remaining audit entries were given to the owner in chat before `composer ci:check` started. The following is the retained survey; claims about upstream behaviour come from tagged source/changelogs, not from assumptions about patch-version compatibility.

### HttpFoundation

The Cache-Control request-header path is reachable before authentication: `public/index.php:20` captures the Laravel request; `vendor/laravel/framework/src/Illuminate/Http/Request.php:78–82` uses Symfony request capture; HeaderBag parses Cache-Control through HeaderUtils. A response no-store middleware cannot protect request construction. [Upstream advisory](https://symfony.com/cve-2026-108082).

8.1.9 uses non-backtracking token/whitespace branches and also fixes the whitespace-separator regression in 8.1.7–8.1.8. The source comparison additionally shows trusted-host literal/combined-regex handling, corrected IPv4-embedded IPv6 forwarded-address parsing, suppressed PHP body/query-parser warnings, and session-handler failed-read propagation/serialization protection. Some Symfony handler read methods now allow `string|false`; KPOne does not override those handlers. The Symfony request limiter's change from a count check to an empty-array check does not change the limit. General 8.1 deprecations already present in 8.1.4 are not claimed as new upgrade effects. [Tagged source](https://github.com/symfony/http-foundation/tree/v8.1.9).

### Laravel

Actual old/new source comparison confirms non-string TokenGuard token rejection; JSON API relationship-depth enforcement, including zero; and debug-renderer escaping fixes. Other relevant changes include route-name preservation for array controller actions, strict `in` / `in_array` / `doesnt_contain` comparisons, numeric-comparison exception handling, literal-dot handling in unknown-field checks, empty wildcard keys, literal asterisks in merged request/query keys, Redis cursor normalization, scoped-instance deduplication, and unique-job lock release only when acquired. PostgreSQL keepalive DSN options are added only when configured. These are compatibility-sensitive fixes, not a claim of byte-identical framework behaviour. No KPOne configuration was changed to use new features. Laravel now explicitly requires guzzlehttp/psr7 and psr/http-message and permits newer Guzzle major lines in its own metadata; the already-locked versions 2.13.0 and 2.0 satisfy the new requirements without movement. These are upstream lock metadata changes, not edits to KPOne composer.json.

The tagged changelog has no separate 13.30.0 heading and incomplete adjacent headings. The notes were therefore checked against the actual package diff. No major migration or KPOne call-site repair was required. Laravel `SessionGuard`, session `Store` and `Routing/Middleware/ThrottleRequests.php` are byte-identical to the baseline. [Tagged changelog](https://raw.githubusercontent.com/laravel/framework/v13.30.0/CHANGELOG.md).

### CommonMark

2.10.2 checks the delimiter before rescanning a growing paragraph, uses byte-oriented table scanning, and filters a disallowed tag even when HTML ends immediately after its name. GFM table headers no longer need a pipe, and header/delimiter cell counts must agree. 2.10.1 accepts `unordered_list_markers` for InlinesOnlyExtension. These table-detection changes can change rendered Markdown, intentionally; no KPOne page depends on the old mismatch behaviour. [Changelog](https://raw.githubusercontent.com/thephpleague/commonmark/2.10.2/CHANGELOG.md).

No direct `CommonMark`, `Markdown` or `Str::markdown` conversion call was found under `app`, `routes`, `resources`, `config` or `bootstrap`. It is nevertheless a runtime library: Laravel Mail `Markdown::parse` installs TableExtension (`vendor/laravel/framework/src/Illuminate/Mail/Markdown.php:151–204`), and `Str::markdown` / `inlineMarkdown` expose converters (`vendor/laravel/framework/src/Illuminate/Support/Str.php:817,840`). Fortify enables password-reset and verification notifications (`config/fortify.php:145–148`); User uses Notifiable (`app/Models/User.php:42`). Default reset mail uses fixed framework lines and a generated URL. No attacker-controlled Markdown body reaches these converters through a tracked KPOne route. Deployed mail customisations were not inspected; this is a source-backed absence of an established untrusted-input path, not a claim that Markdown is unused.

### Required transitives

Flysystem 3.35.3's only source change makes malformed UTF-8, as well as control characters, a corrupted-path refusal instead of letting a PCRE error pass. Normal paths keep their existing normalization. [Changelog](https://raw.githubusercontent.com/thephpleague/flysystem/3.35.3/CHANGELOG.md).

Mime 8.1.10 rejects non-token parameter names; parameter values escape quotes/backslashes and encode controls. Other intervening fixes tighten address/control-character and unserialization checks, preserve multipart/subclass state, and correctly read a stream line containing `0`. KPOne has no custom MIME parameter-name or serialization override. [Header-injection advisory](https://symfony.com/cve-2026-45070), [tagged source](https://github.com/symfony/mime/tree/v8.1.10).

### THROTTLE-1 and TokenGuard

`app/Http/Middleware/RouteIsolatedThrottle.php:14–27` still overrides `protected resolveRequestSignature($request): string`. The new parent still has one untyped argument and no native return declaration, so the child return type remains compatible. Parent throttle source is unchanged. Named limiter dispatch is inherited; all logical keys, decay periods and framework cache-key assertions pass unchanged. The six `ThrottleIsolationTest` tests also cover route/user isolation, guest IP fallback, the polling/start/Call In sequence and ten registrations followed by payment. Its two frontend static guards pass.

`config/auth.php:40–45` defines only the `web` session guard; `config/fortify.php:18` selects it. Searches found no application TokenGuard construction, token driver, `auth:api`, `Auth::extend` or `viaRequest` registration. The advisory's TokenGuard path is not configured. The dependency is still patched rather than relying on that absence.

## Advisories closed and left open

`composer audit --format=json` returns exit 1 because two advisories remain, with valid JSON and no transport error. No ignored or abandoned entry is reported. No targeted package appears in the final advisory list. Compared with the DEPS-AUDIT-1 snapshot at the same old versions, these eight advisory records are closed:

| Package | Closed advisory |
| --- | --- |
| HttpFoundation | GHSA-r925-mgvr-h9cc / CVE-2026-108082 — Cache-Control ReDoS. |
| Laravel | GHSA-f9x3-7vq9-cph6 — non-string TokenGuard credentials. |
| Laravel | GHSA-5wcm-3v2w-8362 — JSON API relationship depth. |
| Laravel | GHSA-jh5r-qr3c-85q8 — debug-renderer XSS. |
| CommonMark | GHSA-3q6v-r5mr-hxv8 — table-parser DoS. |
| CommonMark | GHSA-97jj-33gv-5xf9 — unfinished disallowed raw tag. |
| Flysystem | GHSA-cxf4-7mrp-vvpr — malformed UTF-8 path bypass. |
| Mime | CVE-2026-45070 — header parameter-name injection. |

Remaining: **0 critical, 1 high, 1 medium, 0 low; 2 advisories / 2 packages**.

| Package / current version | Advisory | Why left open |
| --- | --- | --- |
| `phpseclib/phpseclib` 3.0.56 | Medium, PKSA-12sz-bcny-pk4m; GHSA-q97c-8qh3-fpc6; CVE-2026-84308, X25519 private-key recovery. | Outside the authorised targets and not required to resolve them. Runtime dependency remains; separate scoped review/patch needed. The fixed floor on this line is 3.0.57. |
| `symfony/yaml` v8.1.2 | High, PKSA-xz3r-9gp4-4m1z; GHSA-2v3c-qxhr-7vpv; CVE-2026-109413, inline-parser recursion. | Development-only Sail dependency in packages-dev; outside this patch. Fixed floor 8.1.6. Production exclusion of dev dependencies needs deployment verification. |

`composer audit --no-dev --format=json` reports only the one medium phpseclib advisory. npm packages and the npm advisories from DEPS-AUDIT-1 are unchanged and not remediated by this work. In particular this report does not clear the separate Vue/SSR issue or development-tool advisories. Audit data is a dated snapshot, not a production security approval.

## Validation environment and ordered gate

PHP 8.4.26, Composer 2.10.3, Node 22.23.3, npm 10.9.9. Owner-approved task-only PHP CLI memory setting: 1 GiB. Production limits and assertions unchanged. No real `.env` or `.env.preview` was opened, printed, created or edited. Explicit placeholder values were used; the preview bootstrap selects an empty environment directory. No outbound mail was sent.

Task-owned PostgreSQL **18.6**, **127.0.0.1:58787**, cluster `/private/tmp/kpone-deps-1-pg18`, user `kpone_deps_1`. Every database was created with **ENCODING UTF8 TEMPLATE template0**:

- Full CI suite: `kpone_deps_1_testing`.
- Final named groups and explicit PostgreSQL replay: `kpone_deps_1_focused_testing` (separate database/storage, allowing independent execution without sharing suite state).
- Independent fresh seed: `kpone_deps_1_seed`.
- Synthetic browser rehearsal: `kpone_deps_1_preview`.

Before use, a direct PDO check printed process host, port and database, then verified current_database, server encoding and the owned cluster data_directory. PostgreSQL regression tests create/drop their own child databases. No configured-host versus server-address equality is used as proof. Full-test, focused-test, seed and live preview storage roots are separate; suite helpers use their existing per-test temporary storage, never the preview artifact root. Vendor/node_modules were copied, not shared as writable symlinks. The served tree was not switched or modified.

| Check | Final result |
| --- | --- |
| `php -l` on changed dependency PHP files | 150 dependency PHP files passed; the four PHP files brought in from main also passed. No application PHP change belongs to this patch. |
| Early Pint / PHPStan level 7 | Passed; zero PHPStan errors. |
| `composer ci:check` | Passed: 1,579 passed / 33,977 assertions / 2 existing skips / 0 failures (1,581 tests reached; 879.633 seconds). |
| Frontend types, lint, format | Passed; also run by composer ci:check. |
| `npm run build` | Passed. |
| `node --test tests/Frontend/*.mjs` | 603 passed, 0 failed, 0 skipped. |
| Explicit PostgreSQL replay | Passed: 120 tests / 1,202 assertions across 18 classes; 0 skipped, 0 failed; 91.873 seconds. |
| Separate `migrate:fresh --seed --force` | Passed on `kpone_deps_1_seed`, dummy reference/development seed only. |
| Composer manifest/lock validation | Passed, existing exact-version recommendation warning only. |
| `git diff --check`, including report | Passed for tracked changes and the new report; no whitespace diagnostics. |

The first full gate on initial base d213653 passed 1,571 tests / 33,872 assertions / 2 existing skips, and its explicit PostgreSQL replay passed 120 tests / 1,202 assertions with no skips. Those retained earlier results are not substituted for the repeated final-tree gate above.

The exact Composer script order is unchanged: disable Composer process timeout; frontend lint; frontend format; frontend types; artisan config:clear; Pint `--parallel --test`; PHPStan with the repository's 1 GiB argument; artisan test. Wayfinder generation, build and node tests were also run explicitly. Composer install/update lifecycle commands that could create an environment file were not run.

### Named evidence groups

| Group / exact selection | Tests passed | Assertions | Skips / failures |
| --- | ---: | ---: | --- |
| Authentication: `tests/Feature/Auth` | 23 | 182 | 2 existing disabled-public-registration skips; 0 failures. |
| Session: `SecurityHardeningTest --filter=session` | 4 | 22 | 0 / 0. |
| Rate limit: `ThrottleIsolationTest` | 6 | 374 | 0 / 0. |
| Inertia: `Auth/AuthenticationTest --filter=history` | 3 | 131 | 0 / 0. |
| PostgreSQL contention/regression: all 18 `Postgres*Test.php` classes | 120 | 1,202 | 0 skipped / 0 failed. |

These are overlapping selections, not counts to add to the full suite. Authentication includes login/logout/reset/verification; Inertia covers history isolation, principal changes and session loss. The two throttle frontend guards pass separately.

### Focused security proof and independent review

A private, bounded PHP probe compares the original package snapshots with the upgraded packages in separate processes. Original HeaderBag loses both Cache-Control directives for the 18/30-space input; patched parsing retains max-age 60 and private. Whitespace separators and ordinary quoted-header controls remain supported. Original TokenGuard passes array/integer credentials to a fake provider; patched user/validate rejects both without a provider call, while a valid string still authenticates. Original CommonMark emits bare `<script` and accepts mismatched table cells; patched conversion escapes the tag, refuses the mismatch as a table and preserves normal aligned tables and long plain paragraphs. A fixed-content Markdown notification also rendered successfully (10,564 HTML bytes), with no message sent. No machine-speed threshold was loosened or introduced. Initial private harness mistakes (class-map override/expected return shape) were corrected before retaining the final comparison; no application or repository assertion was changed.

A fresh read-only boundary investigator preceded the update. A separate fresh read-only candidate reviewer found no concrete surviving bypass/regression; it independently checked token variants/hash modes, JSON API depths/fieldsets, raw-tag variants, parser JIT modes, throttle source and session compatibility. This was a scoped remediation review, not a full repository security scan.

## Synthetic browser rehearsal

Headless Chromium / Playwright, owned preview origin `http://127.0.0.1:8911`; external requests blocked. Only fictional `.test` accounts/patients. The billable starting invoice was prepared with existing domain/test fixture helpers without invoking test setup, RefreshDatabase or storage fakes; the ten registrations and payment were then submitted through the real UI.

- Login succeeds; real account-menu logout returns to login; login succeeds again.
- Ten distinct existing synthetic patients were searched, selected and registered as OTC through the Registration form in one burst. No duplicate or skipped registration.
- One real UI payment of **1,000 sen** is recorded through the saved Synthetic Cash method against a **4,000-sen** finalized invoice. The page shows **1,000 received / 3,000 due**, and SQL confirms **11 visits total, one payment row, 1,000 sen**.
- Pilot-pace login/logout, registration burst and payment requests returned **zero 429s**.
- Deliberate overrun of `POST /registration/search` retains its **60/minute** limit and returns **429 with Retry-After**. `POST /patients/search` still returns **200** for the same session. The real board displays `Too Many Attempts.` after a refused manual request. The retained final overrun loop recorded 60 HTTP 200 responses followed by one 429 with Retry-After 57 seconds; the subsequent manual board request also returned 429 for the visible refusal capture.

The private automation was adjusted for actual UI names/menu steps and browser fetch Response properties; those were harness defects, not application failures or permission to alter product behaviour. No second payment was submitted while fixing the harness. Screenshots were inspected at 390 and 1440 px; no real patient data or credential is in them. The 390 px visit-detail capture shows its pre-existing action-row overflow (full-page image extends beyond the viewport); the page source and all npm dependencies are unchanged, so no layout repair is included. Physical devices, a production proxy/cache/session backend and outbound delivery were not tested.

Screenshot pairs (headless, local private artifacts, not committed):

- `login-390.png`, `login-1440.png`.
- `logged-out-390.png`, `logged-out-1440.png`.
- `registration-burst-complete-390.png`, `registration-burst-complete-1440.png`.
- `payment-dialog-390.png`, `payment-dialog-1440.png`.
- `payment-recorded-390.png`, `payment-recorded-1440.png`.
- `deliberate-429-390.png`, `deliberate-429-1440.png`.

## Every changed assertion and changed file

**Existing assertions changed: none.** No existing test file, expected value, fixture, test name, timeout or skip changed. No new repository test was needed to replace existing coverage; the security probe and browser harness are private validation artifacts. The complete tracked/new-file diff is:

| File | Change |
| --- | --- |
| `composer.lock` | Exactly five required runtime package entries at the versions above. |
| `docs/reports/DEPS-1.md` | Survey, scope, validation, remaining advisories and evidence. |

## Evidence, limits and handoff

Persistent private evidence collection: `/Users/klinikputrijaya/.codex/state/plugins/codex-security/scans/KPOne-deps-1/artifacts-08a79f9c969bc3a9a93b337f54dd75814ad08856669eb65e94c632689307b667/artifacts/deps-1/`. Gate/audit logs, security comparison, step/request logs and the screenshot pairs are retained there; browser session state and dummy credentials are not retained as review evidence or committed.

All task-owned preview services and the PostgreSQL cluster are stopped. The synthetic databases/storage remain local for review. The served tree remains on main and was not modified. The isolated worktree is intentionally dirty with only composer.lock and this new report. No commit, push or PR.

The targeted advisories are closed; the whole dependency audit is not clear. phpseclib, development YAML and npm maintenance need separate scope. Deployment verification, real-data approval, upstream proxies, production session/cache drivers and customised mail templates remain outside this synthetic gate. No new permission, migration or application feature. Before any future PR, recheck main, merge it if it has moved again, and perform the owner-requested checks. No commit, push, PR or production approval is authorised by this report.
