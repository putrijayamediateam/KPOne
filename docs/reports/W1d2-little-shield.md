# W1d2 — Little Shield private programme drafts

**Local candidate**, owner-authorised 2026-10-23. Branch `feature/w1d2-little-shield`, based on Job 1 commit **07c2384026d8e8f41e95efe71f84a7dd709d125e** (canonical base b49a96d). This is a stacked, separately reviewable change; Job 1 must land first. No push, PR or merge. Served tree remains clean on main, untouched.

## Scope

Exactly one manually entered Little Shield information item per organisation, created on first save, independent EN/BM text and saved-only private preview. Nothing is public. No old code, text, image or data import, no seed content, no operational/clinical catalogue lookup. The empty state is expected. The owner definition supplies the subject; the application stores only the owner's bounded editorial fields. It does not claim the described medical/consent process has been carried out.

No parent/child/school records, forms or sign-up, interest collection, clinical workflow, school list, impact counts, numeric fees, staff sourcing, messages, scheduling, publishing, approval, release, public URL/read API, outbound call or dependency. The staff-only edit form is not a public programme form. How-to-take-part is escaped plain text, never a link field or form action. No prices, sort order or archive feature. The inclusion flag is inert draft intent.

## Content and completeness

| Field | Exact server-side budget |
|---|---|
| Title / tagline / introduction | 120 / 200 / 600 characters |
| Overview paragraphs | Up to 4, 1200 each |
| Programme pillars | Up to 6; title 100, description 400 |
| Ordered programme flow steps | Up to 8; title 100, description 400 |
| Medical safeguards | Up to 10 bullets, 250 each |
| Partnership introduction | 600 |
| Partner types | Up to 6; title 100, description 300 |
| How to take part | 300, plain text only |
| Cover / ordered poster gallery | Optional cover, up to 8 distinct same-org sanitised assets |
| Coverage branches | Up to 100 distinct active same-org branch ids; may be empty on save |
| Include on website | Boolean intent only, false initially |

Unknown content fields are refused. Existing W1c plain-text validation rejects HTML/common Markdown, invalid UTF-8, controls, bidi and zero-width characters before trimming. Strings are never interpreted as markup. List shape/count and each nested field budget are validated server-side; stored ordering is exact. Uppercase UUID references are canonicalised; duplicate poster UUIDs are refused case-insensitively.

Saved-revision completeness requires title, introduction, at least one pillar with title/description, at least one flow step with title/description, at least one nonempty safeguard and at least one coverage branch. All other fields are optional. Incomplete private drafts always remain saveable within validation limits. Neither completeness nor inclusion implies approval, medical verification or publication.

Images follow existing W1a rules: active sanitised same-organisation assets, recorded rights, identifiable-person consent reference when flagged, existing media permission for selected images, no new upload endpoint. Cover may also appear in the distinct poster gallery. Retired images cannot be selected/retained in the current submitted revision; clear/remove them and save. Old history retains old assets. Job 1's narrowly recognised reference-only copy preserves an unchanged image in the other language even if retired, rather than altering that language's media; it does not authorise attaching new retired images. New Little Shield PostgreSQL media checks follow that rule. Child images without recorded consent are explicitly prohibited in the UI; a human-supplied reference is not automated proof of consent.

## Implementation, migration and permissions

New files:

- `WebsiteLittleShieldFields.php`, `WebsiteLittleShieldService.php` under `app/Domain/Organisation/Website/`.
- `app/Http/Controllers/WebsiteLittleShieldController.php`.
- `resources/js/pages/Website/LittleShield.vue`, `resources/js/components/website/LittleShieldDraftPreview.vue`, `resources/js/types/website-little-shield.ts`.
- `database/migrations/2026_10_25_000200_add_little_shield_draft.php`.
- `tests/Feature/Website/WebsiteLittleShieldTest.php`, `tests/Frontend/website-little-shield.test.mjs`.

Shared changes are one Clinic management Website destination, two authenticated sensitive/no-store routes (`GET` and `POST /website/little-shield`), exclusion from the existing global pending-intake lookup, preserving untrimmed content on that route for hidden-character refusal, and the three authorised assertion groups below. Every other route's behaviour is unchanged. No change to AGENTS, staff authority, permissions catalogue, original W1a–W1d services or Job 1 helper/migration/tests.

**No new table.** The additive migration expands `website_type_only` to allow `little_shield` with null branch_id and null archived_at; all existing type cases are retained. Partial unique index `website_little_shield_singleton` enforces one item per organisation. A type-specific insert guard checks media; existing append-only guards remain, and Job 1's deferred cross-language constraint applies automatically to the new type's branch references. Existing revisions/commands/item branches/media are reused. Item identity/slug stays immutable. `down()` retains evidence/protections. No data rewrite, content seed, permission grant or counter.

**No new permission:**

| Existing permission | Exact default holders | Use |
|---|---|---|
| website.content.view.organisation | Director, Marketing, HR Manager, Administrator | Private page, history and saved preview |
| website.content.manage.organisation | Director, Marketing, Administrator | First save and edits |
| website.assets.manage.organisation | Director, Marketing, HR Manager, Administrator | Existing library/selected media authority only |

HR Manager sees disabled fields and no Save action; holding media permission does not permit editing the page. All other catalogue roles gain nothing. Director-only provisioning of Marketing, HR Manager and Administrator, canAssignRoles/canManage, and all protected authority rules remain unchanged. There is no archive/unarchive route or control; the database also disallows archived_at for this type. This is the narrow singleton option authorised by the owner.

## Transactions, coordinated save and risk

Every save uses WebsiteAccess for fresh permission, organisation and effective-branch authority inside DB::transaction, organisation/item/reference/media row locks, independent item lock_version, UUID retry command with keyed payload-conflict refusal and one values-free audit. The organisation lock serialises first creates, backed by the partial unique index. A second create with a new key is refused; a matching retry returns its original result without duplicate rows. Foreign/stale ids or versions cannot select another organisation's item.

Calls the **unchanged reusable `WebsiteSharedReferences::append` from Job 1**. A branch change appends a revision for the submitted language and, if necessary, a reference-only revision for the other saved language, preserving its text/cover/poster order and all old rows. Item version advances once. Audit metadata is exactly item id, version, revision number, languages and field names; no content, branch-name values, consent text or image labels. Audit failure rolls back item/control/associations, both revisions, command and audit.

The deferred PostgreSQL constraint compares latest EN/BM references under an item lock at commit, with an explicit application check before returning. Temporary difference is allowed inside the atomic pair, never in a committed result. No writable bypass marker. A privileged/direct SQL client can submit a matching pair; the database is not an application-permission boundary, and an operator able to disable triggers remains trusted. No such bypass is exposed by the feature. No history is rewritten. No new framework or document/clinical evidence abstraction.

## UI and Chrome checks

One permission-filtered Little Shield hub entry opens the singleton directly. English staff UI; EN/BM tabs; saved completeness; ordered paragraphs, safeguards, pillars, flow, partner cards and posters with up/down/remove controls; a precise saved preview; 12-row read-only paginated history. Selecting history changes only the preview, never the editable current draft. No archive, list-of-programmes, public preview or publish control.

1440 CSS px uses two columns; 390 stacks them with wrapping controls and bounded images. On branch switch all loaded drafts, item id, branch/media selections and history are cleared. A visible branch-change message and read-only reload action are available while blocked; fresh loading uses backend authority. An unsaved sentinel title was discarded during an authorised Cheras/Puchong switch and only the saved title reappeared. An earlier switch with no synthetic secondary assignment was correctly refused; no access rule changed. Only the local fixture actor was given a non-primary assignment to exercise the valid switch.

Real Chrome evidence is private and ignored: `storage/app/private/little-evidence/manifest.json`, with **98 JPEGs**, no attachments committed. All captures measured exactly 1440 or 390 CSS px and document scrollWidth equal to innerWidth; no horizontal overflow. Both application themes explicitly selected. Temporary viewport overrides reset.

| Scenario family | Filename pattern, append .jpg |
|---|---|
| Permission-filtered hub | hub-{1440,390}-{light,dark} |
| Empty create/completeness | empty-create-{en,bm}-{1440,390}-{light,dark} |
| Saved bilingual edit/completeness | edit-{en,bm}-{1440,390}-{light,dark} |
| Pillars / steps / safeguards / partners | {pillars,steps,safeguards,partners}-{en,bm}-{1440,390}-{light,dark} |
| Exact poster order / coordinated coverage / saved preview | {posters,coverage,preview}-{en,bm}-{1440,390}-{light,dark} |
| History page 1 | history-{1440,390}-{light,dark} |
| History page 2 and retained first draft | {history-page-two,retained-first-preview}-{1440,390}-{light,dark} |
| HR read-only | hr-read-only-{1440,390}-{light,dark} |
| CA 403 | denied-ca-{1440,390}-{light,dark} |
| Unsaved branch-switch content discarded | branch-switch-cleared-{1440,390}-light |

The generic 403 template is theme-independent: its files were captured after each account theme selection, but its document does not use the application's dark class. The manifest records this honestly. No programme content appears there. Changing the shared error renderer is outside this slice. All other application-theme captures have the expected dark/light class. Fixtures contain only synthetic .test accounts and GD posters with explicit synthetic labels, no identifiable-person image or old-site content. Chrome first saved an incomplete EN draft, then saved actual EN ordering/coverage and a BM-only text edit. EN steps/poster order changed, BM wording/poster order stayed as entered, and Puchong reached both languages.

## Tests and every changed existing assertion

Ten new backend tests (424 focused assertions) cover every catalogue role's exact existing grants and view/write access, no new permission, empty GET/no seed, singleton/second-create, idempotency/conflict/stale, coordinated branches with unchanged text/history and one version/audit, independent EN/BM, all field/card/list budgets and hidden-character refusal, unknown personal/tracking/price-like fields, exact ordering, saved-only HTTP props and no-store headers, organisation/branch isolation, cached role/direct permission revocation, branch assignment revocation, PostgreSQL singleton/identity/update/delete/truncate/cross-language refusal, media order/consent/scope/retirement/removal, first/coordinated audit rollback, no HTTP/jobs/scheduler and full unrelated-row/query isolation. PostgreSQL JSON object key order is normalised only in a new test's expected object; list order remains exact. Seed fixture branches use forceCreate to set their guarded organisation id; application mutations are not bypassed.

`test_no_prohibited_queries_http_jobs_scheduler_and_all_unrelated_rows_unchanged_after_each_action` compares every unrelated table in ID order after create, read, BM save, coordinated save and HTTP read using populated clinical/billing fixture evidence. Only declared Little Shield records/commands and audit are excluded. It compares staff/user/assignment queries to the exact existing acting-user WebsiteAccess query set and rejects all patient/clinical/billing/dispensary/stock/catalogue/credential/schedule/appointment reads. No website content is derived from any such table.

Four new frontend tests compile the SFCs, pin private/no-collection/bounded UI, permission filtering, branch clearing/reload, ordering and responsive structure, existing permission routing/helper use, and actually SSR-render escaped malicious-looking strings with exact paragraph/step/poster order. No v-html, iframe, upload or outbound request.

Exactly **three existing assertion groups changed** relative to base 07c2384:

1. `tests/Feature/ClinicManagementTest.php`, `test_hub_returns_only_authorized_implemented_destinations_for_every_catalogue_role`: exact expected destination map gains `/website/little-shield` under `website.content.view.organisation`. All old destinations and role-by-role exact equality remain. Reason: the owner authorised this private destination.
2. `tests/Frontend/website-editorial.test.mjs`, renamed `All three destinations use existing view authority and forbidden families stay absent` to `All four ...`: positive navigation labels change from Promotions/Activities/FAQs to those three plus Little Shield, all under the same existing view permission. Every prohibited-family assertion remains. Reason: pin the newly authorised destination, without weakening existing boundaries.
3. `tests/Feature/Website/WebsitePeopleTest.php`, `test_postgresql_immutability_type_constraint_and_existing_branch_profile_preserved`: only the rejected-type literal changes `little_shield` → `site_setting`. Little Shield is now authorised; site settings remain unauthorised. The database rejection and every immutability/identity/branch-profile assertion stay intact.

No other existing test file changes. W1a–W1d and Job 1 tests pass with those explicit rule updates; no staff-authority or grant assertion changed, no test removed, weakened or newly skipped. The combined website/hub run before the final additional direct-grant test passed 73 tests / 5,355 assertions; final focused new suite passed 10 / 424.

## Validation

Task-owned PostgreSQL **18.6**, verified effective/actual target before requests: **127.0.0.1:58481**, user **little**, DB **kpone_little_testing**. Separate fresh/seed DB **kpone_little_seed_testing**. Isolated Chrome DB **kpone_little_preview_testing**, preview **127.0.0.1:54421**. No other database connection; no .env opened/created. Existing local dependencies copied, no install/new dependency. Only git fetch origin main used for external network.

| Gate | Final result |
|---|---|
| Full backend, memory override, PG18 | **1,357 passed; 26,209 assertions; two existing public-registration skips**, 1,359 total; 560.345 seconds; exit 0 |
| Full frontend | **478 passed**, zero failures/skips; exit 0 |
| Pint | Passed, exit 0 |
| PHPStan level 7 | Passed, zero errors, exit 0 |
| Types / lint / format | All passed, exit 0 |
| Production build | Passed, exit 0 |
| Separate migrate:fresh --seed | Passed, exit 0; no Little Shield seed |
| git diff --check | Passed, exit 0 |

Private logs: `/private/tmp/little-{backend,pint,phpstan,types,lint,format,build,frontend,seed,diff}.log`, gate-status log and focused logs. Development failures were confined to new fixture construction, JSON-object key ordering, the exact stronger no-store header and new-code formatting; fixed without editing old access tests. Browser validation prompted the frontend-only branch-change explanation/reload action while the full backend run was in progress. PHP/migration/backend-test source hashes remained unchanged throughout that full run; all frontend/static/build/seed checks were rerun after the frontend change and passed. The two changed frontend files and remaining source hashes are recorded locally. No additional backend logic changed after its full gate started.

## Decisions and open questions

No blocking product decision. Narrow choices: no archive feature; no sort field; cover may also occur in the distinct poster gallery, as in W1d; current submitted retired media must be removed while reference-only other-language copies preserve old media; history issue times follow existing website history's explicit UTC presentation. None grants new access or publishes content.

Clinic-approved EN/BM programme wording, actual medical/consent procedures, image rights and any later public legal notice still require owner/doctor review before a future publishing slice. Private saving/completeness does not certify those processes. Site settings/legal editors, public programme/interest forms, data collection, schools/participants, metrics, prices, publishing/releases, redirects, appointments, feedback, analytics, messaging, Cloudflare and all old-site use remain unauthorised and absent.
