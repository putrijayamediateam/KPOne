# W1e-2a — deterministic static renderer and private artifacts

Status: **PR #170 candidate; CI correction and required main integration validated. Not production-approved.**
Branch: `feature/w1e-2a-renderer`; original independent base `4e375f9` (contains PRs #165–#168). The original reviewed candidate was committed as `f5d198a` and opened as [PR #170](https://github.com/putrijayamediateam/KPOne/pull/170). The original gate/evidence below are retained as history; the CI investigation and subsequent validation are recorded at the end. No served-tree change.

## Scope and inputs

An approved release plus its approved, bound output plan produce a review-only static artifact. The source adapter rechecks canonical hashes, exact item/media rows and retained media hashes, lengths, MIME and dimensions, then constructs a second explicit public allowlist. It reads only the release, item, media, plan, plan-asset and approval-control tables. No live draft, staff, clinical, financial, settings or old-website source is used for public content.

`WebsiteStaticRenderer` uses an isolated Blade factory. Its constructor loads and compiles fixed repository templates and loads the fixed CSS/JS; `render()` uses those in-memory programs only, without filesystem, database, Storage facade or network access. Only trusted repository Blade is compiled as PHP; DTO values are arguments and escaped with Blade, never compiled as code. No application view composers, shared account/session data, Vite or application layout enter the public output. The recipe digest pins the renderer, in-memory engine, profile, views and resources. Binary images remain byte-identical.

The new Website builds block is inside the existing private release detail page: approved-plan selector, both source numbers and hashes, disabled reasons, build states and a plain file index. No file link or download exists. Branch switching clears the loaded controls. Current staff-name lookup behaviour is unchanged; the build block adds no staff lookup.

## Permission, writes and audit

No new permission or grant. Build uses `website.releases.prepare.organisation`: Director, Marketing and Administrator, with fresh organisation, active effective-branch and permission checks at the start and again at completion. Existing release-page access remains unchanged. Director-only approval and website-role provisioning are unchanged.

The authenticated POST `/website/releases/{release}/builds` is the only new route (existing private/no-store middleware, existing permission and 10/minute actor throttle). It serves no files. No public route, preview, ZIP, artifact approval, queue, job, deployment, Cloudflare call, import or analytics.

New migration: `2026_10_28_000100_create_website_artifacts.php`. Five new tables: `website_artifacts`, `website_artifact_files`, `website_artifact_attempts`, `website_artifact_actions`, `website_artifact_counters`. `down()` retains all evidence. Existing release/plan tables, guards and grants are untouched. Existing databases need `php artisan migrate`.

The new file index supports `generated`, `release_media` and **owner-authorised `plan_asset`** (the explicit follow-up approval). Optional plan logos refer to `website_output_plan_assets` through `plan_asset_id`, without changing an old table or copying an image again. Matching release/logo hashes deduplicate to one retained reference. Generated files alone are encrypted using Crypt on the new private local `website_artifacts` disk, in unpredictable per-attempt directories; there is no public disk/symlink.

Lock order: authority/organisation, release, release control, plan, plan control, attempt; the numbering counter is locked at completion. UUID retry keys bind the exact payload and recipe; matching retries return their existing attempt, conflicting payloads refuse. Requested/building audit and source capture commit before rendering; rendering runs twice outside the locks. A short final transaction rechecks authority, approval, versions and hashes, then atomically records the immutable header/index, built state, counter and audit. Numbers are `WEBBUILD/{branch-local year}/{6 digits}`; an organisation with differing active-branch timezones is conservatively refused rather than guessing the numbering year.

PostgreSQL refuses update/delete/truncate of immutable headers, files and actions; attempts permit only requested→building/failed and building→built/failed with version +1 and immutable identity. Terminal attempts cannot restart. Counters are monotonic and retained. Approved exact source binding is enforced at header insertion; file references must belong to that release/plan. A deferred constraint requires the full sorted file manifest, count, total and built action before commit. Index insertion closes once built.

Audit records only identifiers, numbers, action, hash prefixes, count and total bytes. No content, exception body, recipient, personal information or storage keys. Audit failure rolls back its step. Failure exposes no artifact and cleans only that attempt's staging; uncertain commit outcomes retain files rather than risk deleting a committed artifact. A killed request leaves an honest lease; the next authorised build reconciles expired attempts to failed, with bounded `interrupted` code and no scheduler. Cleanup failures may leave unindexed encrypted bytes; a matching retry can attempt cleanup again. No retention/purge is introduced.

## Output and review profile

EN root, BM `/bm/`, `lang=en/ms`, visible language switch and paired hreflang. Selected page set only: home; branches/detail aliases; doctors; services/category filter; shared `service-detail/?slug=` shell; promotions; activities; careers; Little Shield; FAQs; contact. Both languages have 404 output. All text is escaped. External local-data JS uses textContent/createElement, same-origin credential-free JSON, validated query keys and aliases; unknown slugs show unavailable with noindex. No forms, cookies, trackers, inline scripts/styles, remote resources, embeds or automatic language redirect. Public links are limited to frozen map/application HTTPS links, public email mailto and frozen-number wa.me. No other message integration.

One h1, explicit landmarks and skip link, visible focus, frozen-language image alt, reduced-motion support, system font and fixed profile contrast pairs. CSS prints without navigation. Only the fixed clinic wordmark is microdata; no invented medical assertions, statistics or history. Service title/description/canonical/hreflang/OG are updated from frozen local JSON after selecting a known slug.

Profile is `review-noindex-v1`: meta noindex,nofollow on every page; robots disallows all; `_headers` adds noindex, CSP and privacy/security headers. Sitemaps are **not for submission**. A production profile requires a separate authorisation.

Directory indexes are an explicit host assumption: the future static host must resolve `/branch/` to `/branch/index.html`. Verify trailing slash handling, MIME and `_headers` support, custom EN/BM 404 status and security headers before cutover. The temporary Python harness does not interpret `_headers` or automatically map missing routes to the custom 404; the evidence opens the generated 404 explicitly. Shared service shells require JS and are a known SEO/soft-404 consideration for the later parity review. No redirects, deployment or production SEO claims are included.

Reviewed constants, defined once in `WebsiteOutputProfile` (existing frozen design definition/digest unchanged):

| Constant | EN | BM |
|---|---|---|
| Wordmark | Klinik Putrijaya | Klinik Putrijaya |
| Home line | Clinic information and services. | Maklumat dan perkhidmatan klinik. |

Palette tokens remain `white #ffffff`, `ink #18181b`, `soft #fff1f7`, `pink #ff46a5`, `brand #be185d`, `border #71717a`. The unchanged frozen contrast pairs are:

| Pair | Contrast |
|---|---|
| ink / white | 17.72:1 |
| ink / soft | 16.18:1 |
| brand / white | 6.04:1 |
| brand / soft | 5.51:1 |
| white / brand | 6.04:1 |
| ink / pink | 5.63:1 |
| border / white | 4.83:1 |
| border / soft | 4.41:1 |

Owner confirmation of wording remains pending. About is absent. The first release uses the text wordmark; the optional frozen-logo path is tested, including a logo not present in release media. No custom home narrative or logo sourcing is invented.

## Limits and benchmark

Named `WebsiteArtifactBudget` constants: 2,000 files; 250 MiB plaintext; 30 seconds across the two renders; 512 MiB PHP allocated-memory ceiling. All refuse; never truncate. Reader preflight checks estimated count and media volume, with incremental checks during assembly/render. The actual file set is checked again before staging.

Final synthetic maximum-file benchmark: 934 branch detail pairs, all page families and 100 distinct sanitised synthetic PNGs (1200×700), **2,000 files, 261,698,426 bytes (249.58 MiB)**. Two byte-identical renders: **0.534 seconds**; process peak **312,672,256 bytes (298.19 MiB)**. This measures the pure renderer including in-memory Blade, not fixture generation, PostgreSQL capture, encryption/staging or complete HTTP latency. It is not a production capacity claim. The exact byte cap, over-file cap, deadline and memory refusal paths are separately tested. Private benchmark script/result remain ignored under `storage/app/private/renderer-tools` and the evidence folder.

## Tests and existing assertions

New `WebsiteArtifactBuildTest` / isolated `WebsiteArtifactTestCase`: rich deterministic builds and verify; source isolation; renderer purity; privacy sentinels; DOMDocument HTML/XML, one h1, lang/hreflang, alt, internal links, no inline/forms, JS safety and exact palette values; full ID-ordered comparison of every unrelated table including populated billing; historical stability after draft/staff-name/live-asset changes; every catalogue role and fresh revocation; retry/payload conflict; withdrawn source; all resource limits; final withdrawal/fresh revocation; audit rollback at requested and completion; encrypted generated-file tampering; interrupted lease reconciliation with own-prefix-only cleanup; immutable delete/update/truncate and late-index refusal; approval/plan binding/branch/organisation refusal; distinct plan-logo reference; canonical evidence/media integrity helpers; nondeterminism refusal; deferred completeness and terminal version enforcement.

New frontend test compiles the block and pins its private controls, branch clearing, source hashes, plain manifest and absence of file links or serving controls, plus the local JS safety boundary.

**Every changed existing assertion: none.** No tracked existing test file is changed. The new fixture reuses the established synthetic source shapes without modifying their original tests. No test is weakened, removed or newly skipped. Only the two existing public-registration skips remain.

## Gate

An initial full run stopped in the new deferred-completeness test: the database returned the expected P0001 but the test caught QueryException instead of the PDOException raised by commit. Only that new catch was corrected; P0001 and the unchanged-row checks remain exact. The focused 17-test suite then passed (1,417 assertions). No existing assertion failed or was edited.

Task-owned PostgreSQL **18.6**, `127.0.0.1:58523`, user `renderer`, DB `kpone_renderer_testing`; separate fresh/seed DB `kpone_renderer_seed_testing`; synthetic Chrome fixture DB `kpone_renderer_preview_testing`. Effective host/port/database were verified before the first request/connection. No other database or .env was used.

| Check | Result |
|---|---|
| `php -d memory_limit=1G artisan test --compact -d memory_limit=1G --stop-on-failure --stop-on-error` | **1,407 passed, 29,227 assertions, two existing skips** (1,409 total; 616.843 seconds) |
| Full Pint `--test` | Pass |
| PHPStan level 7, 1G | Pass, zero errors |
| `npm run types:check` | Pass |
| `npm run lint:check` | Pass |
| `npm run format:check` | Pass |
| `npm run build` | Pass |
| `node --test tests/Frontend/*.mjs` | **485 passed**, zero failures/skips |
| `migrate:fresh --seed --force` on separate owned seed DB | Pass |
| `git diff --check origin/main`, including new-file checks | Pass |

The preceding gate table records the original pre-commit validation at base `4e375f9`, later committed as `f5d198a`. Its private logs and original patch remain under the evidence folder. The PR #170 CI correction and latest gate are recorded below.

## Evidence and generated fixture files

Private, ignored, uncommitted evidence: `storage/app/private/w1e-2a-evidence/`. The only public-output viewing method is the authorised throwaway materialisation harness, which verifies/decrypts the artifact internally into `/private/tmp/renderer-site` and serves it on loopback port 54524. This is not a feature preview route. The private KPOne UI ran separately on loopback 54523 against the owned preview DB.

`static-captures.json`: 30 real Chrome screenshots at exactly 1440/768/390 CSS px: home, branch, category-filtered services, known/unknown detail, doctors, Little Shield, contact, BM home, generated 404. `private-captures.json`: four captures of the actual Website builds block at 1440/390 in light/dark. All recorded document widths equal viewport widths; no sideways overflow. No real data or external link was used. Screenshots are local artifacts, not PR attachments or committed files. The optional logo is exercised by automated tests, while the pictured first-release fixture correctly has no logo.

The fixture has **39 files / 108,844 plaintext bytes**. `renderer-file-manifest.json` records each size/MIME/hash; `renderer-materialise.log` pins the final artifact and proves screenshot-harness bytes identical. The file list is:

```text
404.html
_headers
activities/index.html
assets/img/d7e6e2ba76bcb886cd02ed6434d5618757875073ae3c29a77a7e4e6a820e8fa2.png
assets/site.css
assets/site.js
bm/404.html
bm/activities/index.html
bm/branch-synthetic-0/index.html
bm/branch-synthetic-1/index.html
bm/branch-synthetic-2/index.html
bm/branches/index.html
bm/careers/index.html
bm/contact/index.html
bm/doctors/index.html
bm/faqs/index.html
bm/index.html
bm/little-shield/index.html
bm/promotions/index.html
bm/service-detail/index.html
bm/services/index.html
branch-synthetic-0/index.html
branch-synthetic-1/index.html
branch-synthetic-2/index.html
branches/index.html
careers/index.html
contact/index.html
data/services.bm.json
data/services.json
doctors/index.html
faqs/index.html
index.html
little-shield/index.html
promotions/index.html
robots.txt
service-detail/index.html
services/index.html
sitemap-services.xml
sitemap.xml
```

## Readiness, deferred scope and owner questions

- Confirm the wordmark, neutral EN/BM home lines, fixed navigation labels and palette wording before any production profile. Fixed `kp-soft-v1` look remains light-only; private KPOne retains light/dark.
- About is not included in the first release; logo added later through a reviewed frozen plan. No custom home copy or unverifiable clinic claims.
- Future static-host behaviour, public origin, custom 404 status, headers and service-detail SEO parity need verification before cutover. `https://clinic.test` is synthetic only.
- Mixed active-branch timezones currently refuse a build; decide a separate organisation numbering timezone if ever needed. Today the reference branches share Asia/Kuala_Lumpur.
- A future private preview adapter must authorise every read; the internal integrity materialiser is not an HTTP access policy or serving endpoint.
- Failed encrypted staging may need an explicit operational cleanup policy later; no background cleanup or purge is authorised here.
- Preview/file serving, ZIP/download, artifact approval, production profile, deployment/Cloudflare, redirects, settings, About, appointment intake, feedback, analytics, old-site import/use and new permissions remain absent and unauthorised.

## PR #170 CI investigation (2026-10-26)

Failed candidate: `f5d198a5e7e891d0f162f466d98a13ae02fb3464`, GitHub run `40100568070`. The complete failed log was retrieved with `gh run view --log-failed`; it was not necessary to infer the failure from container startup logs. The failing command was `composer ci:check`, specifically its final `php artisan test`. ESLint, Prettier, Vue type checking, Pint with `--parallel --test`, and PHPStan with `--memory-limit=1G` had passed. GitHub reported **7 failed, 1,400 passed, two existing skips, 29,150 assertions, 873.95 seconds**.

The first failure, `test_rich_frozen_build_is_deterministic_and_the_private_index_verifies`, was exactly:

```text
render
Failed asserting that two strings are identical.
-'built'
+'failed'
```

PostgreSQL logged:

```text
ERROR: Incomplete built artifact
CONTEXT: PL/pgSQL function website_artifact_complete() line 6 at RAISE
STATEMENT: COMMIT
```

The other six failures were in the same new test class: source-row stability (null artifact), retry/source withdrawal (zero rather than one artifact), encrypted-file tampering (null artifact id), immutable statements (the counter is empty after the failed fixture build), plan-logo references (null artifact id), and deferred completeness (no artifact row). These are consequences of the same failed build, not seven independent renderer failures.

Exact hosted failure locations at `f5d198a` (all in `tests/Feature/Website/WebsiteArtifactBuildTest.php`):

| Test method | Location | Failure output |
|---|---|---|
| `test_rich_frozen_build_is_deterministic_and_the_private_index_verifies` | 33 | `render`; `Failed asserting that two strings are identical.`; expected `built`, actual `failed` |
| `test_build_changes_no_source_rows_and_live_edits_do_not_change_historical_output` | 164 | `Failed asserting that null is not null.` |
| `test_retry_conflict_stale_versions_and_source_withdrawal` | 212 | `Failed asserting that 0 is identical to 1.` |
| `test_tampered_generated_bytes_are_refused_and_retained_media_are_references` | 290 | `Attempt to read property "id" on null` |
| `test_postgresql_refuses_immutable_changes_and_late_file_insertion` | 331 | `Immutable statement accepted` |
| `test_plan_only_logo_is_referenced_without_copying_and_integrity_helpers_refuse_tampering` | 391 | `Attempt to read property "id" on null` |
| `test_postgresql_deferred_completeness_and_terminal_versions_are_enforced` | 453 (call), framework `BuildsQueries.php:403` | `RecordsNotFoundException` (no message) |


### Workflow command order and settings

After candidate/ref/tree attestation and PHP/Node setup, `.github/workflows/tests.yml` executes these commands in this exact order:

```sh
cp .env.example .env
composer install --no-interaction --prefer-dist --no-progress
npm ci
php artisan wayfinder:generate --with-form --no-interaction
shopt -s failglob
node --test tests/Frontend/*.test.mjs
php artisan migrate --force
npm run build
composer ci:check
```

Composer install also executes its normal lockfile hooks: `Illuminate\\Foundation\\ComposerScripts::postAutoloadDump` and `php artisan package:discover --ansi`. `composer ci:check` expands in order to:

```text
Composer\Config::disableProcessTimeout
npm run lint:check             -> eslint .
npm run format:check           -> prettier --check resources/
npm run types:check            -> vue-tsc --noEmit
php artisan config:clear --ansi
pint --parallel --test
phpstan analyse --memory-limit=1G
php artisan test
```

There is **no PHPUnit memory override** in the workflow. Its pinned `setup-php` action selects PHP 8.4, production INI and coverage `none`, without an explicit extension list or `ini-values`. The pinned action's `configure_php` appends its checked-in defaults, including `memory_limit=-1` and `date.timezone=UTC`. Xdebug and PCOV are disabled. The action also appends `opcache.enable=1`, `opcache.jit_buffer_size=256M`, and `opcache.jit=1235`; CLI OPcache remains off (`opcache.enable_cli=0`). The completed fixed replay explicitly matches those values too. The application artifact budget is independently 512 MiB and remains unchanged. The actual hosted run used PHP 8.4.27, Composer 2.10.3, Node 22.23.3 and PostgreSQL 18.6 with `en_US.utf8` collation.

Local replay uses only disposable checkouts with `.env` copied from `.env.example`, a new task-owned PostgreSQL 18.6 cluster at **127.0.0.1:58526**, `en_US.UTF-8`, UTC, user `postgres`. The original replay DB is `kpone_test`; the fixed replay DB is `kpone_ci_fixed_testing`; the isolated diagnostic DB is `kpone_ci_probe_testing`. All host/port/names were verified. No served-tree request or real environment file is used.

Reproduction limitation: this machine is macOS/ARM, not Ubuntu/x86. The configurable Homebrew PHP binary is **8.4.26**, one patch older than the hosted run; it runs with the action's effective unlimited CLI memory setting, UTC and no Xdebug/PCOV. Required extensions including pgsql/PDO, GD, EXIF, mbstring, intl, DOM/XML, zip and sodium are loaded. Composer and Node match the hosted versions. The installed Herd PHP 8.4.27 forces its own global 128 MiB configuration, so it was not used for the completed replay. An initial diagnostic with that incorrect 128 MiB setting exhausted memory in an unrelated billing test; it is discarded as a settings mismatch, not evidence of this CI failure. No global PHP configuration was changed.

### Cause and bounded fix

The PHP file manifest uses `ksort` (byte order for these allowlisted paths). The new deferred PostgreSQL completeness guard used locale-sensitive `ORDER BY path`. In the new `en_US` database, the observed order is `_headers,404.html,...`; byte order is `404.html,_headers,...`. JSONB preserves array order, so the complete, correctly staged artifact is rejected at commit. The original rich-build failure reproduced in **1.422 seconds**, far below the 30-second render budget. The complete original-head sequence then reproduced exactly **1,400 passed, seven unsuccessful tests (four assertion failures and three errors), two existing skips and 29,150 assertions**, in **642.421 seconds**. `composer ci:check` returned exit 2. The failed test names and failure messages match the hosted run.

The fix changes only the new migration's aggregate ordering to `ORDER BY path COLLATE "C"`. Counts, summed bytes, exact manifest equality, the required built action, append-only guards and every production budget remain intact. No renderer output, file bytes or canonical PHP hash is changed. This is a correction to the as-yet-unmerged artifact migration; an installation that already ran the earlier candidate migration will not rerun that file automatically and needs a separately reviewed forward repair before reuse. Disposable validation databases are rebuilt.

New regression `test_completion_manifest_uses_byte_order_even_with_a_locale_sorted_path_column` explicitly uses an ICU English collation for the file path column, proves it differs from byte ordering, then requires a successful complete build, exact byte-ordered manifest and successful integrity verification. Its pre-fix negative control failed with the same `render` / `built` versus `failed` result. No existing assertion is changed, weakened, removed or newly skipped.

The 2,000-file / 249.58 MiB benchmark is an ignored manual renderer harness, **not a fixture executed by this workflow**. Normal CI rich-build fixtures are tens of files. The expired-lease test establishes a lease five minutes in the past rather than sleeping for a machine-dependent interval. Each artifact test uses a fresh database and `Storage::fake('website_artifacts')`; the completed replay starts from separate checkouts/private storage and does not reuse prior evidence. The budget refusal test uses small injected budgets to exercise the same production logic and retains its assertions. Disk had approximately **769 GiB free**. The observed CI failure is not a deadline, memory or disk refusal; no production limit or timing assertion is loosened.

### Main integration

Fetched `origin/main` at `6174586` (PR #169). The read-only merge check found a conflict **only in `docs/BASELINE.md`**. A normal merge was therefore started as requested. Resolution keeps main's merged output-plan status and owner decisions, plus the renderer row and its explicit scope. The plan-name implementation/tests arrive unchanged from main. They are not assertion changes made for this fix. The fix and this required integration are recorded in one merge commit after validation.


### Fixed candidate: completed CI replay and full gate

The complete fixed workflow sequence returned **exit 0** after the required main integration. Backend: **1,410 passed / 29,270 assertions / two existing skips** (1,412 total), **636.937 seconds**. The additional tests relative to `f5d198a` are the two already-merged plan-name tests from PR #169 and the one new locale regression. No existing assertion was changed by the fix. Imported main files match `origin/main` exactly except the documented baseline resolution.

| Check | Result |
|---|---|
| Ephemeral example environment; locked Composer install; `npm ci` | Pass |
| `php artisan wayfinder:generate --with-form --no-interaction` | Pass |
| `node --test tests/Frontend/*.test.mjs` with `failglob` | **486 passed**, no failures/skips |
| `php artisan migrate --force` | Pass |
| `npm run build` (`vite build`) | Pass |
| `npm run lint:check` / `format:check` / `types:check` | Pass |
| `php artisan config:clear --ansi` | Pass |
| Pint `--parallel --test` | Pass |
| PHPStan level 7 `--memory-limit=1G` | Pass, zero errors |
| `php artisan test` (workflow settings, no manual memory override) | **1,410 passed, 29,270 assertions, two existing skips** |
| Separate owned `kpone_ci_seed_testing`: `migrate:fresh --seed --force` | Pass |
| Broader `node --test tests/Frontend/*.mjs` | **486 passed**, no failures/skips |
| `git diff --check` | Pass |
| Immediate post-suite artifact rerun (same checkout/database, fresh fake disk) | **18 passed / 1,421 assertions**, 22.171 seconds |

The validated runtime/test files were byte-compared with the feature worktree before committing. Investigation logs remain local at `/private/tmp/w1e2a-ci-original-logs/` and `/private/tmp/w1e2a-ci-final-logs/`; effective PHP settings are recorded in the latter's `php-settings.json`. No logs, database files, environment copies or private evidence are committed. The served tree remains clean on `main`. No other branch is pushed and the PR is not merged.
