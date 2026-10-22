# W1b — Doctor profiles and careers drafts (local candidate)

Owner-authorised on **2026-10-22**, branch `feature/w1b-website-people-content`, worktree `/Users/klinikputrijaya/Herd/KPOne-w1b`, based on fetched canonical main **18b5d60**. `git merge-base --is-ancestor 18b5d60 origin/main` passed. The served checkout and local main remain unchanged at `462aad1`. Local commit only: no push, PR or merge.

## Scope

Two concrete private content types: **manual doctor public profiles** and **careers postings**. Empty initial lists are intentional. No staff account, staff profile, credential, roster or schedule is used as a content source. Registration text and its display approval reference are optional manual strings; displaying registration text requires its approval reference. Profile completeness includes a separate profile-consent reference. No registration or consent claim is verified by KPOne.

EN and BM revisions are independent and append-only. Incomplete doctor profiles may be saved. Career saves require exactly one HTTPS application link or public HR email. Careers contain no salary, applicant, CV, attachment or application form. Intent flags do nothing outside the private draft.

Lists provide bounded search, organisation-branch filter, archived toggle and 20-row pagination. Editors provide language tabs, completeness, saved revision preview, paginated read-only history, versioned archive/unarchive and stable internal slugs. Saving appends a revision; archiving never deletes one. Historical revision content includes its selected branch ids, so earlier associations remain inspectable. Branch associations are item-wide current metadata; the last save updates them, while earlier language revisions retain their original association snapshot. The preview displays saved content, not unsaved form changes. Save or reload before switching language or archiving. Branch switching hides loaded content and clears form, portrait, branch selection and selected history.

Nothing is public. No publishing, approval, release, public route/read API, outbound website call, job, Cloudflare integration, old-data import or old website use. No new dependency. AGENTS.md is unchanged.

## Files and tables

- `WebsitePeopleFields`: named field/list budgets, strict plain-text validation, EN/BM content defaults, doctor consent completeness, registration-display dependency, career employment allowlist, date-only validation and exactly-one application destination.
- `WebsitePeopleService`: existing W1a authority checks, content/revision/media tables and transactional save pattern; scoped lists/history, save, archive/unarchive, optimistic versions, organisation-scoped retry records and values-free audits.
- `WebsitePeopleController`, private authenticated `/website/doctor-profiles` and `/website/careers` routes, `Website/People.vue`, `website-people.ts`.
- The shared Inertia header deliberately does not query the QR patient-intake counter on the new doctor/careers routes (including denied requests). It remains unchanged on W1a and all other routes. HTTP query-log tests cover every catalogue role.
- Existing permission catalogue consumes `WebsitePermissions::grants`; Clinic management Website group gains two view-filtered destinations. Existing W1a branch-profile/media controller, service, fields, image sanitiser and routes are untouched.
- `2026_10_24_000300_extend_website_people_drafts.php`: extends `website_content_items` type check to `branch_public_profile`, `doctor_profile`, `career_posting`; keeps a non-null branch and partial branch uniqueness for branch profiles, requires null branch_id for the two new types; adds `archived_at`. Adds `website_content_item_branches` with composite organisation foreign keys to items and branches. Stable item identity (including slug/type/org) cannot change; new-type updates must advance version. Item deletion/truncation is refused. Existing revision/command append-only triggers remain unchanged.
- `2026_10_24_000400_grant_website_people_permissions.php`: missing-only `firstOrCreate`/grant migration, cache refresh, no revocation in down(). No data import, new content seeds or destructive rollback.

## Permissions and authority

| Permission | Default holders |
|---|---|
| Existing `website.content.view.organisation` | Director, HR Manager, Marketing, Administrator |
| New `website.doctor_profiles.manage.organisation` | Director, HR Manager |
| New `website.careers.manage.organisation` | Director, HR Manager |

Marketing and Administrator have read-only pages and receive 403 on create/save/archive/unarchive endpoints. Every other catalogue role gets neither new permission nor new website view access. No publish/service permission is introduced. **Provisioning Marketing, HR Manager and Administrator remains Director-only.** `canAssignRoles`, `canManage`, provisioning services, policies and all staff-authority rules are untouched.

## Safety and audit

Every mutation validates fresh permission, organisation and effective branch through W1a `WebsiteAccess`, inside a transaction. Existing actor/organisation/branch locks serialize authority; item, association branches and portrait rows are locked before changes. Optimistic item version rejects stale writes. UUID retry commands carry a keyed payload fingerprint; same request replays without a second revision/audit, conflicting payload or actor refuses. A stale/changed branch refuses. Archive/unarchive advance the independent item version and retain all content.

Audits `website.people.save`, `.archive`, `.unarchive` contain **item id, version, revision number, language and field names only**. No content, names, registration or consent-reference values. Failure to record an audit rolls back the complete mutation. Permission-denial audits remain the existing middleware's values-free events.

Portraits reference only active sanitised W1a assets from the same organisation with identifiable-person flag and consent reference. At most one distinct portrait across the latest EN/BM revisions of a profile; a conflicting second portrait is refused (clear the other language portrait first to change it). Earlier history can retain older portraits. One portrait per individual revision; no new upload mechanism. Retired assets cannot be newly attached; historical revision references remain readable. HTML, common markup, control/bidi/zero-width characters and invalid UTF-8 refuse. No raw-HTML rendering.

Full ID-ordered comparisons cover every unrelated public-schema table before and after doctor and career save/view/archive/unarchive, including financial, patient/clinical, dispensary, stock, MC/referral/attendance and media rows. Query-log checks forbid operational/credential sources; identity reads must match the existing acting-user authority check. HTTP list/create/edit-route checks for every catalogue role also forbid operational sources, including the otherwise shared QR-intake counter. No outbound HTTP or queued job is allowed in the source-invariance test. The new route parameter is deliberately `websiteItem` to avoid the application's unrelated global dispensary `item` binding.

## Every changed existing assertion

1. **`StaffAuthorityBoundaryTest::test_director_provisions_all_three_website_roles_with_exact_approved_permissions`**: HR Manager's exact website set changes from `{website.assets.manage.organisation, website.content.view.organisation}` to exactly those two plus `{website.doctor_profiles.manage.organisation, website.careers.manage.organisation}` (four, sorted). Deliberate owner-authorised W1b permission change on 2026-10-22. The assertion remains an exact-set equality; no subset/contains replacement. Every other assertion in this file is untouched, including Director-only provisioning, denials, demotion, escalation and administration.
2. **`WebsiteDraftTest::test_additive_grants_replay_without_revoking_custom_grants_and_down_keeps_evidence`**: global exact website permission set changes from the three W1a permissions `{website.assets.manage.organisation, website.content.manage.organisation, website.content.view.organisation}` to exactly those three plus `{website.doctor_profiles.manage.organisation, website.careers.manage.organisation}` (five, sorted). Same deliberate owner-authorised change on 2026-10-22. Exact equality, replay/grant preservation and all other assertions remain untouched. Nothing was weakened.
3. **`ClinicManagementTest::test_hub_returns_only_authorized_implemented_destinations_for_every_catalogue_role`**: exact destination map gains `/website/doctor-profiles` and `/website/careers`, each requiring existing `website.content.view.organisation`. All old destinations remain; exact role-by-role equality still applies.
4. **Frontend `website-drafts.test.mjs`, Website hub navigation test**: removes the prohibition on the two now-authorised doctor/career destinations from its negative regex. Publishing/requests remain prohibited. New frontend assertions explicitly pin both destinations to the existing view permission and continue excluding unauthorised families.

**W1a branch-profile type allowlist:** the database check deliberately expands from the single branch-profile type to the three authorised types. A new PostgreSQL regression pins allowed new types, refusal of an unauthorised type, original branch-profile row preservation and immutable revisions/slug. There was no existing explicit type-only assertion in `WebsiteDraftTest` to edit; only its expressly authorised permission-set expectation changes. No other existing test assertion was changed. No test weakened, removed or newly skipped.

## New tests

`WebsitePeopleTest`: every catalogue role's exact new grants, permitted views and mutation denials; anonymous access; HR HTTP create/update, retries and payload conflicts; independent EN/BM revision history; stale versions; archive/unarchive retained revisions/version/slug; list filters; each text/list budget, hidden/control/markup refusal; completeness and manual registration dependency; employment types, application destination validation and calendar dates; fresh view/manage revocation; foreign org/branch isolation; portrait consent/active/retired checks; PostgreSQL immutable revision update/delete/truncate and slug/type guards; existing branch-profile preservation; full unrelated-row/query/HTTP/job isolation and audit rollback.

`website-people.test.mjs`: Vue compilation, escaped previews, no outbound actions, responsive wrapping, access-filtered controls, EN/BM, version/retry fields, branch-switch clearing and authorised navigation.

## Validation

Task-owned PostgreSQL **18.6**, host **127.0.0.1**, port **58441**, user `w1b`, UTC cluster. Backend database `kpone_w1b_testing`; Chrome database `kpone_w1b_seed_testing`; a third task-owned database `kpone_w1b_checks_testing` is used for final focused checks and separate final fresh-migration/seed verification. Effective configuration and actual server host/port/name/version verified before use; records in `storage/app/private/w1b-evidence/test-db-verified.log` , `seed-db-verified.log` and `checks-db-verified.log`. No `.env` opened or created. Existing dependencies cloned locally; none added or installed.

**Final full gate PASS: 1,305 backend tests, 1,303 passed, 22,929 assertions, two existing skips; 401 frontend tests passed.** The final focused W1b suite passes **10 tests / 1,381 assertions**, including the cross-language one-portrait rule, foreign portrait refusal and all-role HTTP source isolation. Preliminary new-code issues were corrected without editing existing assertions: replayable trigger-function creation during fresh migrations, distinct website route parameter, normal HTTP empty-string conversion in retry fixtures and exact existing no-store response header. Initial development failures are not release evidence.

## Evidence

Real Chrome, synthetic `.test` identities and manually written fictional content only. Portrait is a generated illustration, not a photograph of a real person. Exact DOM `innerWidth` and document scroll width are recorded per capture; 1440 and 390 CSS px, light/dark. Hub entries, lists, create/edit EN/BM, completeness, saved preview, history, archive/unarchive, consented portrait upload, Marketing read-only and denied-role views. The **93 retained screenshots** and exact-width/scroll-width records are indexed in [W1b evidence](W1b-evidence.md), with machine-readable manifest at `storage/app/private/w1b-evidence/chrome/manifest.json`. Screenshots remain local, outside public storage and outside Git. No real patient screenshot is retained.

## Narrow choices / open questions

- Safe internal bounds: sort order -100000 to 100000; application URL max 500 and public HR email max 254; at most 100 associated active organisation branches. Slug is type plus UUID, immutable, and explicitly not a public URL.
- At most one distinct current portrait across EN/BM: changing to a different asset requires clearing an existing different portrait in the other language first. This narrow rule avoids a hidden bulk rewrite of independent language revisions.
- Portrait optional for draft completeness; profile-consent reference and substantive profile fields/lists required for completeness. Public-registration approval reference required only when registration text is present. Publishing-time requirements remain undecided and out of scope.
- Plain text has no control characters, including line breaks; biographies/summaries wrap visually, and lists use separate bounded entries. No rich text or rendered Markdown.
- Associated branches must be active and from the organisation; acting user still needs existing effective-branch authority. W1a authority is not widened.
- All publication/approval decisions, content authenticity and consent verification remain for the owner in later slices. No decision blocks private drafts.

## Deferred scope

Services/taxonomy/prices, galleries, promotions, activities, Little Shield, FAQs, site settings, approval/publishing/releases, public routes/read APIs, deployment/Cloudflare/outbound integration, appointment requests, feedback, analytics/Search Console, applicant/hiring workflows, attachments, import/sync from old website or KPOne staff/credentials, and all other website programme features remain absent and unauthorised.


## Final full-gate results

| Check | Result |
|---|---|
| `php -d memory_limit=1G artisan test --compact -d memory_limit=1G` on task-owned PG18 | PASS — **1,305 tests; 1,303 passed; 22,929 assertions; 2 existing skips**, 516.361 seconds |
| Whole-repository Pint `--test` | PASS |
| PHPStan level 7 | PASS — 0 errors |
| `npm run types:check` | PASS |
| `npm run lint:check` | PASS |
| `npm run format:check` | PASS |
| `npm run build` | PASS |
| `node --test tests/Frontend/*.mjs` | PASS — **401 passed; 0 failed; 0 skipped** |
| Separate task-owned PG18 `migrate:fresh --seed` | PASS — `kpone_w1b_checks_testing`; only three existing branch-profile items, no seeded doctor/career content; exactly five website permissions |
| `git diff --check` and staged whitespace check | PASS |

The two existing skips are `Auth/RegistrationTest::test_registration_screen_can_be_rendered` and `test_new_users_can_register`: public Fortify registration remains disabled. No new or PostgreSQL test is skipped. The complete existing MC, referral, attendance, drug-label, billing and staff-authority suites ran unchanged except the expressly authorised exact permission expectations.

Final logs and zero exit statuses: `storage/app/private/w1b-evidence/{backend-full,pint,phpstan,types,lint,format,build,frontend,seed-final,diff-check}.{log,exit}`. Focused final W1b result: `people-final.log` (10 tests, 1,381 assertions). Database verification: `test-db-verified.log`, `seed-db-verified.log`, `checks-db-verified.log`. Exact final staged diff: `storage/app/private/w1b-evidence/W1b-final.patch`.

Chrome screenshots: **93 local synthetic captures**, indexed in [W1b evidence](W1b-evidence.md); 1440 and 390 CSS px, light/dark, no document-width overflow. Retained image widths also verified: 1440 or 390 pixels. Example paths:

- `storage/app/private/w1b-evidence/chrome/doctors-edit-en-390-dark.jpg`
- `storage/app/private/w1b-evidence/chrome/careers-preview-1440-light.jpg`
- `storage/app/private/w1b-evidence/chrome/marketing-doctors-readonly-390-light.jpg`
- `storage/app/private/w1b-evidence/chrome/portrait-upload-consent-390-dark.jpg`
- `storage/app/private/w1b-evidence/chrome/ca-denied-doctors-1440-light.jpg`

No blocking owner question remains for private drafts. The narrow choices above, including the one-current-portrait cross-language rule and future publishing-time completeness, remain explicit for review. Nothing was pushed, published or opened as a PR.
