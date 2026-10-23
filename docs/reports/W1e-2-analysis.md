# W1e-2 analysis — private static website artifacts

**Analysis only, 2026-10-23.** Base `origin/main` **32f7679**, containing #164 (`21bb2ec`), #165 (`ef79ea6`) and #166 (including `821398f`). Branch `docs/w1e2-output-analysis`. This is the only file changed. No code, template, renderer, test, migration, permission, dependency, route or application configuration is written. No database, server, old-site script, provider API or production site was accessed. Only `git fetch origin main` used external network. Nothing below authorises implementation, public disclosure or production use.

## 1. One-page recommendation

Build a small deterministic **Blade/PHP static renderer inside KPOne**, with ordinary local CSS and the minimum plain JavaScript required for legacy query-string details. It should accept verified frozen release content, produce an immutable file manifest and retain a private artifact. It must never call the existing draft capture service, reuse an Inertia page as public HTML, look up staff names or fetch a remote asset. Reuse prepare authority for building/private inspection and Director approval authority for a separate exact-artifact review. Release approval currently binds content, not a renderer or a URL map.

**The complete public-site build has an input gap.** Current schema-1 releases contain no public slug/URL map, public origin, global brand/logo selection, home/about prose or external legal destinations. They cannot reproduce the existing service URLs or unseen About content from their frozen fields alone. Do not silently resolve these from live drafts, internal slugs, staff, business settings or the old website. Before implementing a complete artifact, the owner must approve a frozen output-contract mechanism or authorise a new freeze schema. Existing approved evidence remains untouched.

Recommended contract: a separately immutable, Director-reviewed **output plan**, bound to the release hash, holding only reviewed route aliases, language/origin policy and a versioned system design profile. It is non-content build metadata, not a settings editor. **This is an additional proposed input, not permitted by the current strict release-only rule without explicit owner approval.** If the owner keeps that rule literally, public routes must instead be included in a separately authorised future freeze schema; existing schema-1 releases are insufficient for compatibility builds. A pure renderer core may be developed and tested with synthetic fixtures first, but must not label an incomplete real artifact a complete website.

| Question | Recommended default |
|---|---|
| Scope | Private generation, verification and retention only; no deployment or public access |
| Renderer | Blade/PHP, fresh isolated view data, local plain CSS; no public framework or per-build npm operation |
| Inputs | Verified release items/media/manifest only for content; separately approve the frozen output-plan exception or block complete builds |
| URLs/languages | Preserve existing English paths and `?slug=` detail behaviour; propose `/bm/` equivalents with HTML `lang="ms"`; confirm inventory first |
| Images | Copy exact retained sanitised bytes; no derivatives in the first renderer |
| Look | Rebuild the familiar information layout in a simple owner-approved clinic palette; no copying old CSS/assets or invented brand |
| Security | Escaped allowlisted fields, no forms/cookies/tracking/inline scripts; private noindex preview and restrictive CSP |
| Integrity | Pin release hash, renderer/profile digests and output plan; hash every file and the canonical file manifest; build twice |
| Storage | Encrypted private generated files; immutable references to retained release media instead of another stored image copy |
| Preview/ZIP | Authenticated top-level private artifact view; relative URLs; defer ZIP until an actual handoff needs it |
| Approval/access | Prepare holders build/view; Director separately approves exact artifact with existing approve permission |
| Execution | Bounded synchronous request; render outside locks, fresh approval/version check before atomic completion; no queue |
| Tests | Determinism, privacy sentinels, source-query isolation, artifact link/SEO/security checks and real Chrome responsive/a11y review |
| Slices | Resolve contract, then 2a renderer/storage, 2b private preview/artifact review, 2c URL/SEO evidence; each separately authorised |
| Biggest blockers | Missing frozen routes/brand inputs, visual choice, query-URL SEO, approval meaning and storage/limits |
| Authority | This document changes no authorisation boundary; no build until an explicit slice instruction |

## 2. Verified evidence and differences from earlier proposals

References below are to this analysis worktree unless marked OLD. Unqualified WebsiteRelease*.php filenames are under `app/Domain/Organisation/Website/`; release migrations are under `database/migrations/`. Reports describe their historical candidate state; current merged code wins where they differ. In particular, W1e-1b supersedes W1e-1's Staff #id and historical-approval comparison limits. BASELINE section 6 item 13 still groups approval with future publishing; its W1e-1 delivery row and merged code establish that private release approval exists. This analysis does not edit that wording.

| Verified fact | Evidence |
|---|---|
| Public generation, hosting and import still need separate authorisation | `AGENTS.md:11`; `docs/BASELINE.md:264`–`265`; `docs/reports/W1e-analysis.md:137`–`170` |
| Explicit nine-type public projection; no URL slug or renderer version | `app/Domain/Organisation/Website/WebsiteReleaseProjection.php:7`–`35` |
| Capture reads live drafts and branches, so it is unsuitable as the renderer's reader | `WebsiteReleaseSelection.php:14`–`39` |
| Frozen keys are opaque; branch/parent/service/media references are resolved during capture | `WebsiteReleaseSelection.php:22`–`25`, `107`–`154` |
| Private manifest includes source revision ids/hashes as well as public projections | `WebsiteReleaseSelection.php:159`–`170`; never copy that whole manifest to public output |
| Evidence stores exact release item projections and encrypted base64 media | `WebsiteReleaseService.php:75`–`79`; release migration `2026_10_26_000100_create_website_release_evidence.php:37`–`63` |
| Approval uses exact release hash/version and typed number, not an output hash | `WebsiteReleaseService.php:83`–`108` |
| Current comparison is currently-approved lower-id release; staff names are a page-only lookup | `WebsiteReleaseService.php:151`, `157`–`175`; `docs/reports/W1e-1b.md:7`–`10` |
| Canonical JSON sorts object keys and preserves array order | `WebsiteReleaseJson.php:7`–`22` |
| Current media reader decrypts and checks SHA/length | `WebsiteReleaseService.php:219`–`231` |
| Seven release tables and immutability/state guards already exist | `2026_10_26_000100_create_website_release_evidence.php:11`–`106`; do not add build states to these guards |
| Private release access/default holders | `2026_10_26_000200_grant_website_release_permissions.php:14`–`25`; `routes/web.php:153`–`158` |
| Current preview is structured data, not a website renderer | `resources/js/pages/Website/Releases.vue:218`–`305` |
| Existing private headers | `PrivateWebsiteRelease.php:11`–`16`; `PreventSensitiveResponseCaching.php:17`–`24` |
| Image source limits are 5 MiB, 4096 px per dimension, 12 million pixels | `WebsiteImage.php:10`–`14`; these are not an aggregate artifact-size budget |

OLD is the available read-only clone `/Users/klinikputrijaya/MiniWeb-readonly`, clean tip **0fe19da**, checked without modifying it. Source observations below are **not verification of today's deployed website, URLs, account configuration or indexed pages**. Those need owner confirmation and later authorised crawl/provider checks. No old code, assets, prose, contacts or staff records are imported or reproduced.

- OLD `frontend/js/service-detail.js:37`–`63` reads `slug` and calls the old catalogue API; `81`–`119` sets a query-string canonical; `148`–`163` changes title/description in the browser. The static shell starts with a generic head and loading content (`frontend/service-detail.html:11`–`27`, `68`–`79`).
- OLD `frontend/js/services.js:249`–`271` sets category query filters; `644`–`646` links to `service-detail.html?slug=...`. The canonical and service sitemap instead use extensionless `/service-detail?slug=...`.
- OLD `backend/routes/serviceSitemap.js:8`–`39`, `43`–`85` builds the service sitemap from nonempty distinct slugs, requiring active service, subcategory and category. OLD `frontend/sitemap.xml:6`–`86` lists the principal public pages; `robots.txt` points to the static main sitemap and old backend service sitemap.
- OLD `README.md:35` and `docs/ARCHITECTURE.md:170`–`183` document Pages but note leftover Firebase configuration. Host rewrites/status behaviour are therefore **needs owner confirmation**; no deployed response behaviour is assumed here.

## 3. Scope and dependencies

Recommended future W1e-2 result: one complete **private, non-deployable review artifact** assembled from one currently approved release and its explicitly authorised immutable build contract. It includes local pages/assets, a compatibility data file if needed, sitemap/robots/header candidates, a deterministic manifest and verification results. No files go to a public disk or host. The Director's content approval does not automatically approve a new design.

W1e-3's separately authorised connector depends on exact artifact hashes, extraction/path rules, final artifact approval and a production output profile; its provider token, upload/activation, rollback and reconciliation remain out. W1e-4 cutover/retirement depends on the final URL inventory, rendered SEO evidence, legal/operator decisions and a tested deployment rollback. W2 appointments and W3 analytics are independent later authorisations: this artifact may not contain placeholder forms or trackers for them. A later settings slice is needed for richer home/about/site-wide content unless the owner explicitly accepts a limited fixed system layout using only existing frozen content. KP Content OS remains on its separate host, linked only after reviewed destinations are frozen.

## 4. Renderer options

| Option | Recommendation and alternative risk |
|---|---|
| Blade/PHP in the existing domain | **Choose.** Laravel is already present. Domain reader verifies evidence, a pure rendering layer gets a frozen DTO, and views receive only explicit fields. No Eloquent/global view data, app layout, CSRF/session injection, Vite development tags, current time or live config reads in public templates. More care is needed to keep normal Laravel conveniences out of static output. |
| Separate Node static-site builder | Defer. Adds runtime/toolchain, package/build plugin supply chain, process boundaries and reproducibility work. Existing Node for KPOne does not justify installing a public framework. Revisit only for a demonstrated requirement. |
| Hand-concatenated HTML strings | Reject as the primary approach: context escaping, translated layouts and valid markup become difficult to review. Small standard serializers for XML/JSON/manifests are reasonable; do not write a generic templating language. |

No public front-end compile step is needed initially: version-controlled CSS and a small fixed external JS file are copied byte-for-byte and hashed. KPOne's normal frontend build remains its own gate, not an artifact input. If a later design needs a CSS compiler/fonts, produce and review pinned assets at developer build time, record tool versions/source digests, and never run package resolution/downloads during a staff build. No old-site CSS, JS, templates or assets are reused.

## 5. Frozen inputs and public allowlist

Separate the **authority adapter** from the renderer. The adapter may use existing acting-user/organisation/effective-branch authority reads and release controls/actions to prove current approval; those records are never passed to a public view. The content reader may query only `website_releases`, `website_release_items`, `website_release_media` for the selected scoped release. The pure renderer performs **zero DB queries**. It must not call `WebsiteReleaseService::page` (which now reads staff names), `capture`, draft services, live branch data or the original media disk.

Recompute the canonical release hash, compare manifest entries with stored projection/content hashes, reject missing/extra rows or media/hash/size/MIME mismatches, then construct a second explicit render DTO. Never export private `sources`, omissions, actions, creator/approver labels or raw DB rows. Query-log tests distinguish the adapter's exact authority reads, the release-only reader and the zero-query renderer; full-table comparisons prove no source row changes. A changed draft, staff name or asset retirement must not change historical output bytes. A withdrawn **release** blocks further builds/serving. Live-asset consent withdrawal requires the owner to withdraw the release; the renderer cannot both avoid live reads and automatically detect a later media retirement.

The current projection vocabulary, repeated explicitly for renderer review:

| Type | Allowed content fields |
|---|---|
| Branch profile | display_name, address_line_1/2, postcode, city, state, public_phone, whatsapp_number, public_email, map_url, hours_note, parking_notes, accessibility_notes; opening_hours for the fixed weekdays with closed/open/close only; contact_blocks with label/text only |
| Doctor | display_name, professional_title, biography, public_registration_text, qualifications, areas_of_practice, languages_spoken, sort_order |
| Career | title, summary, location_note, apply_url, apply_email, responsibilities, requirements, employment_type, closing_date, sort_order |
| Service category | name, short_description, sort_order |
| Public service | name, short_summary, seo_title, seo_description, price_text, what_to_expect, suitable_for, sort_order; description_sections with heading/body |
| Promotion | title, short_summary, offer_text, valid_from, valid_until, terms, sort_order; description_sections with heading/body |
| Activity | title, short_summary, location_note, starts_on, ends_on, sort_order; description_sections with heading/body |
| FAQ | question, answer, sort_order |
| Little Shield | title, tagline, introduction, overview_paragraphs, medical_safeguards, partnership_introduction, how_to_take_part; programme_pillars, programme_flow_steps, partner_types with title/description |

Only frozen opaque item/branch keys are used internally to resolve relationships. Each projection also allows branches, parent/linked_service where applicable, and media entries with position, hash filename and translated alt. Keys need not appear in page text or a downloadable private manifest. A minimal public catalogue may use an opaque key to link its own records; no numeric/source identifier is substituted for it.

**Forbidden everywhere in HTML/CSS/JS/public JSON/XML, metadata and filenames:** consent/rights/display-approval references; internal numeric ids, source revision ids/hashes, source UUIDs/internal slugs, private paths, staff names/accounts, audit/decision reasons, clinical/operational records, credentials or tokens. Public slugs may appear only if separately frozen and approved; never derive legacy aliases from live internal slugs or names. The doctor display name is explicitly approved public content; a release approver's staff name is not. Registration text may render only from that frozen public field: freeze already requires its approval reference (`WebsiteReleaseSelection.php:73`–`75`); renderer neither publishes nor refetches the private reference.

## 6. Page inventory, languages and the URL gap

The following is a **proposed output map**, not a promise that the frozen inputs already supply every page. Preserve existing English canonical paths after a reviewed inventory; actual `.html`, slash and case aliases need later host validation. Initial BM proposal mirrors each path under `/bm/`, with a visible EN/BM switch and explicit `hreflang="en"`/`"ms"`; internal storage language `bm` is not an HTML language code. English root remains the compatibility entry, with no cookie/automatic locale redirect. Confirm x-default strategy before cutover.

| Page / nine-type source | Proposed first output | Input or compatibility limit |
|---|---|---|
| Home | `/`, plus `/bm/` | Fixed navigation and selected frozen service/branch/programme summaries only; no invented hero claims or imported home copy |
| About | `/about`, BM equivalent | No About field exists. Do not invent a clinic history. Complete About prose needs separately frozen authorised content; a minimal branch-information page is an owner decision, not SEO equivalence |
| Branch list and three branch detail pages | `/branches`, `/cheras`, `/sungai-besi`, `/puchong` | Branch profiles plus frozen doctor/service branch associations; mapping opaque branch keys to legacy paths is missing and must be approved |
| Doctor list / profiles | `/doctors`; initial cards/anchors on list | No known old individual doctor URL is asserted. Per-doctor pages are optional later, not required for parity without evidence |
| Categories/subcategories and services list | `/services?category=<reviewed-alias>` | Category tree from frozen parent keys; shared static list/filter data; legacy category aliases are missing |
| Service detail | `/service-detail?slug=<reviewed-alias>` | Shared HTML shell and minimal frozen JSON; aliases must be newly frozen, not guessed |
| Promotions | `/promotions` | Static list/cards and anchors; no new detail URL unless reviewed |
| Activities/CSR | `/activities` | Static list/cards and anchors from activity items; no automatic expired-item hiding |
| Careers | `/careers` | List/cards, optional stable anchors, how-to-apply link/email only; no application form |
| Little Shield | `/little-shield` | Existing frozen structured programme fields; no signup/parent form or old Google Form link |
| FAQs | `/faqs` (new proposed path) or approved page sections | Owner confirms dedicated destination; questions/answers and frozen linked-service references only |
| Contact | `/contact` | Derived branch contacts/hours/map links, no contact form; no new social values from old site |
| Error | `/404.html` and BM counterpart | Fixed reviewed translations; actual unknown-path 404 status is a host/cutover test, not guaranteed by a file alone |
| Discovery/host candidates | `/sitemap.xml`, `/sitemap-services.xml`, `/robots.txt`, `_headers` | Generated from the reviewed map; no backend sitemap dependency. `_headers` is a host instruction file, not an HTML page |

OLD source also contains appointment, feedback, staff signup/admin/password/verification/maintenance/backups and KP Content OS product/legal pages. Do not generate these forms, auth flows or product policies. The old appointment/feedback URLs and any indexed product-policy links require explicit later continuity decisions before retirement. Missing functionality cannot be hidden by serving a generic 200 or silently redirecting it home.

### Query-detail alternatives

| Option | Recommendation / risk |
|---|---|
| Keep `?slug=` with one static shell and frozen local JSON | **Recommended first compatibility option, already the earlier owner default.** A small newly written external JS module reads only the allowlisted query, loads local frozen JSON, uses safe DOM text APIs, sets title/description/canonical and displays the selected service. No old backend/KPOne content API. Browser fetch of an artifact file is not a live read API. Generic raw HTML head remains the same for all slugs; link previews and crawlers without JS cannot be promised per-service metadata. |
| One generated file per service | Better raw HTML/title/canonical and no JS dependency for content. It introduces new paths unless a separately authorised, proven query-aware host mapping serves them at the old addresses. A bare static file server cannot choose different files just from `?slug=`. Do not invent a Cloudflare redirect capability or decide a clean-URL migration here. |

Unknown/missing slug gets an explicit unavailable state and noindex, never arbitrary-file resolution or live fallback. A query-shell response may remain HTTP 200 even for an invalid slug; record this soft-404 limitation honestly. A no-JS fallback links to the static services index; do not claim it is equivalent to the selected service. Category filters are not separately indexed pages unless the owner chooses that SEO model. No automatic query-to-fragment or clean-path canonical rewrite.

**Before cutover:** owner-reviewed old canonical/query inventory including `.html` variants, encoding/case/trailing-slash behaviour; URL collision/orphan checks; a reference rendered crawl with permission to inspect production; equivalent content/head/canonical/hreflang; representative social previews; rendered service content without old API; valid sitemap URLs and 404 status. Preserve the same public origin and Google property/verification. Replacing the backend sitemap with a static one requires an explicit submission/continuity plan. No crawl or Search Console request was performed for this analysis.

## 7. Images and visual design

**Images:** retain the already sanitised originals exactly, use hash filenames from release evidence, translated alt from the selected language, width/height attributes, responsive CSS and lazy loading below the fold. Do not invent alt or use a filename as its replacement. Semantic portrait/product images use frozen alt; decorative system elements may have empty alt by a fixed template rule. The same hash can have different translated/context alt attributes without duplicating its bytes.

Defer generated variants, format conversion, animation/video, SVG uploads, face cropping and adaptive image services. They add decoder/encoder version, quality, EXIF/colour, memory and hash variability. A later variant identity must include source hash + exact transform recipe + pinned codec version; originals remain unchanged. Large originals may make the first build too slow/heavy: refuse an aggregate budget, report which file is too large and request a separately approved optimisation slice; never silently lower quality or use remote optimisation.

**Look is an owner decision.** OLD `frontend/css/global.css:1`–`14` defines pink/dark-neutral tokens; `28`–`49` uses Inter body/Poppins headings. OLD service shell `29`–`42` requests Google Fonts. These are source observations, not a new approved design or licence to copy files. Options: rebuild the familiar current arrangement; design a new simple clinic-palette layout; implement an owner-supplied design. Default: familiar navigation and content hierarchy, simple owner-confirmed palette, system sans-serif stack initially. Ask for approved logo/brand materials through a future frozen contract; do not read live business logo/settings. New aesthetic direction or bundled font files require approval and rights review.

Target WCAG 2.1 AA through semantic landmarks/headings/lists, skip link, labelled navigation/language controls, visible focus, keyboard access, meaningful alt, contrast checks (including hover/focus/error states), zoom/reflow and reduced-motion support. No carousel/autoplay is needed. Verify 390, 768 and 1440 CSS px, portrait/landscape, 200% zoom and long EN/BM content; this is a test target, not an unsupported compliance certification. Print uses white background/dark text, readable content and sensible breaks, hides navigation only where harmless, and never truncates clinical/public service text or prints it as a certificate.

## 8. Artifact security, privacy and headers

Use context-appropriate escaping for text, attributes, XML, JSON and URL components. Never mark frozen text as raw HTML; newlines become CSS-preserved text/paragraphs, not markup. Validate permitted URLs again by context: HTTPS only where defined, no credentials/control characters, javascript/data/file schemes, HTML or arbitrary embeds. Resolve internal href/src through the frozen file/route map. No staff-supplied CSS/script/template. Reject path traversal, encoded traversal, absolute paths, null bytes, symlinks, case-fold/Unicode-normalisation collisions and duplicate file paths. Serve only exact manifest entries with an extension/MIME allowlist; never PHP, uploads or a directory listing.

Public output has no forms, cookies, localStorage identifiers, login, tracking, service worker or analytics. The query shell's own local JSON fetch is permitted only if explicitly authorised in the renderer slice; no outbound build-time fetch is permitted. Public click-to-chat, if included in the approved layout, is a plain `https://wa.me/<digits>` link derived from frozen public branch contact and the established strict number rules, without prefilled text/API/history. Never send public visitors through KPOne's staff-only WA-01 redirect. Map/social/apply links are deliberate navigation, not automatic embeds. `mailto:` may expose only the frozen approved public/HR mailbox; show the address plainly with a mailto link. Public addresses are scrapeable: neither obfuscation nor noindex guarantees protection. Owner should choose a dedicated public mailbox; do not substitute private staff email.

OLD source-visible external families: Google Fonts (`service-detail.html:29`–`42`); Railway catalogue/other API through `frontend/js/api.js:4`–`13`; map, social, `wa.link`/`wa.me` and Google Form links in public HTML; shared script loads first-party analytics (`frontend/js/main.js:943`–`970`). Inspected home/contact/branch/programme/shared files did not establish an external video embed or video provider. **Needs owner confirmation** for deployed scripts/embeds and anything injected at the host. Default: system fonts or separately licensed local fonts, maps/social/video as reviewed links only, no Google Forms, analytics or old API. No CDN script is needed.

Recommended header plan, to verify against the eventual host's current official capabilities before W1e-3:

- `_headers` candidate: restrictive CSP, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, minimal `Permissions-Policy` disabling unused browser capabilities, and review-only `X-Robots-Tag: noindex, nofollow`.
- CSP starts from `default-src 'none'`; allow only generated local CSS, local raster images and the exact fixed external script if query compatibility requires it. `connect-src` only local artifact JSON; no inline/eval, objects, frames, forms or base element. `frame-ancestors 'none'`, `form-action 'none'`, `base-uri 'none'`. No wildcard/CDN exception. No web fonts unless local approved files are added.
- Private preview needs its own runtime CSP sources scoped to the artifact route prefix and API-free assets. Do not simply copy host-relative `'self'` policy onto all of KPOne and call that isolation. Verify the query JSON request stays inside its artifact prefix.
- No inline executable scripts, handlers or style attributes. For structured data, recommend ordinary HTML microdata first; inline JSON-LD would need an explicit decision under the no-inline-script rule. Do not invent ratings, accreditation, pricing or clinic/legal facts to populate schema.
- While review-only, generated HTML/robots/header policy is noindex/disallow, and the private route independently enforces authentication/no-store/noindex. **Noindex is not access control.** `_headers` alone does nothing when served by Laravel; the adapter must set equivalent headers.

A later production profile must explicitly remove review-only blocking and be rebuilt, hashed and approved as a different artifact. The connector must reject the preview-only profile. Do not strip noindex or rewrite headers in place during deployment; that would break the approved byte identity. Never claim the candidate can safely go live just because its files load privately.

## 9. Determinism and integrity

The honest invariant is **same frozen release + same pinned renderer/output plan/profile = byte-identical logical files and artifact hash**. Current release approval alone cannot bind renderer changes. A different renderer/profile creates a new artifact, never overwrites an earlier one. Do not promise identical output forever from the release number alone.

Private artifact manifest: schema version, release number + exact release-manifest hash, renderer identifier/source digest, design/local CSS/JS resource digests, frozen URL/language/origin plan digest, target mode, and sorted entries `{relative_path, mime, byte_count, sha256}` for **every** HTML/CSS/JS/JSON/XML/text/image file. Artifact hash is SHA-256 of the canonical unsigned manifest excluding the manifest's own hash/signature. Do not hash ciphertext or attempt ids. Define the manifest as outside the deployable payload to avoid self-referential file hashes. Public metadata, if ever needed, gets a separate minimal allowlist, not this private manifest.

Build stamp is a fixed renderer version plus code/resource digests, not current time, machine paths or Git working-directory state. UTF-8/LF, explicit sort/tie-break rules using frozen sort_order then opaque key, preserved ordered lists, fixed translations, no locale/timezone defaults or dependency on current dates. Expiry/open dates remain frozen text/intent, not a live clock. Exclude actor, request id, wall time, random ids, temporary paths and runtime CSP nonces from file bytes. Build timestamps belong only to private action metadata.

Build twice in clean temporary directories and compare paths/MIME/length/SHA and complete plaintext bytes. Encryption deliberately has random nonces; encrypted containers need not be byte-identical. Hash/decrypt/size checks on every private read or export detect changed bytes; compare stored manifests with their pinned digests, and fail closed on absent/extra entries. Hashes alone do not defeat a privileged attacker who can replace both files and DB evidence: PostgreSQL immutability, filesystem permissions, encrypted backup/key custody and restore tests remain necessary.

If ZIP is later authorised, fix entry order/path encoding/timestamps/permissions and archive format; use a pinned writer, no platform file metadata. Compare the archive hash separately from the logical artifact hash. Uncompressed deterministic ZIP avoids compressor-version variation but is larger. Native ZIP extension availability is not verified by this documentation-only task; check before choosing it. Never shell out with content/path arguments.

## 10. Storage, size and retention

**Default:** encrypted private generated files and an immutable DB file index, with exact immutable references to the selected release's existing media rows. A complete *logical artifact* includes every image path/hash; its private reader or future archive materialiser resolves those retained bytes. It has no live website-media/old-site/runtime dependency. Do not pretend an archive excluding images is a complete deployable handoff.

| Option | Assessment |
|---|---|
| Another full encrypted DB blob/archive with all images | Simple single object but repeats W1e-1's image-copy cost, increases WAL/backups and large reads. Not the default. |
| Private filesystem generated files + immutable release-media references | **Recommend.** Avoids a second retained copy of originals, streams one file at a time, and still reconstructs the exact bundle from immutable evidence. Requires DB/files/key backup and orphan-stage recovery. |
| Retain a complete encrypted archive on private disk | Reasonable alternative if the owner needs independent portability. Extra media copy per artifact and deterministic archive tooling required. |
| Public disk, mutable live image paths or remote asset URLs | Reject: disclosure or loss of historical integrity. |

No DB was queried, so the number of current doctors, services, selected images or their real byte sizes is **unknown**. Three branch page types are established by source, not a measured release. Illustrative planning scenario only: 3 branches, 12 doctor profiles, 100 services, 12 categories, 6 promotions, 10 activities, 4 careers and 20 FAQs, plus Little Shield. At roughly 250–400 bilingual HTML pages/sections and small local assets/JSON, allow **5–15 MiB** generated text initially; at 100 distinct images averaging 1 MiB, another **100 MiB raw** is referenced. A second artifact then adds approximately 5–15 MiB text/index/encryption overhead, not another 100 MiB of stored originals. A full archive would add about 105–115 MiB before encryption. These are budgets, not measurements or performance claims.

Each newly frozen release still duplicates its selected images in W1e-1 PostgreSQL storage. Its explicit base64 plus encryption envelope costs more than raw bytes, plus indexes/WAL/backups; measure actual usage later rather than promise a precise ratio. Referencing release media from the artifact removes only the **extra artifact copy**, not that existing per-release cost. At 50 such releases the illustrative raw retained media alone is about 5 GiB before encryption/backup overhead.

Initial proposed hard refusal budgets: 2,000 logical files, 250 MiB total plaintext output, 30-second render deadline, 512 MiB worker memory; stream each retained image rather than loading all media into memory. Use preflight metadata counts/lengths and runtime counters. Limits are owner-confirmable starting points, not verified capacity; an over-budget build fails without truncation or visible partial files. Benchmark maximum permitted fixtures before accepting those limits.

Immutable successful artifact evidence/files stay indefinitely by default, as do failed attempt metadata and source releases. No delete/purge UI, cron or retention migration in the first slice. Random encrypted temporary staging is not issued evidence and may be removed after failure or by a narrowly scoped next-request stale-stage cleanup. Never delete a built artifact because it was superseded/withdrawn. A future purge/archival policy needs owner/privacy/restore decisions and must not weaken source immutability.

## 11. Private preview and optional archive

Propose a nested authenticated artifact-file route under the existing release workspace, not a public storage link or KPOne content API. Fresh prepare permission, organisation and effective-branch authority on **every** file, including CSS/JS/JSON/images. Resolve an artifact id and exact normalized manifest entry, verify bytes/MIME, require completed artifact and currently approved source release, and return private/no-store/noindex/nosniff. No path supplied by staff becomes a filesystem lookup. No public symlink, unguessable-URL-as-security, external preview host or signed anonymous link.

Open preview as a separate top-level private page so it receives only its own static CSS/scripts, not the Inertia layout or staff data. Relative file and page links must work below an artifact sub-path; no root-relative assets, no `<base>`, and no rewriting file bytes per request. Put depth-correct relative href/src in output; canonical/hreflang may use the approved public origin as metadata. Preserve allowlisted query strings. Preview cannot prove the eventual host's clean-path/404/query handling; those are separately checked later. A file opened via file:// may block local JSON fetch, so a ZIP is not automatically a working local preview without an HTTP server.

Do not embed arbitrary artifact HTML unsandboxed in the staff shell. An iframe, if later chosen, needs an explicit security prototype: sandboxing without same-origin can break authenticated JSON requests; allow-scripts plus allow-same-origin is not a meaningful isolation boundary by itself. This analysis does not promise both isolation and working query JSON from a generic iframe flag. Initial top-level preview is system-rendered code only, escapes all content and uses a CSP limited to its exact static resource paths; test that scripts cannot access unrelated KPOne endpoints or escape into a staff-page view.

Audit preview opening once per page/session action with artifact/release ids and file counts, not content or URLs/contacts. Do not audit every image as a separate staff review event. Audit failure refuses the opening. Every subresource still checks access/withdrawal even when no separate audit row is needed. Future ZIP, if explicitly authorised, is a private POST-triggered, throttled verified stream, audited **before any byte is released**; audit failure returns no archive. No stored public ZIP, email or job. Default: defer ZIP until the connector's handoff format or an owner need is clear.

## 12. Build identity, states, approval and execution

**Separate artifact records, no new release state or change to release tables.** Proposed additive evidence families: immutable successful `website_artifacts` header/recipe/file manifest; immutable `website_artifact_files` hash/reference index; versioned `website_artifact_attempts` with UUID retry/payload identity and bounded state; append-only `website_artifact_actions` for attempts, results and exact-artifact decisions. Exact DDL/command placement is an implementation review item, not permission for a framework. Protect identities/files/actions against update/delete/truncate and late insertion after completion; control rows allow only enumerated transitions/version increments. down retains evidence.

| State | Meaning |
|---|---|
| requested | Audited intent accepted; no files visible |
| building | This synchronous request owns the bounded attempt/lease; no queued worker is implied |
| built | All files verified and immutable header/index committed atomically; separate review remains pending |
| failed | No artifact exposed; append bounded failure code, not a content-bearing exception body |
| superseded | A later explicit Director artifact decision marks an older artifact ineligible for future handoff; bytes retained, not a release withdrawal |

Director artifact approval is a separate append-only review event on a built artifact, bound to artifact hash, recipe, release number/hash and current versions. Reuse `website.releases.approve.organisation` with prepare authority; no new grant. Approval is not a publish action. If the owner declines this separate step, freeze the renderer/route plan before a new release approval instead; current approved release hashes do not bind either, so content approval alone is insufficient.

Build/view defaults: existing `website.releases.prepare.organisation` holders Director, Marketing, Administrator. HR/Technical Admin/Finance/other roles gain nothing. No typed release number merely to build (an inert private action); show selected approved number/hash explicitly. Artifact approval does require the typed release number and expected artifact hash/version, with a same-person disclosure where applicable. Deny all builds from prepared/refused/withdrawn releases and recheck fresh grants.

Suggested synchronous flow:

1. Fresh authority; transaction and consistent locks (organisation/release/control, then artifact attempt). Verify approval, source hash, requested versions and UUID payload fingerprint. Same key/same inputs returns the existing attempt/result; changed release/renderer/profile under the same key refuses. Serialize duplicate input keys; do not invent a second artifact on retry.
2. Audit requested/building before rendering. Capture verified immutable DTO and pinned recipe, then release DB locks. Render to unpredictable encrypted private staging with no DB reads/network. No partial route can serve staging.
3. Compare two clean builds, run local file/link/safety checks and verify limits. In a short transaction reacquire locks in the same order, recheck release still approved/version and actor still authorised, commit immutable artifact/index/result plus audit. File-store/DB operations are not one atomic transaction: stage under a private attempt path, commit only a complete index, and never serve absent/unindexed bytes.
4. On timeout/corruption/withdrawal/audit failure, expose no completed artifact; remove only that attempt's staging and retain truthful failed/interrupted intent. A killed request may leave building/staging; reconcile its lease on the next authorised request without adding a scheduler. Audit unavailability may prevent a final failure event: retain the earlier intent and do not manufacture success.
5. Withdrawal after completion immediately blocks new builds, file preview, approval and future export. Retain evidence; structured release history retains its existing behaviour. This cannot recall any externally saved copy and does not implement public-host withdrawal.

No long DB locks around CPU/file generation. No job, broker or background retry. An Artisan command is useful only as a developer determinism tool later; it is not the first staff-facing bypass of user authority. If bounds are too small, fail safely and review a separately authorised queue (existing database queue before any broker), worker identity, cancellation, fresh authority and withdrawal rules. No speculative queue infrastructure now.

## 13. Testing, evidence and existing assertion forecast

These are proposed tests only; none are written or run in this documentation task.

| Area | Required evidence in a later build |
|---|---|
| Determinism | Two clean renders, exact plaintext bytes/tree/hash; pinned locale/timezone/toolchain/resources; input order/list order; different profile or renderer produces a distinct immutable artifact |
| Privacy/escaping | Sentinels in every private/top-level/nested field, URLs, alt, metadata, JSON/XML; search every generated file for leaks; hostile quotes, tags, closing-script strings, controls, bidi and path encodings; no raw HTML sinks |
| Read/write boundary | Zero-query renderer; exact frozen reader table/column/release scope; only adapter authority/control reads; **no staff-name page lookup**; changed live source cannot change output; full ID-ordered all-table comparison excluding only declared artifacts/audit |
| Authority/state | Every catalogue role, revocation/branch/org isolation, approved-only, concurrent withdrawal, stale versions, matching/conflicting retries, concurrent identical builds, typed artifact approval, audit failure and partial-files refusal |
| File integrity/storage | Missing/corrupt/encryption-key failure, hash/length/MIME mismatch, no extra file/late insertion, immutable SQL update/delete/truncate, temp crash cleanup, size/time/memory refusal; complete materialised bundle includes images |
| Links/URLs | Offline manifest-based checker for every internal href/src/CSS URL/JSON reference, query aliases and language targets, anchors, collisions/404s; validate HTTP(S)/mailto/tel/wa.me without visiting external sites; no Railway/KPOne/public API dependency |
| Markup/accessibility | Offline HTML parsing/conformance checker with its parser/version reviewed before adding a dependency; no obsolete tags/duplicate ids; automated a11y if separately approved tooling is available, plus keyboard/focus/contrast/screen-reader/reflow/reduced-motion review; no unsupported AA claim |
| SEO | Unique static titles/descriptions where inputs exist; raw and browser-rendered service heads; canonical origin/query, reciprocal hreflang, OG title/description/URL/image, sitemap XML escaping/deduplication, robots/profile noindex, microdata only for supported facts; no invented review/rating/medical claims |
| Browser/network | Real Chrome at 390/768/1440, both EN/BM and long content, staff shell themes; public-page style/print readable. Record requests and prove only private artifact subresources load, zero third-party/old API/tracker/cookie creation; links stay within the artifact prefix |
| Operations | Separate task-owned PG18 full gate, fresh migrations/seeds, restore of DB/generated files/keys, no public disk or outbound calls; connector/deployment tests remain separate |

Automated HTML/a11y validator choice is not an unapproved dependency. First use the repo's existing parsers/browser tools where sufficient; if they cannot provide conformance/accessibility validation, explicitly approve a pinned local dev-only tool rather than pretend regex proves validity. No online validators/upload of private previews.

**Expected existing assertions under the recommended nested UI/reused permissions: none.** Forecast exact possible changes in advance, not permission to make them:

- Permission exact sets in `WebsiteDraftTest::test_additive_grants_replay_without_revoking_custom_grants_and_down_keeps_evidence` (`:450`), `WebsiteLittleShieldTest` exact global list and `StaffAuthorityBoundaryTest::test_director_provisions_all_three_website_roles_with_exact_approved_permissions` (`:311`): **unchanged** because no new grant. A new build/export grant would need separate owner approval and exact old/new lists.
- `ClinicManagementTest::test_hub_returns_only_authorized_implemented_destinations_for_every_catalogue_role` (`:14`) and `tests/Frontend/clinic-management.test.mjs:32`: **unchanged** if artifact actions stay within Website releases. A separate artifact hub destination requires an explicitly authorised map/navigation update.
- `tests/Frontend/website-releases.test.mjs:44`–`46` forbids iframe/window.open/fetch/publish/deploy in the current structured page/components. Prefer a regular authenticated Link to a separate preview route; keep that assertion. Do not remove its public/network prohibitions to accommodate preview.
- `WebsiteReleaseTest::test_all_unrelated_rows_queries_http_jobs_scheduler_and_audit_are_values_free` (`:423`): unchanged source-action invariance and staff-query allowance. Artifact reader has its own stricter query tests; do not broaden this again for renderer reads.
- Immutability tests already use release evidence `TRUNCATE ... CASCADE` with exact P0001 (`WebsiteReleaseTest:364`–`370`) and revision CASCADE P0001/plain 0A000 (`WebsiteDraftTest:324`–`348`). Keep FKs/codes. New artifact FK/trigger guards must preserve these; any unexpected different existing failure requires a stop, not dropping an FK or widening error codes.

No exact future assertion list is guaranteed before implementation. Every newly discovered existing failure must be reported for owner authorisation. No staff-authority rule change.

## 14. Proposed split and explicit authorisations

| Slice | Small scope and tests | Proposed migrations / risk / approval needed |
|---|---|---|
| Contract decision before 2a | Confirm immutable URL/language/origin/design profile and source of missing page/brand content. Approve the frozen output-plan exception or require a new freeze schema; do not implement either on this analysis branch | Blocks a complete compatibility artifact. No live slug/settings lookup or old content import as a shortcut |
| **W1e-2a** renderer + retained artifact | Pure Blade rendering, approved-release reader, nine-type projections, fixed reviewed public resources, complete logical file manifest, private storage/media references, bounded synchronous build; management action within releases, no file-serving preview yet. Determinism, leakage, integrity, limits, retry/audit/state and row-invariance tests | Additive artifact/attempt/file/action evidence migration, no new permission or change to release v1. Explicit owner authorisation for renderer/resources/storage and the input contract; small-to-medium implementation once blockers resolved |
| **W1e-2b** private preview + exact artifact review | Authenticated manifest-file adapter, sub-path links, strict CSP/no-store/noindex, Director artifact approval and withdrawal blocking; responsive/browser/print/a11y checks. ZIP deferred by default; if authorised, verified audited private download only | Reuse artifact action/control evidence and existing prepare/approve permissions; only a narrowly required additive extension if 2a cannot already represent review. Main risk is preview-origin/script isolation and artifact-versus-release approval confusion |
| **W1e-2c** URL/SEO continuity evidence | Final reviewed alias/language/origin plan, static sitemap/robots/header candidates, offline link/SEO checker and authorised rendered-crawl comparison. Inventory redirect requirements only; no running redirects, query-aware edge handler, DNS, provider call or cutover | No live draft/old DB read; no migration expected if output plans were frozen in 2a. Query-vs-clean-URL choice remains the owner's. Can refine the map later, but the initial route contract cannot be postponed until after a supposedly complete 2a build |
| Later **W1e-3** | Production-profile artifact rebuild/review, connector, observation/rollback and token custody, separately specified | Requires new explicit network/hosting/production/permission decisions, current official host capability verification and a non-preview approved artifact |
| Later **W1e-4**, settings, **W2**, **W3** | Cutover/retirement; new settings/content; appointment intake/privacy; analytics/GSC each separately scoped | None implied by W1e-2. No old backend retirement while unresolved public forms/legal/SEO behaviour remains |

Do not call a unit-tested renderer with missing URLs/logo/About text a complete site; surface readiness blocks. The split is a review sequence, not authorisation to stub later features.

## 15. Ranked risks and owner decisions

| Priority / decision | Options and recommended default | Other choice / consequence | Blocks |
|---|---|---|---|
| **1 — Frozen route/brand input gap** | Authorise a separate immutable output plan bound to the approved release, with no live content reads, or a new freeze schema. **Default: explicit frozen output plan exception, reviewed before build** | Keeping literally release-only inputs means legacy-compatible real artifacts must wait for a new freeze schema. Guessing aliases, reading internal slugs/settings or importing old data is unacceptable | Complete **2a** |
| **2 — Public visual design** | Familiar structure, simple clinic-palette rebuild, system sans-serif, separately supplied approved brand assets. **Default** | Exact visual reproduction still needs rebuilt code/rights; a new look needs owner design review. No global logo in release means a brand source must be explicitly approved | Real-content **2a** |
| **3 — Query detail SEO** | Keep `?slug=` + frozen local JSON initially. **Default consistent with existing owner decision**; demonstrate rendered parity | Per-item HTML/new URLs needs explicit clean-URL/query-aware host decision and tested redirects. JS shell has generic raw head/social and soft-404 risks | URL contract **2a**; parity **2c/go-live** |
| **4 — Artifact approval** | Reuse existing Director approve authority for a separate exact-output decision. **Default** | Content approval alone does not bind renderer/URL/security profile; alternatively refreeze that contract before release approval | Schema/workflow **2a**, review **2b** |
| **5 — Storage/retention/budget** | Encrypted private generated files + immutable release-media references, indefinite evidence, proposed 250 MiB/2,000 files/30 s/512 MiB, benchmark before adoption. **Default** | Full retained archive is more portable but repeats images; lower caps refuse more builds; no silent truncation or purge | Storage/limits **2a** |
| 6 — Missing home/about/legal/global copy | Only render approved existing frozen content; keep absent custom content as a readiness blocker. **Default** | Fixed minimal About/navigation prose needs explicit wording approval; richer content needs a separately authorised settings/content freeze. Product policies stay external | Complete **2a**; legal continuity before cutover |
| 7 — Language URL structure | Preserve English paths; mirror under `/bm/`, HTML/hreflang `ms`, explicit switch, no language cookie. **Default proposal** | Different prefixes or translated paths need their own stable mapping/SEO review; never auto-translate | Initial contract **2a** |
| 8 — Image sizes/formats | Exact originals and no variants. **Default** | Derivatives need pinned encoders/budgets/rights and new artifact approval; large original bundles may be refused | Limits **2a**; variants can wait |
| 9 — Private HTML/script origin | Separate authenticated top-level page, exact artifact resources, strong CSP. **Default** | Iframe needs a tested sandbox/data-fetch model; public preview host is out of scope | **2b** |
| 10 — Noindex profile | Review-only bytes/robots/headers with noindex and connector-ineligible marker. **Default** | A production profile is a distinct rebuilt/approved artifact, not an in-place edit. Forgetting removal blocks SEO; forgetting privacy exposes content | Profile **2a**; production later |
| 11 — ZIP | Defer. **Default** | If needed now, specify writer/format/determinism, audited-before-streaming private delivery and no file:// functionality promise | Can wait beyond **2b** |
| 12 — External links/mail | Public mailboxes/plain wa.me/map/apply links only when frozen and approved; no automatic embeds/forms/fonts/tracking. **Default** | Public email can be scraped; linking a service does not approve an integration. Any auto-loading third party needs explicit privacy/security review | Layout **2a**; exact destinations before cutover |
| 13 — Structured data | Valid HTML microdata for approved facts; no inline script. **Default** | JSON-LD needs a decision on the no-inline-script constraint and exact safe serialization/CSP treatment | Can wait to **2c** |
| 14 — Retired media and withdrawal | Release withdrawal is the authority signal; block artifacts immediately while retaining evidence. **Default** | Automatically following live media retirement conflicts with release-only rendering; needs a separately authorised revocation mechanism, not a hidden live read | Rule **2a**; public removal **W1e-3** |
| 15 — Host/verification/cutover | Confirm deployment source, existing aliases/verification and legal/form retirements before go-live; retain current origin/property. **Default** | Guessing host rewrites/redirects or dropping sitemap/form URLs risks indexing/data continuity | **W1e-3/4**, not pure renderer |

The top five are prerequisites/owner decisions, not work delegated to an agent by this report. No production budget, brand, current indexed URL count, storage size or host support was measured here.

## 16. Explicitly not authorised and analysis validation

This analysis does **not** authorise any renderer/template/code, migration/model/table/permission, build/preview/download route, new test or dependency; output-plan schema or source-freeze change; artifact generation; static/public file serving; deployment/publish/rollback; Cloudflare/R2/edge call; DNS/redirect/cutover; site-settings/legal content; appointment/feedback/applicant/contact forms; analytics/GSC/reporting/tracking; messaging/API/OTP; old-site code/assets/prose/data import; operational/staff/clinical/billing/stock access; changed release evidence; new queue/job/scheduler; purge or production use. Do not modify AGENTS.md.

Validation for this one-file documentation change: required ancestor/base checks, read-only source inspection, `git diff --check` and exact single-file scope before local commit. No application gate, database connection, browser evidence, external research or provider validation is claimed or needed for analysis. Old clone status remains clean and its tip unchanged. One local commit only; no push, PR or merge. Wait for explicit owner instruction.
