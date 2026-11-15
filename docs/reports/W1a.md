# W1a — Website content foundation (local candidate)

Owner authorised the three legacy staff-authority fixture updates on **2026-10-22**. The first complete gate stop is resolved by test changes only; no access rule, authority service or extra role grant is changed. Final rerun results are recorded below.

Owner-authorised draft-only branch public profiles and private website media, based on canonical main `5bf8612`, branch `feature/w1a-website-content-foundation`, isolated worktree `/Users/klinikputrijaya/Herd/KPOne.worktrees/w1a-website-content-foundation`. The served tree stays unchanged on main. Local commit only; no push, PR or merge. No old website code, import, dependency or repository modification.

## Scope and files

Organisation-owned content identities have exactly one permitted concrete type: `branch_public_profile`. Every save appends an immutable EN or BM revision and advances the item's independent version. Latest revision is the current private draft. The editor has separate language tabs, saved-revision completeness, read-only paginated history and escaped saved-draft preview. Incomplete drafts may be saved. Nothing can publish. Unsaved changes must be saved or discarded by reloading before switching language; the saved preview never claims to show unsaved edits. Branch switching hides loaded content and clears profile fields, hours, contacts, hero selection and upload drafts.

New domain files under `app/Domain/Organisation/Website/`: `WebsitePermissions`, `WebsiteAccess`, `WebsiteProfileFields`, `WebsiteDraftSeeds`, `WebsiteImage`, `WebsiteDraftService`. New `WebsiteDraftController`; authenticated routes under `/website`; `Website/Profiles.vue`, `Website/DraftPreview.vue`, `Website/Media.vue` and website types. Existing catalogue, reference seeder, private disk configuration and Clinic management navigation/icon map gain only this foundation. AGENTS receives exactly one authorised line clarification; BASELINE records this local candidate and the remaining authorisation boundary.

## Tables and migrations

- `2026_10_24_000100_create_website_drafts.php`: `website_content_items` (organisation/branch identity, fixed type, stable slug, optimistic version); `website_content_revisions` (item/language/revision, bounded JSON content, optional hero, seed flag, numeric creator id, UTC time); `website_media_assets` (private sanitised path/hash/MIME/size/dimensions, EN/BM alt, rights/consent references, identifiable-person flag, numeric uploader id, retirement/version); `website_draft_commands` (organisation-scoped UUID retry key, actor id, operation, keyed payload fingerprint and result id). Deliberate composite foreign keys prevent cross-organisation hero/item links. PostgreSQL rejects revision and retry-record UPDATE, DELETE and TRUNCATE. Asset metadata is immutable; only one version-checked retirement is allowed. Asset DELETE/TRUNCATE are refused, including referenced assets. `down()` retains evidence.
- `2026_10_24_000200_grant_website_draft_permissions.php`: additive `firstOrCreate` permissions, missing grants only, replay safe, no revocation in `down()`. Reference catalogue matches.

The controlled migration/reference seed creates one unreviewed EN and BM revision per existing branch, copying **only** existing public address and map URL. It never reads letterhead, legal registration, footer, patient or clinical sources. Re-seeding never overwrites a revision.

## Permissions and access

| Permission | Default holders |
|---|---|
| `website.content.view.organisation` | Director, Marketing, HR Manager, Administrator |
| `website.content.manage.organisation` | Director, Marketing, Administrator |
| `website.assets.manage.organisation` | Director, Marketing, HR Manager, Administrator |

All other catalogue roles receive none. HR views profile/history without editing but can manage media. No publishing, requests, doctor profiles, careers or other permission. Every read and mutation checks fresh backend authority, active organisation, effective active-branch assignment and organisation ownership. Expected branch id prevents a request drafted under an earlier branch context from saving after a switch. No staff names, emails, MMC numbers or credential content are returned by the website domain (only numeric creator/uploader ids are retained internally).

## Content and media safety

All profile text is bounded; controls, bidi and zero-width formatting characters, invalid UTF-8 and HTML tags are refused. No HTML/markdown rendering, arbitrary embeds or expressions. Map URL is HTTPS only, bounded, without embedded credentials; displayed as text, never fetched. WhatsApp accepts strict Malaysian local mobiles or explicit foreign international numbers, stores digits only, and performs no click-to-chat/network operation here. An international stored number is shown with + in the editor for safe input round-trips. Seven weekday rows use same-day HH:MM or closed; incomplete blank times are permitted in drafts. At most three bounded plain contact blocks and one sanitised hero.

Website media uses an isolated private disk, with no public symlink/serve route. Authenticated, fresh-permission/organisation-checked previews are private/no-store, nosniff and sandboxed. Only JPG, still PNG and still WebP are accepted after extension/declared MIME/actual MIME agreement, strict container checks, real decode, byte/dimension/pixel budgets and re-encoding. Limits: 5 MB, 4096 px per side, 12 million pixels. Only the re-encoded file is kept, with metadata removed and SHA-256 of retained bytes. Synthetic EXIF/GPS is verified present before and absent after re-encoding. Originals, remote URL fetching, SVG/HTML/PDF/GIF, mislabeled/trailing-content polyglots and animations are refused. Alt text in both languages and rights reference are mandatory; an identifiable person needs a separate consent reference. Retirement prevents new attachments; retained revisions still preview their prior hero. No hard delete action.

## Mutation and audit safety

Every save/upload/retirement uses a transaction, organisation/actor/grant/assignment/branch row locks, item or asset lock_version (upload requires initial version 0), UUID retry key and conflicting-payload refusal. Replays require fresh authority and add no revision, file or audit row. Upload failures/audit failures roll back rows and delete any staged sanitised file. An absent/failed audit blocks the action. Audit metadata contains item/asset ids, versions/revision number, language and controlled field names only, never content, image/rights/consent values or staff names. Timestamps are stored with explicit UTC offsets and returned as canonical UTC instants, independent of PostgreSQL session display timezone.

Website code does not read or write patient, visit, queue, clinical, billing, dispensary or stock tables. Query-log assertions cover the website service operations; full ID-ordered snapshots compare **all unrelated public tables** before/after each save, upload, profile/media/preview read and retirement with populated invoice and clinical sources. No operational mutation, financial calculation, background job or outbound network integration.

## Every changed existing assertion

1. `ClinicManagementTest::test_hub_returns_only_authorized_implemented_destinations_for_every_catalogue_role`: extend its exact destination-to-permission map with `/website/branch-profiles` guarded by `website.content.view.organisation` and `/website/media` guarded by `website.assets.manage.organisation`. This recognises exactly the two authorised new destinations for each role; the exact allowlist and all existing privacy assertions remain intact.

2. `StaffAuthorityBoundaryTest::test_technical_admin_can_administer_every_role_without_administrative_authority`: replace the successful Marketing role-sync destination and final `hasRole('marketing') === true` expectation with an explicit `AuthorizationException` refusal, unchanged original role and unchanged audit count. Retain the existing full profile-edit, branch-assignment, role-sync and deactivate coverage by syncing to `business_development`, whose permissions Technical Admin already covers; the existing canonical role-list/final role expectations now use that target. All other helper assertions and role coverage remain unchanged.
3. `StaffAuthorityBoundaryTest::test_technical_admin_can_demote_but_never_promote_and_the_demotion_is_audited_with_the_actor`: Marketing assignment now explicitly refuses, keeping the CA Supervisor role and audit count unchanged. The successful demotion target becomes `business_development`. Exactly three existing Marketing expectations change: post-demotion `hasRole`, attached audit role name and retained role after failed promotion now expect `business_development`. The CA Supervisor removal, actor attribution, detached role, promotion refusal/no-audit, `canAssignRoles` refusal and Director restoration assertions are unchanged.
4. `StaffAuthorityBoundaryTest::test_technical_admin_can_demote_a_peer_technical_admin`: Marketing assignment now explicitly refuses with the peer's original role and audit count unchanged. Successful demotion uses `business_development`, and the post-demotion `hasRole('marketing')` expectation becomes `hasRole('business_development')`. Existing manageRoles authority, removal of Technical Admin and audit actor assertions remain unchanged.

The reason for all three fixture revisions is the deliberate W1a permission consequence: Marketing is no longer an assignable low-permission target for Technical Admin. No existing account-management, demotion, audit or anti-escalation coverage is removed or weakened. The shared `administerAllFour` helper is unchanged.

**No other existing assertion changes.** Existing frontend navigation assertions remain unchanged; new frontend tests pin the Website icon, destinations, permission filtering, escaped saved preview, EN/BM, completeness/history, field budgets, branch-switch clearing and responsive structure. No test weakened, removed or skipped.

## Validation

Task-owned PostgreSQL 18.6: **127.0.0.1:58439**, user `w1a`, database `kpone_w1a_testing`; separate migration/seed/browser database `kpone_w1a_seed_testing`. Both effective and actual host/port/name/version verified before requests. Cluster timezone explicitly UTC. Datadir and tooling are task-owned under this worktree's `storage/app/private/`; no `.env` opened or created. No dependencies installed or added. No connection to any unowned database.

Final full-gate results and Chrome evidence are recorded below. The earlier handoff stopped on the three legacy Marketing assignment expectations; the owner authorised precisely those fixture updates on 2026-10-22. The resumed focused authority/website/hub run passed 39 tests / 2,170 assertions before the additional explicit Website-role restoration test was added. The final full gate includes that test. No pre-fix or stopped run is counted as release evidence.

## Deferred scope and open questions

- No approval state or publishing capability. “Eligible hero” means active sanitised asset with required rights/consent metadata; there is no hidden approval workflow. Formal approvals/publishing belong to later owner authorisation.
- Incomplete drafts are intentional. Completeness currently requires public name, address line 1, postcode, city, state, public phone and all seven weekdays (times or closed); email/map/WhatsApp/notes/hero are optional. Owner may refine publishing-time requirements later; no public release is possible here.
- Same-day opening hours only; overnight/split shifts are not represented in this slice. A bounded hours note can describe draft exceptions, pending a later structured-hours decision.
- Retired assets and their bytes are retained for revision history. No purge, hard delete or original archive.
- Old website, other content types, content import, translation workflow beyond independent EN/BM drafts, snapshots/releases, public routes/APIs, appointment intake, feedback, analytics/GSC, deployment, CDN/Cloudflare calls, jobs and runtime integrations remain unauthorised and absent.


## Staff-authority regression coverage (owner-authorised follow-up)

Four new tests explicitly prove:

- Technical Admin and HR Manager cannot assign **or provision** Marketing, HR Manager or Administrator. Refusals leave role lists, user count and audit count unchanged.
- Director can provision all three, with exactly the approved Website permission subsets and one primary branch assignment.
- Technical Admin and HR Manager retain profile editing and deactivation of those accounts. Technical Admin retains branch assignment; HR's existing refusal of branch management remains pinned.
- Technical Admin can demote each of the three Website-role accounts to Business Development, but cannot restore their prior role; failed restoration writes no audit or role change. Director can restore each original role.

`StaffAuthorityService::canAssignRoles`, `StaffRoleService`, `StaffProvisioningService`, policies and all role grants are **unchanged** by this follow-up. Exact existing behaviour matters: HR Manager covers its own role's permission set, so the coverage-only `canAssignRoles` helper may return true for HR-to-HR. HR still cannot assign or provision that role because both mutation entry points require `access.manage.organisation`, which HR does not hold. Tests assert the real service boundary rather than changing the helper to fit an inaccurate shortcut. Technical Admin lacks all three roles' Website permissions. Among current catalogue roles, provisioning these three accounts is therefore Director-only.

## Foundation full-gate rerun (`081222c`)

**PASS — local candidate only; no push, PR or merge.** Owner authorised the three legacy fixture revisions, preserving all access rules, and the complete gate was rerun on the verified task-owned PostgreSQL 18.6 databases.

| Check | Final result |
|---|---|
| `php -d memory_limit=1G artisan test --compact -d memory_limit=1G` | PASS — **1,256 tests, 1,254 passed, 20,815 assertions, 2 existing skips**, 495.206 seconds |
| Whole-repository Pint | PASS |
| PHPStan level 7 | PASS — 0 errors |
| `npm run types:check` | PASS |
| `npm run lint:check` | PASS |
| `npm run format:check` | PASS |
| `npm run build` | PASS |
| `node --test tests/Frontend/*.mjs` | PASS — **398 passed, 0 failed, 0 skipped** |
| Separate disposable PG18 `migrate:fresh --seed` | PASS |
| `git diff --check` and staged whitespace check | PASS |

The only backend skips are the two pre-existing public-registration screen/signup tests gated by disabled Fortify registration. No PostgreSQL regression or new test is skipped. The final backend run includes all four new staff-authority tests, the three updated legacy fixtures, the unchanged remaining staff-authority assertions, all website safety tests and the rest of the complete suite.

Final logs and captured exit statuses: `storage/app/private/w1a-evidence/resume-backend-full.log`, `resume-pint.log`, `resume-phpstan.log`, `resume-types.log`, `resume-lint.log`, `resume-format.log`, `resume-build.log`, `resume-frontend.log`, `resume-seed.log`, `resume-diff-check.log`; verification records `test-db-verified-resume.log`, `seed-db-verified-resume.log`. Corresponding `.exit` files are zero for every executable gate step. Whitespace checks produce no output. Complete final diff: `storage/app/private/w1a-evidence/W1a-final.patch`.

The original stopped handoff's 1,247 passes / three legacy fixture errors / two existing skips is not release evidence. No pre-fix or stopped run is counted as final evidence. Existing Chrome evidence remains valid: this authorised follow-up changes tests and documentation only, with no screen, access-rule or Website grant change.

## Real Chrome evidence

47 viewport captures, all synthetic `.test` accounts and fixtures, at measured **1440 or exactly 390 CSS px**, light and dark; every recorded `document.documentElement.scrollWidth <= innerWidth`. Native Chrome remained at its existing 80% zoom. Viewport overrides were adjusted to achieve the exact CSS widths; screenshot clipping removes Chrome's zoom-padding area without altering page content. Overrides were reset after validation. The upload helper initially lacked file URL access; the owner enabled it, then actual browser PNG acceptance and SVG refusal were completed successfully.

Observed: Website hub present for Marketing and absent for CA; EN/BM editors, complete and incomplete drafts, saved preview, read-only seed/history; private media acceptance/refusal, retained sanitised bytes and soft retirement; HR fields disabled with no save button; CA profile/media HTTP 403; successful assigned-branch switch discards an unsaved sentinel and shows that branch's own incomplete seed. An unassigned-branch attempt clears loaded content then returns 403, rather than exposing the prior draft.

Safest narrow branch choice: existing transactional authority pattern requires an effective assignment to the selected branch, even for these organisation-scoped content permissions. A role's organisation-wide branch selector may list an unassigned branch; website reads/writes still refuse it. No assignment, role or permission is expanded automatically. Owner may decide later whether Website-only organisation authority should permit every branch; that has **not** been added.

All screenshots and gate logs are local ignored evidence, not application media or repository commits. Artifact directory: `storage/app/private/w1a-evidence/`; Chrome manifest: `storage/app/private/w1a-evidence/chrome/viewport-measurements.json`. No screenshot of another app or real patient data is retained or forwarded.

| Screenshot | CSS width | Theme |
|---|---:|---|
| [ media-390-light ](../../storage/app/private/w1a-evidence/chrome/media-390-light.jpg) | 390 | Light |
| [ hub-390-light ](../../storage/app/private/w1a-evidence/chrome/hub-390-light.jpg) | 390 | Light |
| [ editor-en-390-light ](../../storage/app/private/w1a-evidence/chrome/editor-en-390-light.jpg) | 390 | Light |
| [ editor-bm-390-light ](../../storage/app/private/w1a-evidence/chrome/editor-bm-390-light.jpg) | 390 | Light |
| [ preview-bm-390-light ](../../storage/app/private/w1a-evidence/chrome/preview-bm-390-light.jpg) | 390 | Light |
| [ history-390-light ](../../storage/app/private/w1a-evidence/chrome/history-390-light.jpg) | 390 | Light |
| [ hub-1440-light ](../../storage/app/private/w1a-evidence/chrome/hub-1440-light.jpg) | 1440 | Light |
| [ editor-en-1440-light ](../../storage/app/private/w1a-evidence/chrome/editor-en-1440-light.jpg) | 1440 | Light |
| [ editor-bm-1440-light ](../../storage/app/private/w1a-evidence/chrome/editor-bm-1440-light.jpg) | 1440 | Light |
| [ history-1440-light ](../../storage/app/private/w1a-evidence/chrome/history-1440-light.jpg) | 1440 | Light |
| [ media-1440-light ](../../storage/app/private/w1a-evidence/chrome/media-1440-light.jpg) | 1440 | Light |
| [ hub-1440-dark ](../../storage/app/private/w1a-evidence/chrome/hub-1440-dark.jpg) | 1440 | Dark |
| [ editor-en-1440-dark ](../../storage/app/private/w1a-evidence/chrome/editor-en-1440-dark.jpg) | 1440 | Dark |
| [ editor-bm-1440-dark ](../../storage/app/private/w1a-evidence/chrome/editor-bm-1440-dark.jpg) | 1440 | Dark |
| [ history-1440-dark ](../../storage/app/private/w1a-evidence/chrome/history-1440-dark.jpg) | 1440 | Dark |
| [ media-1440-dark ](../../storage/app/private/w1a-evidence/chrome/media-1440-dark.jpg) | 1440 | Dark |
| [ media-390-dark ](../../storage/app/private/w1a-evidence/chrome/media-390-dark.jpg) | 390 | Dark |
| [ media-retired-390-dark ](../../storage/app/private/w1a-evidence/chrome/media-retired-390-dark.jpg) | 390 | Dark |
| [ hub-390-dark ](../../storage/app/private/w1a-evidence/chrome/hub-390-dark.jpg) | 390 | Dark |
| [ editor-en-390-dark ](../../storage/app/private/w1a-evidence/chrome/editor-en-390-dark.jpg) | 390 | Dark |
| [ editor-bm-390-dark ](../../storage/app/private/w1a-evidence/chrome/editor-bm-390-dark.jpg) | 390 | Dark |
| [ preview-bm-390-dark ](../../storage/app/private/w1a-evidence/chrome/preview-bm-390-dark.jpg) | 390 | Dark |
| [ history-390-dark ](../../storage/app/private/w1a-evidence/chrome/history-390-dark.jpg) | 390 | Dark |
| [ unreviewed-seed-390-dark ](../../storage/app/private/w1a-evidence/chrome/unreviewed-seed-390-dark.jpg) | 390 | Dark |
| [ unassigned-branch-denial-390-dark ](../../storage/app/private/w1a-evidence/chrome/unassigned-branch-denial-390-dark.jpg) | 390 | Dark |
| [ media-upload-ready-390-dark ](../../storage/app/private/w1a-evidence/chrome/media-upload-ready-390-dark.jpg) | 390 | Dark |
| [ media-upload-accepted-390-dark ](../../storage/app/private/w1a-evidence/chrome/media-upload-accepted-390-dark.jpg) | 390 | Dark |
| [ media-upload-refused-390-dark ](../../storage/app/private/w1a-evidence/chrome/media-upload-refused-390-dark.jpg) | 390 | Dark |
| [ media-upload-refused-1440-dark ](../../storage/app/private/w1a-evidence/chrome/media-upload-refused-1440-dark.jpg) | 1440 | Dark |
| [ media-upload-accepted-1440-light ](../../storage/app/private/w1a-evidence/chrome/media-upload-accepted-1440-light.jpg) | 1440 | Light |
| [ media-retired-1440-light ](../../storage/app/private/w1a-evidence/chrome/media-retired-1440-light.jpg) | 1440 | Light |
| [ media-retired-390-light ](../../storage/app/private/w1a-evidence/chrome/media-retired-390-light.jpg) | 390 | Light |
| [ branch-switch-incomplete-390-light ](../../storage/app/private/w1a-evidence/chrome/branch-switch-incomplete-390-light.jpg) | 390 | Light |
| [ media-upload-accepted-390-light ](../../storage/app/private/w1a-evidence/chrome/media-upload-accepted-390-light.jpg) | 390 | Light |
| [ media-upload-refused-390-light ](../../storage/app/private/w1a-evidence/chrome/media-upload-refused-390-light.jpg) | 390 | Light |
| [ media-upload-refused-1440-light ](../../storage/app/private/w1a-evidence/chrome/media-upload-refused-1440-light.jpg) | 1440 | Light |
| [ media-upload-accepted-1440-dark ](../../storage/app/private/w1a-evidence/chrome/media-upload-accepted-1440-dark.jpg) | 1440 | Dark |
| [ media-retired-1440-dark ](../../storage/app/private/w1a-evidence/chrome/media-retired-1440-dark.jpg) | 1440 | Dark |
| [ hr-readonly-1440-dark ](../../storage/app/private/w1a-evidence/chrome/hr-readonly-1440-dark.jpg) | 1440 | Dark |
| [ hr-readonly-390-dark ](../../storage/app/private/w1a-evidence/chrome/hr-readonly-390-dark.jpg) | 390 | Dark |
| [ hr-readonly-390-light ](../../storage/app/private/w1a-evidence/chrome/hr-readonly-390-light.jpg) | 390 | Light |
| [ hr-readonly-1440-light ](../../storage/app/private/w1a-evidence/chrome/hr-readonly-1440-light.jpg) | 1440 | Light |
| [ ca-no-website-hub-1440-light ](../../storage/app/private/w1a-evidence/chrome/ca-no-website-hub-1440-light.jpg) | 1440 | Light |
| [ ca-denied-1440-light ](../../storage/app/private/w1a-evidence/chrome/ca-denied-1440-light.jpg) | 1440 | Light |
| [ ca-denied-390-light ](../../storage/app/private/w1a-evidence/chrome/ca-denied-390-light.jpg) | 390 | Light |
| [ ca-denied-390-dark ](../../storage/app/private/w1a-evidence/chrome/ca-denied-390-dark.jpg) | 390 | Light |
| [ ca-denied-1440-dark ](../../storage/app/private/w1a-evidence/chrome/ca-denied-1440-dark.jpg) | 1440 | Light |

Original stopped handoff log: `storage/app/private/w1a-evidence/backend-full-final.log`. Final follow-up gate logs use the `resume-` prefix; verified endpoints use `test-db-verified-resume.log` and `seed-db-verified-resume.log`.

## PR #154 follow-up — EXIF orientation (2026-10-22)

Owner-authorised image-orientation correction only. `WebsiteImage::sanitise` now captures primary-image EXIF before metadata is discarded, validates TIFF byte order/magic/IFD entry bounds and the single SHORT/count-1 Orientation tag, then applies the eight EXIF pixel transforms (mirrors included). JPEG uses PHP's EXIF extension when available, checked against the bounded parser; without it the same bounded reader supplies the orientation. Both little- and big-endian TIFF are supported. Malformed, duplicate, conflicting or out-of-range tags are refused through ordinary image validation, not crashes. No new dependency.

PNG **can** carry EXIF in `eXIf`, and WebP **can** carry it in an `EXIF` RIFF chunk; these were accepted still-image types already. Their EXIF now receives the same validation and upright-pixel handling. PNG CRC/container checks and WebP chunk lengths/padding are checked before extraction. References: [W3C PNG eXIf specification](https://www.w3.org/TR/png-3/#eXIf), [Google WebP container specification](https://developers.google.com/speed/webp/docs/riff_container). There is no network request from the application.

After transforming pixels, dimensions are measured using `imagesx`/`imagesy`; the dimension and pixel budgets are checked again on that final image. Only then does GD encode retained bytes. Uploaded EXIF, Orientation and GPS metadata are absent from the retained output. Width/height, byte size and SHA-256 describe the retained upright file. No permission, migration, route, financial/source record or immutable existing asset is changed. Previously sanitised assets cannot recover an orientation tag already stripped; a rotated old asset needs the original re-uploaded, not a metadata rewrite.

New `WebsiteImageOrientationTest` has 39 cases: 24 end-to-end upload cases (all eight orientations × JPEG/PNG/WebP) check independent asymmetric quadrant pixel expectations, final and stored dimensions (swapped for 5–8), metadata removal, retained byte size/hash and one-file retention; one test exercises both TIFF byte orders and the direct fallback reader; 14 malformed-tag cases run against all three containers, covering truncation, byte order/magic, IFD offsets/counts, tag type/count, 0/9/65535 and duplicate tags. Fixtures are synthetic non-square colour patterns with synthetic GPS references, never real photographs or personal data.

**Changed existing assertions in this follow-up: none.** All prior Website, staff-authority, hub and other test assertions remain untouched. The four pre-existing-test changes made for the initial foundation remain enumerated above; none is changed again. Focused new-plus-existing Website suite: **55 passed / 1,329 assertions**.

A separate PHP process with `exif_read_data` disabled passed the fallback regression: **1 test / 49 assertions**, covering both TIFF byte orders and all eight JPEG orientations without the native function. This probe is separate from (not added to) the full-suite totals.

**Full follow-up gate PASS:** 1,295 backend cases — **1,293 passed, 21,530 assertions**, two unchanged disabled-public-registration skips; 506.193 seconds. **398 frontend tests passed**, zero failures/skips. Whole-repository Pint, PHPStan level 7 (zero errors), frontend types/lint/format, production build, separate PostgreSQL 18 migrate:fresh/seed, and working/staged diff checks all pass. No new or PostgreSQL regression test is skipped. All existing test assertions remain unchanged by this fix; only new orientation tests/fixtures are added. Separate fallback probe: 1 passed / 49 assertions with the native EXIF function disabled. No permission, migration, route or access-rule change. This is a fix on PR #154, not a new slice; do not merge without owner instruction. Logs use `storage/app/private/w1a-evidence/orientation-*.log` and corresponding exit-status files, with verified owned PostgreSQL 18.6 databases at `127.0.0.1:58439`: `kpone_w1a_testing` and separate `kpone_w1a_seed_testing`. The existing page-layout screenshots remain applicable; no UI code changes in this follow-up.
