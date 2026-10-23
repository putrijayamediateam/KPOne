# W1e analysis — approval, immutable releases and static publishing

**Analysis only, 2026-10-23.** Base: fetched `origin/main` b49a96d. This document is the only change on `docs/w1e-publishing-analysis`. No application code, migration, permission, test, public route, secret, deployment or network integration is added. Nothing below authorises a build or a go-live.

## Recommended direction

Keep staff editing in KPOne and serve a complete, independently hosted static website. Marketing and Administrator prepare general content; HR prepares doctor profiles and careers through their existing narrow permissions. The Director alone approves the exact frozen release and authorises publication. Approval means permission to disclose **these bytes**, not a blanket approval of an item or its future edits, and not proof that medical wording or consent was professionally verified.

Freeze exact saved EN/BM revisions, their shared references, sanitised media bytes and the renderer revision into one immutable, hashed release. Provide an authenticated preview inside KPOne. Include only explicitly selected, complete, active content and its complete dependencies. Never release consent/rights references, staff identities, private storage paths or operational data. An inclusion flag is intent, not approval.

Recommend a complete static Pages deployment from this release, with media bundled initially. Keep Cloudflare credentials in the future hosting platform's secret store, not content tables or the repository. Do not expose a KPOne content-read API. Separate offline release preparation from the later, explicitly authorised deployment connector and production cutover. Appointments remain a separate W2 decision: one narrow public write ingress, CA review, no automatic booking or patient/Visit/Queue creation.

Before cutover, preserve URL behaviour and the existing Google property. There is an important constraint: the old service-detail address uses a query parameter. Plain static hosting cannot serve different server-generated HTML heads for different queries to the same file. Do not promise both new static per-service HTML and unchanged query URLs without choosing an explicit compatibility strategy.

## Evidence and limits

KPOne references: `AGENTS.md`, `CLAUDE.md`, `docs/BASELINE.md` section 6 items 13–14, reports W1a–W1d, `WebsitePermissions`, `WebsiteAccess`, the four draft services/field validators, `WebsiteImage`/orientation handling and migrations `2026_10_24_000100`–`000600`. Draft revisions and commands are append-only; media bytes/identity are retained with hash/size/dimensions and a retirement action. Current branch associations are mutable projections; **freeze references from the chosen revision**, not from that child table. Current internal slugs are expressly not public URLs.

The separate Job 1 fix coordinates shared references and blocks service archival with linked active FAQs. It is not in this analysis branch's base; merge and validate that prerequisite before publishing work. The publisher must independently reject legacy divergent/dangling references, even if an editor notice exists.

Read-only old-website inspection used `/Users/klinikputrijaya/MiniWeb-readonly`, clean commit `0fe19da`: docs, shared navigation/API client, service catalogue/detail scripts, sitemap route, robots/sitemap, public page structure and performance schema/service. No old code, campaign wording, images, records or credentials are included here or proposed for automatic import. No server, dependency installation, production database, browser crawl or provider call was used. A filename-only check found no real `.env` or credential/service-account file. The clone is not evidence of today's deployed contents. Provider capabilities, Google export windows, hosting and DNS must be checked against official documentation and the owner's account later; outbound research was not allowed in this task.

## Preparation and approval

1. Editors save through existing scoped services. Unsaved form state never enters a release.
2. A preparer selects item/language revisions and the intended full-site manifest. Preview is saved-release-only, authenticated, no-store and noindex, inside KPOne. Show omitted items and why; show the exact public projection separately from private approval references.
3. Under fresh permission/organisation/effective-branch authority and consistent locks, freeze source versions, reference graph, public URL map, renderer/version and media hashes. Allocate an organisation-local `WEB/{year}/{six digits}` release number, independent of clinical document counters. Gaps are acceptable; numbers are never reused. Store UTC creation/approval times and localise the review UI.
4. Director sees a change list against the live release: text, images, branch availability, manual price/offer/registration text, public contact details, routes/canonical metadata, omitted pages, rights/consent references and completeness results. Human references are not automated verification. Clinical claims require the clinic's separate medical review.
5. Approval is an append-only event bound to the full manifest hash and renderer revision. Default: later source edits or asset retirement make a pending deployment stale and require a new freeze/review/approval. Do not silently rebuild an approved release from live rows. If the Director also prepared it, disclose that fact; do not invent an impossible second approver requirement.
6. Before deployment, repeat fresh authority, asset availability/withdrawal and approval checks. Explicitly confirm the target site and currently live release. No background publication follows an editor's save or an intent flag.

### Selection rules

| Situation | Recommended rule | Risk of a looser rule |
|---|---|---|
| Include flag false | Exclude; enumerate the omission in review | Accidental disclosure of drafts |
| Archived item | Exclude; never resurrect implicitly | Withdrawn content returns |
| Included but incomplete in EN or BM | Block preparation with item/language/field errors | Silent language fallback or misleading partial information |
| Reference missing, archived, foreign, divergent or not included | Block; list the required dependency and let the editor fix it | Broken links or release of unreviewed content |
| Active child with omitted category | Require an explicitly included complete parent | Orphaned navigation |
| Retired, unavailable, corrupt or hash-mismatched media | Refuse the release; no silent substitution | Unapproved image or missing consent |
| Dates/offer/open flags | Remain descriptive intent in the first publisher; review expired wording explicitly | An unauthorised scheduling/automatic-hiding feature |
| Doctor profile | Require existing profile/portrait consent and optional registration-display reference; no staff/credential lookup | Staff data becomes public by implication |

Both languages should be complete for an included item by default. Alternative single-language publication needs an explicit owner decision and clearly labelled omissions; never auto-translate or copy text. Reference checks should be performed against one consistent database snapshot while rows are locked. The release retains its exact own graph after later drafts change.

## Release contents, storage and rollback

Use a small domain-specific release service, not a document framework or a general template editor. Proposed additive tables: `website_releases` (immutable identity, number, organisation, renderer, canonical manifest/hash), `website_release_items` (exact source revision ids/hashes and frozen public projection), `website_release_media` (source asset ids, byte hash, MIME, dimensions, byte count and retained artifact identity), and `website_release_actions` (prepare/approve/refuse/withdraw/other explicit decisions). PostgreSQL refuses update/delete/truncate of evidence. A separate metadata control row carries `lock_version`; deployment attempts/outcomes are separate append-only records. No change to MC, referral or attendance counters/tables.

The internal manifest contains revision/asset provenance and private review metadata. Keep it in access-controlled private storage with encrypted backups; encrypt a private payload if it includes consent references. The **public** manifest/output is an allowlisted projection: no consent/display-approval references, rights notes, internal ids, audit metadata, draft histories, storage paths, staff accounts or credentials. The public artifact holds only approved website content and public asset filenames based on hashes. Retain source revisions and exact sanitised image bytes, not just a mutable path or a URL to fetch later. Verify bytes against stored hashes during capture and before upload. Do not fetch remote images.

Build a deterministic complete output (HTML, local CSS/JS, public JSON needed for static search/detail rendering, images, icons, sitemap, robots and reviewed redirects if separately approved). Every output file has a safe relative path, MIME, byte count and hash. Include a renderer source revision and output hash; changing the renderer needs rebuilding and reapproval. No arbitrary staff HTML/CSS/JS, expressions, uploads of templates, private JSON fields or imported old-site code. Escape text, attributes and embedded JSON. A template build must not read patient, billing, clinical, inventory or staff tables, except the existing acting-user authority checks.

Rollback means re-deploying the exact retained approved output, not recreating old content using the current renderer. Director confirms it anew. Refuse rollback if a required asset has been withdrawn for consent/privacy reasons; a corrected new release is needed instead. Retain replaced releases privately; no purge mechanism is proposed here. Public copies/caches cannot be guaranteed erased by retiring an asset internally, so withdrawal needs a host/cache removal procedure and a clinic retention decision.

## Publishing and secrets

Recommended flow:

`Saved private drafts → frozen release → private preview → Director approval → verified full static artifact → authorised Pages deployment → verify observed release → record outcome`

| Option | Assessment |
|---|---|
| Pages direct upload, complete artifact | Recommended initial direction, subject to account/API verification. No runtime KPOne reads, one deployment unit, simple exact-artifact rollback |
| R2 public JSON/images with a mutable current pointer | Adds credentials, caching/mixed-release and client-rendered SEO risks. Consider later only if needed; keep all resources versioned and switch one verified manifest |
| Build hook/Git-driven deployment | Extra Git/build credentials and reproducibility coupling. A hook alone does not prove which saved revisions were built; not the default |
| Live KPOne read API | Reject for this programme: public availability and load become coupled to the internal app and expose another access boundary |

The connector would make a server-side HTTPS call to Cloudflare's Pages direct-upload/deployment API for one allowlisted account/project. It must not accept a staff-supplied host or deployment URL. The exact current upload, staging, promotion and atomic activation capabilities/token scopes are **not verified here**. Confirm them before implementing the connector; do not assume a public preview URL can be made private. All pre-approval preview remains inside KPOne. Only a fully approved artifact may leave private storage.

Default: bounded synchronous release build and explicit deployment attempt; no new queue/job in the first offline slice. Do not hold database locks while doing network I/O. Commit a deployment intent plus its audit first; if that audit fails, send nothing. Upload only a hash-verified complete artifact, and activate only when the host has confirmed the complete deployment. Never overwrite live files one by one. If provider support cannot meet this, stop the connector design for an owner decision.

Database and provider state cannot be one atomic transaction. A timeout after upload may mean success: record an unknown outcome, reconcile using a provider deployment id/release hash, and never blindly create a second publish. A post-delivery audit/storage failure cannot undo a successful external activation. Preserve the prior audited intent, flag the discrepancy, block further publish attempts and reconcile. Idempotent commands, one active attempt per organisation, short row locks and a versioned control record prevent two staff publishing concurrently. A new outcome is appended; an earlier record is not edited to pretend success.

| Configuration/secret | Placement and access |
|---|---|
| Pages API token | Future hosting platform secret store, minimum available scope for the intended project/account; service access only, redacted everywhere, rotate and revoke on compromise |
| Account/project/production target, approved public origin | Non-secret deployment configuration, allowlisted; not arbitrary content fields |
| KPOne encryption key/private artifact credentials | Existing platform-managed secret arrangements, separately backed up and restore-tested; never in release/public output |
| Build-hook secret or R2 keys | Not needed for the recommended first route; do not create them speculatively |
| Turnstile secret/site key | Only if W2 is later authorised: secret at ingress, public site key in deployment config; not a content-editable field |
| Google key | Not needed to publish. Separate later reporting authorisation; never copy the old account key |
| SMTP/email provider secret | Not needed: no publishing email/job is proposed |

Audit release ids, source revision ids, hashes, action, target id, UTC time, result code and counts only. Never audit content, recipient/contact values, tokens, request bodies, signed URLs or provider error bodies. Token rotation is an operational action, not new Technical Admin content access.

## URLs, Search Console and cutover

Source-established patterns: extensionless public pages; `/services?category=<slug>` filtering; `/service-detail?slug=<slug>` detail/canonical assembled in JavaScript; a static main sitemap and a separate backend `/sitemap-services.xml`. The dynamic sitemap includes distinct nonempty slugs only for active services whose subcategory and category are active. Robots currently refers to the backend sitemap. Navigation and other content still fetch the old API. Static-site production hosting is documented as Pages but Firebase files also remain: the owner must confirm the actual deployment source before changing anything.

Keep the public origin and existing URL behaviour initially. Collect a reviewed mapping: **old canonical path plus allowlisted query → new public content key/language → release output/canonical → response/redirect decision**. Include legacy service and category slugs, percent encoding/case/trailing slash and `.html` aliases, static branch/community pages, sitemap URLs, 404 and legal/product links. Detect collisions and orphaned URLs. Internal KPOne slugs cannot automatically replace these public addresses. An owner-approved URL inventory is not permission to import old content, bookings, feedback or images.

For service detail, default to preserving the current query-address behaviour using a newly built static shell and release JSON, with no old backend call. Its dynamic canonical/content/metadata must pass a JavaScript-rendered crawl at least as well as the existing site. This does **not** provide different server-side heads per query. If the clinic requires fully pre-rendered per-service HTML, obtain explicit approval for new paths plus tested query-aware permanent redirects at the host; verify host support first. Block production cutover until the owner chooses and parity is demonstrated. Do not silently change canonical URLs.

The same Google Search Console property retains its history independently of the website backend. Keep the domain, ownership/verification and authorised property access; the clone does not establish whether verification is DNS, file or meta-based. Do not delete or recreate the property. Preserve any current verification artifact; confirm DNS and actual hosting with the owner. A new reporting service account, if later authorised, gets least-privilege read-only access to the **same** property, with a newly managed secret.

Before retirement, owner-authorised operators should export available Search Console history (plan around the usual roughly 16-month window, but confirm current limits), including dated JSON/CSV summaries and available page/query dimensions with export coverage/caps disclosed. Encrypt the archive outside Git. Archive MySQL and uploads with a verified restore procedure, and separately archive first-party performance data/definitions. Its schema has session/event keys and page paths: it is not automatically anonymous; booking-related event keys may be linkable. Default archive-only, no old raw events/personal-data import into KPOne. Decide minimisation/destruction with the owner/privacy adviser. No live backup, export or crawl was performed here.

Cutover checklist:

1. Approve URL inventory, property/verification owner, legal page operator, content/media rights and the actual Pages project. Preserve separate KP Content OS product/terms/privacy links without merging its restricted service or its operator's policy into clinic intake policy. No public staff-login link.
2. Export archives above; test old-host rollback and exact-release deployment rollback. Keep old backend read-only/available for a planned parallel period until all dependent public calls/forms are replaced or deliberately withdrawn.
3. Generate sitemap/robots from the release; replace the retired backend sitemap dependency with an equivalent static public sitemap and update its references/submission. Preserve historical sitemap routing where practicable. No global noindex, accidental robots block, missing canonical, private preview route or internal hostname in output.
4. Compare pre/post rendered crawl inventories, status codes, redirects, headings, canonical/meta/structured data, branch contacts/hours, images, assets and mobile layouts. Validate all menu/detail/search data comes from the release, not Railway or KPOne.
5. Owner-authorised production activation only after W2/form behaviour and privacy are resolved. Check TLS/domain routing, representative URLs, both sitemaps, verification, 404 and form acknowledgements immediately.
6. For 2–4 weeks monitor Search Console indexing, sitemap fetches and performance with reporting-delay/seasonality context. Default rollback triggers: widespread new 404/noindex/canonical errors, sitemap failure or a material indexing loss linked to the release. Owner sets numeric thresholds after measuring baseline. A normal reporting delay alone is not proof of harm.

## Appointment requests: separate analysis, no build

A static form can submit to one narrow HTTPS ingress without any public content-read API or staff login. Recommended topology, pending hosting: a tightly limited path/host proxying only intake into the KPOne intake service, with no route to staff/session/admin endpoints. KPOne's public exposure and hosting are undecided, so a small separate ingress encrypted mailbox that KPOne pulls from is an alternative, but adds another data store and reliability boundary. Do not expose the entire internal application for convenience. No infrastructure or endpoint is authorised here.

Default fields: contact name (150), normalised phone (existing validated number rules), branch public key, preferred local date and bounded time preference, consent acknowledgement and privacy-notice version. No NRIC/passport, DOB, symptoms, reason, diagnosis, medicine, free clinical text or uploads. A requested date is a preference, never a reserved slot. Use bounded body size, strict allowlists, CORS/origin allowlist as a browser aid (not authentication), honeypot, server-verified bot challenge if selected, per-IP transient and per-phone HMAC limits, an overall cap and UUID retry key with payload-conflict refusal. Do not retain raw IP or log phone/body. Challenge verification is a new outbound integration requiring W2 approval.

Encrypt personal request fields at rest; store UTC received time and typed branch-local preference, minimal status/control metadata and a notice record. Recommend 30 days from receipt then purge the intake payload, like the QR intake's short-lived purpose, subject to clinic/legal approval and a separately tested purge design. Existing patient records have a different retention policy. CA/CA Supervisor at the requested effective branch review, correct, accept/reject and optionally contact via the separately authorised plain click-to-chat flow. On acceptance, mark the request reviewed and hand off to normal Registration with explicit CA confirmation; **no automatic Patient, Visit, Queue entry or appointment**, no slot promise. No new appointment record/workflow without a separate decision. Start with an in-app pending list; defer email/WhatsApp notifications and provider credentials.

The public notice must name the correct clinic operator, explain purpose, review rather than booking, access, retention, withdrawal/contact and emergency limitations. Guardian/minor procedure needs clinic/privacy advice. A checkbox alone does not prove compliance. Public feedback, patient messaging, portals, reminders and analytics collection remain separately unauthorised.

## Failure cases and future tests

| Risk | Required proof before build is accepted |
|---|---|
| Stale approval or changed renderer | Exact-hash approval; changed source/template cannot deploy under old approval |
| Concurrent publication/retry | One active intent, optimistic conflict, fresh permission/revocation checks, same-key replay and conflicting-key refusal |
| Missing/replaced media | Hash mismatch/refused missing bytes; retired/foreign/consent-incomplete assets cannot publish; historical bytes stable |
| Partial build/upload/crash/timeout | No mixed live site; prior release remains until confirmed activation; unknown outcome reconciles without duplicate publish |
| Leaked credential or malicious target | No secret in output/log/audit/errors; allowlisted target; tested revoke/rotate and publish stop |
| XSS/private-data leakage | Escaping, bounded plain text, no executable fields; private sentinel references/paths and prohibited source tables absent from public bytes |
| Broken references/SEO | Both-language graph completeness, archive/inclusion failures, URL collisions, query detail behaviour, canonical/robots/sitemap and rendered-crawl parity |
| Unauthorised access | Every catalogue role's exact grants/denials, organisation/branch isolation, no implied clinical or staff access |
| Money/source mutation | Full ID-ordered before/after comparison of all unrelated financial, clinical, stock, staff and draft rows; only declared release/attempt/audit records differ |
| Bad rollback | Same retained hashes, fresh Director decision and withdrawn-asset refusal; no current-data rerender |
| Intake abuse | Separate W2 tests for limits, challenge failure, replay, encryption/purge, branch-scoped CA review and no automatic operational rows |

Existing assertions likely to change only when the relevant build is authorised: exact website permission catalogue/role permission sets, exact Clinic management destination map/frontend forbidden-family lists, route allowlists and boundary docs. Do not loosen staff-role assignment rules. Any additional assertion change must be reported with its old/new exact expectation and owner decision. No tests are written or modified by this analysis.

## Proposed slices and required authorisations

| Slice | Small scope / proposed tables | Permissions and owner authorisation needed |
|---|---|---|
| W1e-1 | Private freeze, graph validation, immutable release/media manifest, private preview and Director approval; release/items/media/actions/control tables; no output leaves KPOne | Explicitly authorise approval/releases and those tables. Reuse website view; proposed `website.releases.prepare.organisation` for Director/Marketing/Administrator, `website.releases.approve.organisation` for Director only. HR still edits only its existing types; other roles gain nothing |
| W1e-2 | Deterministic complete static artifact in private storage, public-projection tests and URL mapping; immutable artifact/URL-map evidence | Explicitly authorise static generation and reviewed public URL mapping. Existing prepare/approve authority; no outbound connector, public preview, import or live publication |
| W1e-3 | Pages deployment/observation/rollback; append-only attempts/outcomes and versioned publisher control | Explicitly authorise this outbound call, target project, token custodian and recovery rules. Proposed `website.releases.publish.organisation` and `website.releases.rollback.organisation`, Director only. Confirm provider atomicity/API/least-privilege scopes first |
| W1e-4 | Production cutover and old-backend retirement runbook, SEO parity, archive/restore and monitoring | Separate go-live, DNS/host, URL/redirect, legal/operator, backup/destruction and rollback approval. No new clinical access; no retirement while dependent features remain unresolved |
| W2 (separate) | Public intake + CA review; encrypted request/control/command evidence and purge design | Separate authorisation for public exposure, hosting, bot-provider call, privacy/retention and reviewed acceptance handoff; proposed `website.appointment_requests.review.branch` for CA/CA Supervisor only. Appointment records and messaging are not implied |
| W3 (separate) | Aggregate performance/Search Console reporting and any privacy-preserving collection | Separate analytics/GSC/outbound/credentials authorisation; proposed `website.performance.view.organisation` for Director/Marketing/Administrator. Old personal events not imported |

All proposed migrations are additive and retain existing evidence; permission creation uses missing-only grants and down never revokes. Director-only provisioning of roles carrying website permissions remains unchanged. No new access for Technical Admin, Finance, Panel Officer, CA or other roles through publication permissions.

## Decisions — recommended defaults

| Decision | Recommended default |
|---|---|
| Prepare / approve | Existing type owners edit; Director/Marketing/Administrator prepare full releases; Director alone approves and publishes |
| Approval meaning | Exact frozen manifest + renderer; no approval of future edits; medical/consent review remains a human clinic responsibility |
| Public data | Explicit public projection only; private consent/display/rights references and internal provenance stay private |
| Inclusion and translation | Explicit inclusion, complete EN and BM, active complete dependencies; block errors, no silent fallback |
| Shared reference prerequisite | Merge Job 1 first; publisher independently rejects legacy divergence |
| Storage and number | Private immutable release + retained bytes/hashes, separate WEB counter, no reused numbers or automatic purge |
| Host/deployment | Complete Pages artifact; platform secret store, Director-triggered connector only after provider/hosting verification |
| Atomicity | Audited intent before network; host-confirmed activation; uncertain outcomes reconciled, never treated as database rollback |
| Rollback | Exact prior approved bytes, fresh Director confirmation, refuse withdrawn assets |
| URL strategy | Preserve current paths/query behaviour initially; block cutover until rendered SEO parity passes. Pre-rendered new service paths/301s require a separate explicit choice |
| SEO/property | Same origin/property/verification; reviewed URL map and static replacement for dynamic sitemap |
| Data preservation | Encrypted external archives first; export available GSC history; archive-only old performance events, no personal-data import |
| Intake | Separate W2; narrow write ingress, CA review, minimal contact/preferences, no clinical text or automatic records |
| Intake retention/notifications | Proposed 30 days from receipt with clinic/legal approval; in-app list first, no email/message integration |
| Hosting and token custody | Owner names production host/project, operational secret custodian and incident/restore owner before W1e-3 |
| Cutover | Separate owner approval, tested rollback, 2–4 week SEO observation; owner sets baseline-based thresholds |
| Next build | Authorise W1e-1 only if these decisions are accepted; other slices remain separate approvals |

**Deferred and unauthorised:** implementation of the publishing/intake proposals above; site-settings code; public intake/feedback, analytics/GSC integration, automated schedules/hiding, message delivery, login/portal, imports, old-site code use, applicant/hiring workflows, new clinical/financial features and production deployment. The newer four-job owner instruction separately authorises private Little Shield drafts in Job 4; that authorisation does not permit publishing them and adds no implementation to this analysis branch. Only this analysis document is committed. Validation: documentation diff/whitespace and single-file scope check; no application gate or database was run for Job 2.
