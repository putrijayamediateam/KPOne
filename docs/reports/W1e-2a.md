# W1e-2a — deterministic static renderer and private artifacts

Status: **local candidate, uncommitted pending owner review. Not production-approved.**
Branch: `feature/w1e-2a-renderer`; independent base `4e375f9` (fetched `origin/main`, contains PRs #165–#168). Job 1 is separately open as [PR #169](https://github.com/putrijayamediateam/KPOne/pull/169); this branch does not include it. No push, PR, merge or served-tree change for Job 2.

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

The candidate is intentionally uncommitted until owner review; no candidate commit SHA is claimed. The working branch still points to base `4e375f9`. Private logs and the complete uncommitted patch are under the evidence folder.

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
