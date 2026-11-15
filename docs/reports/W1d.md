# W1d — Promotions, activities and FAQs drafts

Status: **local candidate**, owner-authorised 2026-10-22. Branch `feature/w1d-website-promotions-activities-faqs`, based on fetched canonical main **8da1ea0** (merged W1c, PR #158). Served checkout remains clean on local main, untouched. No push, PR or merge. No .env was opened or created.

## Scope and content

**DRAFT-ONLY. Nothing is public.** Manual content for exactly `promotion`, `activity`, `faq`, independent EN/BM text, saved-only private previews. No code from or runtime dependency on the old website. Empty initial lists; no content seed/import or operational lookup. No structured numeric-price fields, discount/coupon codes, booking/contact fields or behaviour. Intent flags and dates trigger nothing.

| Type | Per-language fields and limits |
| --- | --- |
| Promotion | Title 120; short summary 300; up to 6 details sections (heading 100, body 1500 each); up to 8 terms of 200; optional offer text 80 with required display-approval reference 200 when used; optional valid-from/until dates; optional cover; branch availability; sort order; include-on-website intent |
| Activity | Title 120; short summary 300; up to 6 description sections (heading 100, body 1500 each); optional start/end dates; location note 150; optional cover; up to 12 distinct gallery assets in explicit order; branch associations; sort order; include-on-website intent |
| FAQ | Question 200; answer 1500; optional linked active public-service UUID; sort order; include-on-website intent. No branches or media fields |

Text is bounded and escaped, no HTML or common Markdown. Control (including newline), bidi, zero-width and invalid UTF-8 input refuse before trimming. Dates are strict YYYY-MM-DD calendar dates, retained as typed, with the effective branch timezone stated in the editor. No instant conversion, automatic expiry/hiding, schedule, reminder, notification or outbound call. Either date may be omitted; when both supplied, end cannot precede start. Equal dates and New Year spans are allowed.

Sort order uses -100000 to 100000; at most 100 distinct active organisation branches; lists 20 rows, history 12 revisions per page. Promotion/activity completeness needs title, summary, a section with heading/body and at least one branch. FAQ needs question/answer. Optional dates, offers, images and FAQ link do not affect completeness. Incomplete private drafts may be saved. Offer approval is a manually entered reference, never verified or calculated against Pricing.

## Files, migration and retained foundation

New files:

- `app/Domain/Organisation/Website/WebsiteEditorialFields.php`: allowlisted budgets, plain text, dates and completeness; reuses W1c's text cleaner unchanged.
- `WebsiteEditorialService.php`: scoped lists/filtering, language revisions, history, transactional save/archive/unarchive, item-wide reference consistency and fresh media checks.
- `app/Http/Controllers/WebsiteEditorialController.php`: private page and explicit named-route redirects, no bare back().
- `resources/js/pages/Website/Editorial.vue`, `resources/js/types/website-editorial.ts`, `resources/js/components/website/EditorialDraftPreview.vue`: responsive EN/BM drafts, read-only mode, dates, media order, references and escaped saved preview.
- `database/migrations/2026_10_24_000600_extend_website_editorial_drafts.php`.
- `tests/Feature/Website/WebsiteEditorialTest.php`, `tests/Frontend/website-editorial.test.mjs`.

**No new tables.** The additive migration expands `website_type_only` from five allowed types to the same five plus promotion/activity/faq, with null item `branch_id` for each. The branch-profile partial unique index, immutable identity/version rules, append-only revisions/commands and existing W1c guards remain untouched. Reuses `website_content_items`, `website_content_revisions`, `website_content_item_branches`, `website_draft_commands` and existing sanitised media. Adds new-type-only PostgreSQL guards for cross-language references, active same-org service links/media and FAQ branch refusal. Functions use CREATE OR REPLACE so repeated fresh test schemas work when PostgreSQL retains functions after dropping tables. `down()` deliberately retains evidence and protections.

No W1a/W1b/W1c domain class, page, migration, permission, grant or authority rule was changed. The sole W1b backend test edit is the expressly authorised rejected-type literal below. The new service narrowly follows W1c rather than generalising its behaviour. Shared changes are only three authenticated private route families, hub destinations, excluding these pages from the existing shared pending-intake lookup, and preserving raw text on the new paths for control refusal. Every previous route's trimming and authority remain unchanged. AGENTS.md is untouched.

## Item-wide references and revision evidence

- Each revision saves the FAQ service reference and sorted branch-id snapshot alongside its language text. Current branch associations use the existing child table for promotions/activities only; FAQs refuse them in both application and PostgreSQL.
- A save locks existing organisation/actor/branch authority, then item and associated branch/reference/media rows. If the other language has a current revision, its branches and FAQ service reference must exactly match; otherwise the save refuses with an explicit item-wide message. PostgreSQL independently checks the other language on new revision insertion, with case-insensitive UUID comparison. New UUID references are canonical lower-case.
- For a not-yet-written translation, only item-wide branch/link choices are preselected from the existing language; text is empty. No content is copied from clinical, staff or old-website data. A future publisher must read the selected revision, never live associations silently.
- FAQ links must point to an active `public_service` of the same organisation at save; missing, foreign, category/other-type and archived targets refuse. Existing history remains readable after a service is archived. Service archival behaviour is unchanged; FAQs do not block a W1c archive.
- Item identities/slugs never change. Archive/unarchive advances independent item version and audit without deleting revisions. No rewrite of the other language or earlier evidence.

## Permissions, safety and audit

**No new permission or grant.**

| Existing permission | Exact default holders | W1d use |
| --- | --- | --- |
| `website.content.view.organisation` | Director, Marketing, HR Manager, Administrator | Lists, revisions/history and private preview |
| `website.content.manage.organisation` | Director, Marketing, Administrator | Create/save/archive/unarchive |
| `website.assets.manage.organisation` | Director, Marketing, HR Manager, Administrator | Existing media selection; uploads stay in W1a library |

HR Manager is view-only for all three new types. Every other catalogue role gets nothing new. Provisioning Marketing, HR Manager and Administrator remains **Director-only**. `canAssignRoles`, `canManage` and every staff-authority rule are unchanged.

Every mutation uses existing WebsiteAccess for fresh backend permission, organisation and effective branch checks inside a transaction, row locks, item lock_version, UUID idempotency key with keyed payload-conflict refusal, append-only command evidence and audit-failure rollback. Identical retries return the original result, do not duplicate revisions/audit. Stale and archived edits refuse. Reads require fresh existing view authority; sensitive no-store routes remain authenticated and CSRF/session protections remain.

Media must be active sanitised same-org assets. Identifiable-person assets require their existing nonempty consent reference; newly selecting media requires fresh existing media permission. Gallery order is retained, max 12 case-insensitively distinct UUIDs. Cover may also occur in the distinct activity gallery. Retired assets cannot be newly attached or retained in a newly saved revision: clear the cover/remove gallery entries, then save; old evidence retains old references and image access remains through W1a. The editor explicitly shows unavailable cover/gallery choices and a removal path. No new upload endpoint.

`website.editorial.save`, `.archive`, `.unarchive` audit only item id, version, revision number, language and field names. No body, offer, approval, asset label, personal/clinical text or values of references. Failure to record audit rolls the whole action back.

`test_no_prohibited_source_reads_network_jobs_or_scheduler_and_all_unrelated_rows_identical` proves query-log isolation, HTTP/shared-middleware isolation, no HTTP/job, unchanged scheduler registry, and ID-ordered full-row invariance after each create/view/update/archive/unarchive for all three types. Operational financial/clinical synthetic evidence, retained branch/doctor/career/service content and all other tables are compared; only declared W1d rows and audit may differ. Acting-user identity/staff queries must match the existing authority check exactly. No patient/Visit/Queue/clinical/medicine/Pricing/billing/dispensary/stock/appointment/staff/credential source is used for content.

## Tests and every changed existing assertion

New backend suite: **10 tests, 1,064 focused assertions**, including every catalogue role's exact existing view/manage and all mutation denials; permitted roles create/edit; fresh cached role/direct grant and effective-branch revocation; org/branch and foreign references; stale versions/retries/conflicts; language text independence and cross-language branch/service refusal; every text/list/section/date/flag budget; markup/control/invisible refusal; offer reference; active/wrong-type/category/foreign/archived FAQ links; branch refusal; 12-media order, consent, foreign/missing/retired and removal; immutable slug/history and database update/delete/truncate protection; audit rollback/no content; complete unrelated-row and query isolation. Existing foundation suites run intact except exact navigation and the authorised type-allowlist fixture below.

New frontend suite: **5 tests**: editor/preview Vue compilation and budgets, permission-filtered writes, clearing all loaded selections/history on branch switch, responsive structure, three exact destinations, explicit redirects, absence of integration/scheduler and actual SSR escaping plus section/gallery order.

Exactly **three changed existing assertion groups**, authorised navigation rule changes:

1. `tests/Feature/ClinicManagementTest.php`, `test_hub_returns_only_authorized_implemented_destinations_for_every_catalogue_role`: old exact map has no Promotions/Activities/FAQs. New exact map adds `/website/promotions`, `/website/activities`, `/website/faqs`, all requiring existing website.content.view.organisation. Reason: these private destinations are newly authorised. All old destinations and role-by-role exact equality retained.
2. `tests/Frontend/website-services.test.mjs`, `Both destinations use existing view authority and forbidden families stay absent`: old prohibited regex `/website\.publish|website\.requests|website\.promotions|website\.activities/`; new `/website\.publish|website\.requests/`. Reason: Promotions/Activities are now authorised; publish/requests remain forbidden. New W1d tests positively pin all three new destinations. No other assertion in this file changed.

3. `tests/Feature/Website/WebsitePeopleTest.php`, `test_postgresql_immutability_type_constraint_and_existing_branch_profile_preserved`: old rejected-type fixture is `promotion`; new rejected-type fixture is `little_shield`. Reason: the owner expressly authorises the promotion type in W1d, while Little Shield remains unauthorised. This changes only that type literal, retaining the database-rejection assertion and every update/delete/truncate/identity/branch-profile assertion. The first full gate exposed this authorised type-allowlist change; no production access/authority rule changes.

No permission-list or staff-authority assertion needed changing. No existing test weakened, removed or newly skipped. New-code development failures were corrected without changing old tests: retained PostgreSQL functions after fresh schema drops, local route typing generation with form variants, upload fixture signature and verifying denied status rather than an optional exception message. The stale branch test pins the existing expected_branch_id validation refusal, not a new authority contract.

## Validation

Task-owned PostgreSQL **18.6**, host **127.0.0.1**, port **58461**, user **w1d**, backend DB `kpone_w1d_testing`; separate fresh-seed DB `kpone_w1d_gate_seed_testing`; Chrome DB `kpone_w1d_seed_testing`, preview port 54401. Server tuple and effective connection configuration were checked before first HTTP request, with explicit environment wrapper and no .env. Existing local dependencies copied from W1c, no installation/network/dependency change. Only git fetch origin main used outside local validation; no non-owned database.

| Gate | Final result |
| --- | --- |
| Full backend, PG18, PHP memory override | **1,340 passed, 25,512 assertions, two existing public-registration skips** (1,342 total), 541.079 seconds; exit 0 |
| Focused W1d backend | **10 passed, 1,064 assertions**; exit 0 |
| Frontend `node --test tests/Frontend/*.mjs` | **472 passed, zero failures/skips**; exit 0 |
| Whole-repository Pint | Passed; exit 0 |
| PHPStan level 7 | Passed, zero errors; exit 0 |
| `npm run types:check` | Passed; exit 0 |
| `npm run lint:check` | Passed; exit 0 |
| `npm run format:check` | Passed; exit 0 |
| `npm run build` | Passed; exit 0 |
| Separate `migrate:fresh --seed` | Passed; exit 0; three existing branch-profile seeds only, zero W1d seeds, unchanged five website permissions |
| `git diff --check` and staged whitespace check | Passed; exit 0 |

The two skips remain the existing disabled Fortify public-registration tests. No new or PostgreSQL test skipped; no test weakened or removed. Final logs and exit codes are private in `storage/app/private/w1d-evidence/{backend,pint,phpstan,types,lint,format,build,frontend,seed,target}-final.log` and `gate-results.json`. The first full backend gate ran 1,342 tests: 1,339 passes, 25,511 assertions, one failure and two existing skips (548.476 seconds). Its sole failure was the old W1b rejected `promotion` type, now expressly authorised. Only that rejection fixture changes to still-unauthorised `little_shield`; every other existing backend assertion is retained. The complete final gate above passed against the corrected candidate. Source hashes frozen before that final gate cover 1,431 files; final hash comparison shows zero source changes.

## Chrome evidence

Real Chrome, synthetic `.test` accounts and visibly synthetic GD illustrations only. Measured 1440 and 390 CSS px, light/dark, no horizontal overflow in any capture. Temporary viewport override reset. Images remain **local, private and ignored**, not committed. Manifest: `storage/app/private/w1d-evidence/chrome/manifest.json` (139 captures). Each filename below is relative to that directory and has `.jpg`.

| Evidence family | Local filenames (append `.jpg`) |
| --- | --- |
| activities-create-bm | `activities-create-bm-1440-dark`, `activities-create-bm-1440-light`, `activities-create-bm-390-dark`, `activities-create-bm-390-light` |
| activities-create-en | `activities-create-en-1440-dark`, `activities-create-en-1440-light`, `activities-create-en-390-dark`, `activities-create-en-390-light` |
| activities-edit-bm | `activities-edit-bm-1440-dark`, `activities-edit-bm-1440-light`, `activities-edit-bm-390-dark`, `activities-edit-bm-390-light` |
| activities-edit-en | `activities-edit-en-1440-dark`, `activities-edit-en-1440-light`, `activities-edit-en-390-dark`, `activities-edit-en-390-light` |
| activities-gallery-order | `activities-gallery-order-1440-dark`, `activities-gallery-order-1440-light`, `activities-gallery-order-390-dark`, `activities-gallery-order-390-light` |
| activities-history | `activities-history-1440-dark`, `activities-history-1440-light`, `activities-history-390-dark`, `activities-history-390-light` |
| activities-list | `activities-list-1440-dark`, `activities-list-1440-light`, `activities-list-390-dark`, `activities-list-390-light` |
| activities-preview-bm | `activities-preview-bm-1440-dark`, `activities-preview-bm-1440-light`, `activities-preview-bm-390-dark`, `activities-preview-bm-390-light` |
| activities-preview-en | `activities-preview-en-1440-dark`, `activities-preview-en-1440-light`, `activities-preview-en-390-dark`, `activities-preview-en-390-light` |
| ca-denied-activities | `ca-denied-activities-1440-dark`, `ca-denied-activities-1440-light`, `ca-denied-activities-390-dark`, `ca-denied-activities-390-light` |
| ca-denied-faqs | `ca-denied-faqs-1440-dark`, `ca-denied-faqs-1440-light`, `ca-denied-faqs-390-dark`, `ca-denied-faqs-390-light` |
| ca-denied-promotions | `ca-denied-promotions-1440-dark`, `ca-denied-promotions-1440-light`, `ca-denied-promotions-390-dark`, `ca-denied-promotions-390-light` |
| faqs-archived-history | `faqs-archived-history-1440-dark`, `faqs-archived-history-1440-light`, `faqs-archived-history-390-dark`, `faqs-archived-history-390-light` |
| faqs-browser-created | `faqs-browser-created-390-light` |
| faqs-create-bm | `faqs-create-bm-1440-dark`, `faqs-create-bm-1440-light`, `faqs-create-bm-390-dark`, `faqs-create-bm-390-light` |
| faqs-create-en | `faqs-create-en-1440-dark`, `faqs-create-en-1440-light`, `faqs-create-en-390-dark`, `faqs-create-en-390-light` |
| faqs-cross-language-refused | `faqs-cross-language-refused-1440-dark`, `faqs-cross-language-refused-1440-light`, `faqs-cross-language-refused-390-dark`, `faqs-cross-language-refused-390-light` |
| faqs-edit-bm | `faqs-edit-bm-1440-dark`, `faqs-edit-bm-1440-light`, `faqs-edit-bm-390-dark`, `faqs-edit-bm-390-light` |
| faqs-edit-en | `faqs-edit-en-1440-dark`, `faqs-edit-en-1440-light`, `faqs-edit-en-390-dark`, `faqs-edit-en-390-light` |
| faqs-history | `faqs-history-1440-light` |
| faqs-list | `faqs-list-1440-dark`, `faqs-list-1440-light`, `faqs-list-390-dark`, `faqs-list-390-light` |
| faqs-preview-bm | `faqs-preview-bm-1440-dark`, `faqs-preview-bm-1440-light`, `faqs-preview-bm-390-dark`, `faqs-preview-bm-390-light` |
| faqs-preview-en | `faqs-preview-en-1440-dark`, `faqs-preview-en-1440-light`, `faqs-preview-en-390-dark`, `faqs-preview-en-390-light` |
| hr-activities-readonly | `hr-activities-readonly-1440-dark`, `hr-activities-readonly-1440-light`, `hr-activities-readonly-390-dark`, `hr-activities-readonly-390-light` |
| hr-faqs-readonly | `hr-faqs-readonly-1440-dark`, `hr-faqs-readonly-1440-light`, `hr-faqs-readonly-390-dark`, `hr-faqs-readonly-390-light` |
| hr-promotions-readonly | `hr-promotions-readonly-1440-dark`, `hr-promotions-readonly-1440-light`, `hr-promotions-readonly-390-dark`, `hr-promotions-readonly-390-light` |
| hub | `hub-1440-dark`, `hub-1440-light`, `hub-390-dark`, `hub-390-light` |
| promotions-create-bm | `promotions-create-bm-1440-dark`, `promotions-create-bm-1440-light`, `promotions-create-bm-390-dark`, `promotions-create-bm-390-light` |
| promotions-create-en | `promotions-create-en-1440-dark`, `promotions-create-en-1440-light`, `promotions-create-en-390-dark`, `promotions-create-en-390-light` |
| promotions-edit-bm | `promotions-edit-bm-1440-dark`, `promotions-edit-bm-1440-light`, `promotions-edit-bm-390-dark`, `promotions-edit-bm-390-light` |
| promotions-edit-en | `promotions-edit-en-1440-dark`, `promotions-edit-en-1440-light`, `promotions-edit-en-390-dark`, `promotions-edit-en-390-light` |
| promotions-history | `promotions-history-1440-dark`, `promotions-history-1440-light`, `promotions-history-390-dark`, `promotions-history-390-light` |
| promotions-list | `promotions-list-1440-dark`, `promotions-list-1440-light`, `promotions-list-390-dark`, `promotions-list-390-light` |
| promotions-offer-dates | `promotions-offer-dates-1440-dark`, `promotions-offer-dates-1440-light`, `promotions-offer-dates-390-dark`, `promotions-offer-dates-390-light` |
| promotions-original-history | `promotions-original-history-390-light` |
| promotions-preview-bm | `promotions-preview-bm-1440-dark`, `promotions-preview-bm-1440-light`, `promotions-preview-bm-390-dark`, `promotions-preview-bm-390-light` |
| promotions-preview-en | `promotions-preview-en-1440-dark`, `promotions-preview-en-1440-light`, `promotions-preview-en-390-dark`, `promotions-preview-en-390-light` |

Browser actions actually performed: new FAQ saved with service link and complete status; Activity gallery reordered and saved as a new EN revision while BM retained its original order; original Promotion revision opened from history page two; mismatched BM FAQ service save refused in both themes; FAQ archived/unarchived with retained history. HR has no Save/Archive and disabled fields for each family; CA gets 403 on each family. Existing framework denial pages do not adopt application dark theme: dark filenames record the preference chosen beforehand, no content leakage. Screenshots use the existing 80% browser zoom with measured CSS viewport and crop adjusted for zoom/document scroll offset.

## Narrow choices and open questions

- **Strict item-wide consistency:** after both languages exist, references cannot be changed one language at a time. A future coordinated two-language operation needs owner approval; no hidden revision rewrite, override, language deletion or new workflow is added. This also applies when an associated branch is later deactivated, and to clearing a linked service after that service is archived: older history remains readable, but changing the link on a bilingual item needs that future operation (or a new manual draft). Existing W1c archive is not blocked. No issue blocks private draft review.
- References are stored as per-revision snapshots plus current branch associations, with a cross-language guard instead of a mutable canonical parent table. Future publishing must select the saved revision and require one consistent structural answer.
- Dates are optional independently and interpreted in the active branch's displayed timezone without conversion. If associated branches later use different timezones, publication wording needs an owner decision; no event scheduling is implied.
- Category/service/FAQ text, offer approval, rights and consent references are manual and not verified. Human content review and any future approval/publication policy remain owner decisions. Warnings do not automatically detect a private name or medical claim.
- Safe bounds: sort ±100000, branches ≤100, list 20/history 12. Cover may also appear once in an activity gallery. FAQs have no branch/media fields. A complete section has both heading and body; optional sections may be saved incomplete. Plain text excludes line breaks; textareas wrap visually.
- No automatic hiding/expiry, reminder, booking, payment, coupon or discount-code behaviour. Offer wording is not a structured price or a coupon.

## Deferred and absent

Little Shield, site settings, approval, publishing, releases, public route/read API/URL, Cloudflare or host calls, redirects/URL mapping, appointment intake, feedback, analytics/Search Console/tracking codes, discount codes, coupon/booking/payment behaviour, applicant records, imports/seeding, old-website code/runtime dependency and all operational/Pricing/staff/credential sourcing. No new permission, dependency, job or scheduler. No push, PR or merge.
