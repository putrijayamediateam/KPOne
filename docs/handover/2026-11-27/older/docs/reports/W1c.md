# W1c — Website service categories and public-service drafts

Status: **local candidate**, owner-authorised 2026-10-22. Branch `feature/w1c-website-services`, based on fetched canonical `origin/main` **1b9c4f6**. The served checkout remains untouched on local main. No push, PR or merge. W0 was never committed; the owner explicitly waived finding it and directed this slice to use the W1c prompt, merged W1a/W1b code, W1b report and BASELINE section 6 item 13 instead.

## Scope

**DRAFT-ONLY. Nothing is public.** Manual organisation-wide categories with one optional subcategory level, and public-service content linked to one category/subcategory. No old website code, import, clinical service/medicine catalogue or Pricing linkage; no financial calculation. No public path field. Stable type-plus-UUID slugs are internal identifiers, generated once and never changed.

Category language revisions hold name (100), short description (300), optional parent and cover, integer sort order and an include-on-website intent flag. Service revisions hold name (120), short summary (300), up to eight description sections (heading 100, body 1500), two lists of up to eight bullets (200 each), SEO title (70), SEO description (160), optional manual price text (80) and its display-approval reference (200), parent, cover, an ordered gallery of up to twelve distinct media references, sort order and intent. Flags do nothing outside private drafts. All text is bounded, escaped, plain text; HTML/common Markdown, control, bidi, zero-width and invalid UTF-8 input refuse. Plain-text bodies wrap visually; line breaks are control characters and refuse, matching W1b's text rule.

Price text is a human-entered string. If present, a nonempty approval-reference string is required. It is never parsed, calculated, verified, filled or compared against Pricing. No salary, testimonials, booking/contact fields, FAQ or other content family is built or stubbed.

## Files and tables

- New `WebsiteServiceFields`: explicit allowlisted fields, budgets, structure and saved-revision completeness.
- New `WebsiteServicesService`: category/service lists, filters, language revisions, read-only history, create/save/archive/unarchive and media selection.
- New `WebsiteServicesController`: private Inertia page and **explicit route redirects** for save and archive/unarchive; no bare `back()`.
- New `Website/Services.vue`, `website-services.ts` and `website/ServiceDraftPreview.vue`: EN/BM editors, disabled read-only fields, saved-only escaped previews, parent/branch selection, ordered galleries, completeness, retained archive controls and paginated history.
- Existing Clinic management navigation and authenticated routes gain only Service categories and Services. All routes require existing permissions and sensitive no-store middleware. No new public route or API.
- Shared Inertia QR pending-intake count excludes only these two additional website route families, matching W1b isolation. New W1c request paths bypass generic string trimming so their domain validator sees and refuses original leading/trailing control/hidden characters; every other route's trimming is unchanged. CSRF, authentication, sessions and permissions are unchanged.
- New migration **`2026_10_24_000500_extend_website_service_drafts.php`**. **No new tables.** Existing `website_content_items`, `website_content_revisions`, `website_content_item_branches` and `website_draft_commands` are reused.

The migration expands only the item type check from branch/doctor/career to those same three plus `service_category` and `public_service`, both with null `branch_id`. The branch-profile partial unique index remains unchanged. The existing item identity/version/delete/truncate guards and immutable revision/command protections apply unchanged. It adds an expression index for parent references and service-specific PostgreSQL functions/triggers for hierarchy, archival, valid media and category branch-association refusal. `down()` intentionally retains all evidence and guards. There is no permission migration, seed/import or dependency change.

## Hierarchy schema choice

**Parent references are in each immutable language revision's `content.parent_public_id`**, not in a second mutable parent table. Cover and ordered gallery public UUIDs and `_branch_ids` are also saved in that revision. `hero_asset_id` retains the existing same-organisation FK for the cover. Active service availability also uses the existing child association table, as W1b does; historical preview/completeness reads the revision's branch snapshot, not current associations.

- Parent must be an active `service_category` in the same organisation; a public service cannot be a parent. Self-parenting and cycles refuse. A subcategory's parent must be a root across both current language revisions. A category with active subcategories cannot itself become a subcategory.
- The current hierarchy is the union of the **latest EN and latest BM revision** for each item. An earlier superseded link does not block archive. A current link in either language does block archive of that active parent. This prevents a language change from hiding a dependency.
- Archiving a category with any active current category/service children refuses with a clear validation message. Unarchiving a child requires every saved current-language parent to be active and the depth rule to remain valid. Archive never deletes revisions or associations.
- `WebsiteAccess` already locks the organisation/actor/effective branch. All W1c writes use that serialised pipeline, then item/parent/branch/media locks, eliminating competing reparent/archive races within the organisation. PostgreSQL independently checks new revision references, active children on archive, and parents on unarchive; direct invalid SQL is refused too.
- UUID references are canonicalised in the application and compared case-insensitively by PostgreSQL guards. An uppercase JSON UUID cannot evade active-child protection. A dedicated regression tests this with the uppercase-only remaining parent reference.
- Categories are organisation-wide: branch associations are refused in the service and by a new PostgreSQL association trigger. Service associations must be active branches of the same organisation.

No materialised hierarchy table or document/publishing framework is added. Parent/branch/media history is immutable evidence of draft choices, not a published website release.

## Permissions and safety

**No new permissions or grants.**

| Existing permission | Exact default holders | W1c use |
| --- | --- | --- |
| `website.content.view.organisation` | Director, Marketing, HR Manager, Administrator | Lists, saved revisions/history and private previews |
| `website.content.manage.organisation` | Director, Marketing, Administrator | Create, save, archive, unarchive both new types |
| `website.assets.manage.organisation` | Director, Marketing, HR Manager, Administrator | Existing media selection/library authority; uploading remains only in the media library |

HR Manager is **view-only** for these two content families. CA, CA Supervisor, Resident Doctor, Technical Admin, Finance Officer, Panel Officer, Business Development and TV receive nothing. Provisioning Marketing, HR Manager and Administrator remains **Director-only**; `canAssignRoles`, `canManage`, all staff-authority rules, W1a/W1b grants and AGENTS.md are untouched.

Every mutation rechecks fresh permission, organisation and effective branch through existing `WebsiteAccess`, inside a transaction. Row locks, independent item `lock_version`, UUID retry keys with keyed payload-conflict refusal, monotonic language revision numbers and append-only retry records are retained. A retry returns the original result without another write/audit; a new attachment command still refuses a now-retired asset. Stale/archived edits refuse.

Cover/gallery selections use only active sanitised W1a assets from the same organisation. Identifiable-person assets need their existing nonempty consent reference. Selection checks fresh media authority. Retired assets cannot be attached by a new command; earlier revisions preserve their original references/order and remain readable. Cover may also be one of the gallery's distinct entries; gallery order is explicit and never sorted. No bytes are copied into content revisions and no new upload path exists.

Audit events `website.services.save`, `.archive`, `.unarchive` carry **item id, version, revision number, language and field names only**. No content, price, approval reference, asset label, patient/staff name or recipient value. Audit failure rolls back every action. Existing permission-denial audits remain unchanged.

Query-log proof forbids patient/intake, Visit, Queue, clinical/credential, medicine/clinical-service catalogue, billing, Pricing, dispensary and inventory/stock sources. Identity/staff queries must exactly match the existing authority check for the acting user. HTTP view checks also cover shared middleware, not only direct service calls. No outbound HTTP or queued job is permitted.

ID-ordered full-row comparisons cover **all unrelated public-schema tables** before and after create/update/view/archive/unarchive. Within shared website tables, only W1c type rows, their revisions/associations and `services_*` commands are excluded; populated doctor/career drafts and existing branch profiles remain in the comparison. Audit is the only other allowed difference. Operational financial/clinical evidence is populated with existing synthetic fixtures before the proof. No staff/credential, patient, financial, clinical, stock or W1a/W1b record is changed by this feature.

## UI and evidence

Private Website destinations follow KPOne/W1b components and English UI. Lists have search, category filter, archived toggle and service branch filter; twenty items per page. The editor has independent EN/BM tabs, immutable internal slug, saved-revision completeness, optional price/reference, cover/gallery controls and a saved-only preview. History is read-only, twelve revisions per page; opening history changes the preview, not the draft. Read-only actors have disabled fields and no save/archive controls. Unsaved edits must be saved/reloaded before changing language.

Branch switching immediately blocks the old page and clears form content, parent, branches, gallery candidate, loaded items/assets/categories, current item, selected revision, history and loaded language drafts. Fresh props are adopted only after the authorised response. Mobile stacks the editor and preview; desktop uses two columns. No new dependency.

Real Chrome uses only task-owned synthetic `.test` identities and manual fictional content. Three cover/gallery images are task-owned GD illustrations, visibly marked synthetic, sanitised through existing W1a upload. A subcategory was actually created through the UI, gallery ordering saved to a new EN revision, original revision opened from history page two, BM retained independently, and parent archive refusal exercised. HR's disabled fields/no Save or Archive controls and CA's two-family 403 were verified.

Evidence files remain local/private/ignored, never public or committed. The screenshot index below records exact 1440/390 CSS px, theme, URL family and paths. The existing framework error page has no application dark class; captures named dark mean the preference was chosen before opening 403. No website content appears in those responses. Temporary viewport override is reset after evidence.

## Tests and every changed existing assertion

New `tests/Feature/Website/WebsiteServicesTest.php`: **12 W1c regressions** covering exact catalogue role grants/views/mutation denials; Director/Marketing/Administrator writes and HR denials; fresh cached-grant revocation; organisation/branch isolation; stale versions, identical retries and conflicting payloads; independent EN/BM and saved branch snapshots; completeness; every field/list budget, markup/control/invisible rejection and HTTP preservation; hierarchy/active-parent/depth/archive/unarchive rules and direct PostgreSQL protections; media order, twelve-item budget, duplicate/foreign/missing-consent/retired media and fresh media permission; stable slug, append-only evidence and branch-profile preservation; all-source query and row invariance including retained foundation drafts; audit rollback and values-free metadata; uppercase UUID guard consistency.

New `tests/Frontend/website-services.test.mjs`: **4 tests** for Vue compilation, actual SSR escaping/section and gallery order, bounded private controls, permission-filtered writes, branch-switch clearing, responsive structure, explicit redirects and authorised navigation.

Exactly **two existing assertion groups changed**, deliberately authorised by W1c:

1. **`tests/Feature/ClinicManagementTest.php` — `test_hub_returns_only_authorized_implemented_destinations_for_every_catalogue_role`**: the exact destination map gains `/website/service-categories` and `/website/services`, both requiring existing `website.content.view.organisation`. Every old destination and role-by-role exact-set equality remains. Reason: the owner authorised these two private destinations.
2. **`tests/Frontend/website-people.test.mjs` — `New hub entries use existing view permission, with no publishing or additional family`**: the negative regex changes from `website.publish|website.services|website.requests` to `website.publish|website.requests`. Reason: Services is now expressly authorised; publishing and requests remain forbidden. New W1c navigation tests positively pin **both** new destinations to existing view authority. All other assertions in that file are untouched.

**No other existing assertion changes.** There was no exact-three-type assertion needing an edit in W1b's backend suite: its unauthorised-type guard remains, and its full test file runs untouched. W1a, staff-authority and permission-list tests, People service/page behaviour, and all unrelated feature assertions remain unchanged. No test is weakened, removed or newly skipped.

## Validation

Task-owned **PostgreSQL 18.6**, `127.0.0.1:58451`, user `w1c`, UTC cluster. Backend DB `kpone_w1c_testing`; separate fresh-seed DB `kpone_w1c_gate_seed_testing`; Chrome DB `kpone_w1c_seed_testing`, private preview port **54391**. Effective host/port/name and actual server tuple were verified before first requests. Explicit environment wrapper; no .env read or created, no production or non-owned DB. Installed dependencies copied locally from the existing worktree; none added or installed from the network.

Final source fingerprint: `storage/app/private/w1c-evidence/final-source-sha256.json` (1,397 files, zero changes after validation). Documentation-only completion follows the frozen source run.

| Check | Final result |
| --- | --- |
| Full backend, PHP memory override, PG18 | **1,330 passed, 24,420 assertions, 2 existing public-registration skips** (1,332 total), 533.427 seconds; exit 0 |
| New W1c backend suite | **12 passed, 561 assertions**; exit 0 |
| Frontend `node --test tests/Frontend/*.mjs` | **467 passed, 0 failed, 0 skipped**; exit 0 |
| Pint | Passed; exit 0 |
| PHPStan level 7 | Passed, no errors; exit 0 |
| `npm run types:check` | Passed; exit 0 |
| `npm run lint:check` | Passed; exit 0 |
| `npm run format:check` | Passed; exit 0 |
| `npm run build` | Passed; exit 0 |
| `migrate:fresh --seed`, separate `kpone_w1c_gate_seed_testing` | Passed; exit 0 |
| `git diff --check` | Passed; exit 0 |

Final logs and command exit codes: `storage/app/private/w1c-evidence/{backend,pint,phpstan,types,lint,format,build,frontend,seed,target}-final.log` and `gate-results.json`. No test was weakened, removed or newly skipped.

## Local screenshot index

**89 JPEGs**, ignored private evidence under `storage/app/private/w1c-evidence/chrome/`; detailed dimensions/theme/route/scroll metadata in `chrome/manifest.json`. Each filename below has `.jpg`. A temporary Chrome viewport plus its existing 80% zoom gave measured `window.innerWidth` **1440 or 390 CSS px**, height 900; each capture checked `scrollWidth <= innerWidth`. Screenshot clips account for the browser zoom and document scroll offset. The viewport override was reset. No screenshots are committed.

| Evidence family | Local filenames (append `.jpg`) |
| --- | --- |
| branch-switch-cleared | `branch-switch-cleared-1440-light` |
| ca-denied-categories | `ca-denied-categories-1440-dark`, `ca-denied-categories-1440-light`, `ca-denied-categories-390-dark`, `ca-denied-categories-390-light` |
| ca-denied-services | `ca-denied-services-1440-dark`, `ca-denied-services-1440-light`, `ca-denied-services-390-dark`, `ca-denied-services-390-light` |
| categories-archive-refused | `categories-archive-refused-1440-dark`, `categories-archive-refused-1440-light`, `categories-archive-refused-390-dark`, `categories-archive-refused-390-light` |
| categories-create-bm | `categories-create-bm-1440-dark`, `categories-create-bm-1440-light`, `categories-create-bm-390-dark`, `categories-create-bm-390-light` |
| categories-create-en | `categories-create-en-1440-dark`, `categories-create-en-1440-light`, `categories-create-en-390-dark`, `categories-create-en-390-light` |
| categories-edit-bm | `categories-edit-bm-1440-dark`, `categories-edit-bm-1440-light`, `categories-edit-bm-390-dark`, `categories-edit-bm-390-light` |
| categories-edit-en | `categories-edit-en-1440-dark`, `categories-edit-en-1440-light`, `categories-edit-en-390-light` |
| categories-hierarchy-cover | `categories-hierarchy-cover-390-dark`, `categories-hierarchy-cover-390-light` |
| categories-list | `categories-list-1440-dark`, `categories-list-1440-light`, `categories-list-390-dark`, `categories-list-390-light` |
| hr-categories-readonly | `hr-categories-readonly-1440-dark`, `hr-categories-readonly-1440-light`, `hr-categories-readonly-390-dark`, `hr-categories-readonly-390-light` |
| hr-services-readonly | `hr-services-readonly-1440-dark`, `hr-services-readonly-1440-light`, `hr-services-readonly-390-dark`, `hr-services-readonly-390-light` |
| hub | `hub-1440-dark`, `hub-1440-light`, `hub-390-dark`, `hub-390-light` |
| services-cover-gallery | `services-cover-gallery-1440-dark`, `services-cover-gallery-1440-light`, `services-cover-gallery-390-dark`, `services-cover-gallery-390-light` |
| services-create-bm | `services-create-bm-1440-dark`, `services-create-bm-1440-light`, `services-create-bm-390-dark`, `services-create-bm-390-light` |
| services-create-en | `services-create-en-1440-dark`, `services-create-en-1440-light`, `services-create-en-390-dark`, `services-create-en-390-light` |
| services-edit-bm | `services-edit-bm-1440-dark`, `services-edit-bm-1440-light`, `services-edit-bm-390-dark`, `services-edit-bm-390-light` |
| services-edit-en | `services-edit-en-1440-dark`, `services-edit-en-1440-light`, `services-edit-en-390-dark`, `services-edit-en-390-light` |
| services-gallery-order | `services-gallery-order-390-dark`, `services-gallery-order-390-light` |
| services-history | `services-history-1440-dark`, `services-history-1440-light`, `services-history-390-dark`, `services-history-390-light` |
| services-list | `services-list-1440-dark`, `services-list-1440-light`, `services-list-390-dark`, `services-list-390-light` |
| services-original-history-preview | `services-original-history-preview-1440-light` |
| services-preview-bm | `services-preview-bm-1440-dark`, `services-preview-bm-1440-light`, `services-preview-bm-390-dark`, `services-preview-bm-390-light` |
| services-preview-en | `services-preview-en-1440-dark`, `services-preview-en-1440-light`, `services-preview-en-390-dark`, `services-preview-en-390-light` |
| services-price-hierarchy | `services-price-hierarchy-1440-dark`, `services-price-hierarchy-1440-light`, `services-price-hierarchy-390-dark`, `services-price-hierarchy-390-light` |

The additional branch-switch check entered an unsaved synthetic name, then selected Puchong. The old draft/history/selections cleared immediately. The existing website effective-branch authority refused that destination with 403; no unsaved content was retained or saved. The actor returned to Cheras without changing authority. `branch-switch-cleared-1440-light.jpg` records the cleared page behind the refusal dialog; it is not a claim that the destination branch was authorised.


The earlier pre-strengthening full run passed **1,331 total / 1,329 passes / 24,416 assertions / two existing skips**, 534.608 seconds. A subsequent development rerun was stopped after the UUID edge correction changed its candidate; it is not release evidence. The final frozen source hashes and actual command exit codes are retained with the final logs. Initial new-code issues were corrected without changing old tests: PDO JSON-operator placeholder syntax, locked-row collection checking, raw text preservation, new fixture branch/media inputs and distinguishing an original idempotent replay from a genuinely new retired-asset attachment.

## Narrow choices and open questions

- **Incomplete draft versus structural requirements:** the prompt allows incomplete private saves. A service may save without a parent or branches; both are explicitly missing for completeness. Once supplied, there is exactly one active valid parent and only active organisation branches. No public release exists to bypass a publishing gate. Category completeness requires name and short description; root categories need no parent.
- Parent choices and branch snapshots are independent per language, matching W1b's independent revisions. Current hierarchy safety considers both languages. Availability filters use current child-table associations, while history/preview uses the saved language snapshot. A future publisher must resolve the saved revision, not silently read live associations.
- Safe internal bounds follow W1b: sort order -100000 to 100000, at most 100 active branch selections, 20 list rows and 12 history rows per page. Gallery max twelve distinct ordered assets, optional cover may also appear in that gallery.
- Parent filter is an **exact category/subcategory selection**, not an implicit recursive descendant expansion. This avoids silently broadening a filter; services can be associated directly with a root or its one child level.
- Restoring an archived child requires restoring its parent first. No hidden reparenting of archived or other-language revisions occurs.
- Manual approval references, medical claims, content accuracy and consent documents are **not verified** by KPOne. Staff warnings remain; any actual publication/approval rules require later owner authorisation. No decision blocks private drafts. Owner may review the incomplete/locale/filter choices above before a publishing slice.

## Deferred and absent

Promotions, activities, Little Shield, FAQs, site settings, approval, publishing, releases/snapshots, public routes/read APIs, Cloudflare/host calls, appointments, feedback, analytics/Search Console, redirects/URL mapping, applicant/hiring records, imports, old website code/runtime dependencies, and clinical/medicine/Pricing/billing/inventory/staff sourcing. No new permission or staff-authority change. No automatic data transfer or seeding. No push, PR or merge.
