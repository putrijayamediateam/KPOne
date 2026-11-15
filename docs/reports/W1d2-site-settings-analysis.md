# W1d2 — Site settings and legal pages analysis

Analysis only, owner-authorised 2026-10-23. Branch `docs/w1d2-site-settings-analysis`, base `origin/main` b49a96d. This document is the only change. No code, migration, permission, route or test is added. No push, PR, merge, public route, outbound integration or old-site import.

## Recommendation

Use a small typed organisation-wide private settings draft for brand/footer text, public links, bounded SEO defaults and search wording. Reuse existing W1a public branch-profile revisions for addresses, contacts, maps and hours. Do not create a generic key/value bag or store scripts, tracking ids, verification codes, secrets or tokens in content. HR is view-only; Director, Marketing and Administrator manage general drafts through existing permissions. Every staff-authority/provisioning rule stays unchanged.

The old site has no central settings module or table in the inspected source: its defaults are spread over page HTML, shared scripts, branch records and deployment configuration. The inventory below is a source snapshot, not a live-account audit. Publishing, domain/SEO behaviour and legal approval need their own explicit authorisations. Keep KP Content OS product/Terms/Privacy separate from the clinic's own privacy notice and from its restricted service.

## Read-only evidence

Inspected `/Users/klinikputrijaya/MiniWeb-readonly` at clean commit `0fe19da` during the preceding analysis; its status and tip remain unchanged. References: its AGENTS/PROJECT/MEMORY/ENDGAME/ROADMAP and architecture/baseline audit; public page structure; `frontend/js/main.js`, `api.js`, `analytics.js`; production `frontend/admin.js` and admin tab inventory; backend route/schema/migration structures; robots/static sitemap/dynamic service sitemap; Search Console service. No old server/script ran, dependency was installed, link followed or database accessed. No real .env or credential file was opened. No campaign/legal wording, data, images or old code is copied here. Public contact/staff values are not reproduced.

KPOne references: AGENTS/CLAUDE, BASELINE section 6 items 13–14, W1a–W1d reports and website field/services/permission classes. The old clone does not establish current production host, DNS verification, embedded third-party settings or legal validity. Provider behaviour and legal requirements need later review; no outbound research was permitted.

## Source-visible settings inventory


This inventory covers the source-visible setting families and their locations, not unknown account-side values. Most are literals, not stored settings. No old value is a seed for KPOne.

| Existing family / location | What it controls | Proposed treatment |
|---|---|---|
| Shared brand/header/footer in main script | Logo, display name, short brand description, footer summary and ownership/copyright line | Safe bounded manual draft fields, plus sanitised media reference. Never infer legal operator from staff/business tax data |
| Main navigation and footer groups | Page order/labels, services menu, community/contact destinations and public calls to action | Keep the allowed destinations system-controlled. Draft labels/order only for existing approved page keys; publishing validates the frozen route map |
| Staff-login footer entry and admin/auth pages | Legacy website staff access | Retire from the public website. Do not add a KPOne login mechanism/link or copy the old signup/approval system |
| KP Content OS footer links | Separate product, terms and privacy destinations | Preserve as separately reviewed destinations in the future URL/release plan. Do not move the restricted service, its accounts or its legal-policy text into KPOne |
| Branch database records and repeated public cards/modal | Public branch names, addresses, phone/chat links | Reuse manually approved W1a branch-profile snapshots, not old DB values, live operational records or staff contacts |
| Branch/contact HTML and branch structured data | Opening times, map/direction links, location details, branch images, public contact presentation | Reuse W1a opening-hours/map/contact/image fields. Do not create a competing global fallback or silently broaden existing overnight-hours validation |
| Contact page and structured data | Public social destinations, maps and review/directory links | Typed public HTTPS links with bounded length and channel/host review, no embedded credentials. No arbitrary iframe/embed HTML |
| Page heads/home/branch structured data | Titles, descriptions, site name, canonical origin, indexing directives, structured clinic/location information | Bounded editorial default title/description may be drafts. Canonical origin, robots/sitemap rules, verification and structured-data layout belong to the publisher, generated from approved public fields |
| Public language/page labels | English HTML language declarations, some BM-facing text and navigation labels | New independent EN/BM revisions; no automatic translation. One frozen shared route/reference answer across languages |
| Icons and web manifest | Favicon variants, touch icon and application identity | Public identity/content may select an approved logo; output sizes/manifest generation remain publisher-controlled. No arbitrary manifest upload |
| Fonts, CSS and CDN includes | Public typography, layout and external asset dependencies | System-controlled implementation, not text fields for CSS/scripts. Prefer local bounded assets after separate publishing review; do not import old styles/code |
| Hero sections/home actions | Image, heading, summary and book/contact labels/destinations | Draft bounded text and existing media only; a destination is a reviewed page key, not a new form or endpoint |
| Service and doctor search controls | Placeholder, guidance, empty/error text; service category query selection | Allow a few bounded display strings if needed; keep filtering/error behaviour in code. No raw query expression or search analytics collection |
| Maintenance/404 pages | Static error/maintenance wording and indexing behaviour | Wording may later be a fixed renderer resource. No operational maintenance switch, redirect editor or publish trigger in private settings |
| Page/banner/notice copy | Page-specific hero information; no central banner scheduler found | At most an optional manual notice draft with an inert inclusion-intent flag; no dates/scheduler/automatic hiding in the first slice |
| Public API base/client fallbacks | Legacy backend origin and dynamic catalogue/contact/booking reads | Retire for content: future complete release supplies static data. Infrastructure URL is not editable website copy |
| Analytics script | Session/event keys, page paths, event collection and device/branch/service association | Not settings content. No tracking ids, pixels, script snippets, session identifiers or event store in W1d2. Separate W3 privacy/collection authorisation |
| Search Console service | Property identifier, read-only Google identity/key and query calls | Operational integration, not draft fields. No verification token or Google key in KPOne content. Separate reporting and DNS/verification ownership |
| Booking/email configuration | Notification recipients, provider/sender credentials and branch routing | Private operational configuration, never public-contact fields or website settings; separate intake/notification authorisation |
| Auth/server/database/upload configuration | Public signup flag, JWT settings, CORS/origins, frontend/backend locations, port, DB/TLS, persistent upload paths | Deployment secret/configuration boundary, no import or generic settings UI. KPOne keeps its existing auth/provisioning rules |

No general Google Analytics/pixel/verification-code editor was found in the inspected admin or schema. This is **not** proof that production has no injected tracking or DNS verification. Do not create a free-text tracking/HTML field merely because the owner asked for a settings inventory.

Observed technical environment-variable names (names only, no values read): `BACKEND_PUBLIC_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `NODE_ENV`, `PORT`, `DB_SSL`, `DB_SSL_CA`, `UPLOAD_DIR`, `RAILWAY_VOLUME_MOUNT_PATH`, `JWT_SECRET`, `JWT_EXPIRES_IN`, `PUBLIC_SIGNUP_ENABLED`, `BOOKING_NOTIFICATION_EMAILS`, `MAIL_FROM`, `RESEND_API_KEY`, `RESEND_FROM`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS`, `GOOGLE_SEARCH_CONSOLE_CLIENT_EMAIL`, `GOOGLE_SEARCH_CONSOLE_PRIVATE_KEY`, `GOOGLE_SEARCH_CONSOLE_SITE_URL`. Database credential variables are another operational family, not public settings. This source scan is not a complete production-secret inventory. Store future secrets in the chosen platform's secret manager, never in drafts, audit, repo or public release. Even non-secret tracking/property ids remain deployment/reporting configuration under the owner's boundary.

## Proposed typed fields and completeness


One `site_settings` item per organisation, not a generic name/value collection. Shared structural references are item-wide; translated prose stays independent. Do not expand existing W1a branch profiles, W1b people content or W1c pricing separation through this type.

| Proposed field | Budget / rule | Default |
|---|---|---|
| Public site display name / short tagline | 120 / 160 plain characters | Manual; no staff/business-table autofill |
| Footer summary / ownership statement | 600 / 300 | Manual, clinic-owner-reviewed before release; never infer legal operator |
| Default SEO title / description | 70 / 160 | Display defaults only; publisher generates canonical/indexing/structured data |
| Organisation logo / optional home hero | One active sanitised W1a asset each | Rights reference required as already implemented; no new SVG/script/HTML asset type |
| Home heading / summary | 150 / 600 | Manual editorial copy, not a template editor |
| Navigation/footer labels | At most 12 approved destination keys; label 60; unique keys and explicit order | Existing page-key allowlist only; no private/internal/admin destinations or arbitrary redirects |
| Social/public information links | Up to 5 typed channels; HTTPS URL 500; no credentials/control characters; approved host policy | Empty by default. Links are not authorisation to embed third-party scripts or forms |
| Service/doctor search placeholder / no-results text | 100 / 200 for each screen | Optional prose only; no search configuration/expression/query logging |
| Optional notice text and intent | 300 plus boolean false | No scheduling, expiry, live banner or maintenance control |
| Legal-review reference | 200, internal only | Required for completeness where ownership/legal link choices are used; no arbitrary legal-page editor |

Do not add a global branch phone/address/hours field: use exact approved W1a branch-profile revisions in the future release. Do not read clinician credentials, users, schedules, rosters, services/Pricing, invoices, inventory, patients or clinical rows. Public doctor text remains manual under W1b, including optional public registration text with its own display reference.

Settings completeness should require display name, footer summary, default SEO text, approved logo and valid allowlisted link references in both languages; optional notices/socials/heroes may be absent. No false claim that a complete draft is approved or public. Private references and revision provenance must be stripped by the eventual publisher's explicit public projection. Legal policy/consent notice changes, domain/canonical origin, robots/sitemap, verification, DNS, redirect rules, analytics ids and API tokens are not content-editor settings.


## Legal pages: keep boundaries explicit

The old footer links to KP Content OS product information, Terms of Service and Privacy Policy. Its PROJECT documentation names a separate corporate operator for that product. The source policy has EN/BM content. That does not establish the clinic's own data-controller/operator or make that product policy suitable for clinic appointment/feedback intake. The owner/legal adviser must confirm identities and obligations. Do not write new legal wording as part of this analysis, reuse policy text automatically or represent unreviewed wording as approved.

Recommended later content model, subject to separate authorisation: three narrowly allowlisted legal-information keys (`kp_content_os_product`, `kp_content_os_terms`, `kp_content_os_privacy`), with independent EN/BM append-only revisions. This is editorial content only, not the restricted TikTok service or its integrations. The owner may instead keep these pages on their separately owned static host and preserve reviewed footer links; that is the safest initial default until the product operator authorises management in KPOne.

If the operator explicitly approves drafting in KPOne, use bounded structured plain-text sections rather than HTML/templates: title 150; introduction 600; up to 30 ordered sections, each heading 150 and up to 8 paragraphs of 2000; optional effective date (strict YYYY-MM-DD); manual operator display name 200 and external legal-review reference 200. No signature upload, private legal correspondence, tracking identifiers or raw markup. Permit incomplete saving; completeness requires title, operator, effective date, at least one complete section and review reference in both languages. Completeness is not legal approval.

Editing may reuse `website.content.manage.organisation`; reading uses existing website view. A later approval slice should require a separate permission such as `website.legal.approve.organisation`, Director only, plus recorded review by the relevant operator/legal adviser. No grant is created now. HR, Marketing and Administrator cannot turn a draft into effective policy by editing it. No implicit access to patient, staff or restricted-product records. Director-only provisioning and canAssignRoles remain unchanged.

Approval binds exact revision hashes, language, operator, effective date and the frozen release. A wording change always appends a revision and needs fresh approval; do not silently replace a live policy or backdate approval. Retain prior issued policy versions so the clinic can establish which notice accompanied a future intake acknowledgement. Future intake should pin its own clinic notice revision, not a KP Content OS policy. Draft references/reviewer metadata stay private and never enter the public projection or audit body. Archive is not automatic deletion and cannot remove historical notice evidence.

Default before these authorisations: preserve three existing reviewed external/product page destinations in the future release map; do not build a legal editor, copy its text or import its operator/accounts. The clinic's privacy notice, consent text and retention decisions are a separate W2/legal task. No legal text is drafted in this report.

## Access, safety and proposed tests

No new permission for settings drafts. Existing `website.content.view.organisation`: Director, Marketing, HR Manager, Administrator. Existing `website.content.manage.organisation`: Director, Marketing, Administrator. HR view-only. Existing asset permission allows library use but is not permission to edit settings. Technical Admin, Finance, Panel Officer, CA/CA Supervisor, Resident Doctor and every other role gain nothing. Director-only provisioning remains unchanged.

Use the existing fresh permission/organisation/effective-branch check, transaction, consistent locks, independent lock_version, UUID retry/payload-conflict refusal, append-only EN/BM revisions, coordinated structural references, values-free audit and rollback on audit failure. Saved-only private preview, branch-switch clearing, no operational/staff/credential/patient/clinical/billing/stock read, no public route/network/job. No seeding/import.

Future tests: exact grants/denials/read-only writes for every role; revocation/isolation; all field/list limits and plain-text/control/bidi/zero-width rejection; URL schemes/credentials/destination allowlists; media consent/rights/retirement; stale/retry/conflict; independent languages and shared references; immutable revisions and stable singleton/slug; audit rollback and values-free metadata; query-log prohibited-read proof and full unrelated-table row comparison; branch switch and 1440/390 light/dark no-overflow; no executable settings/secrets/tracking/public output. Legal tests additionally pin exact approval hashes, stale approval, version/effective-date history and separation from clinic notices. No tests are written now.

## Proposed slices and expected assertion changes

Settings private drafts are one later PR; operator-authorised legal-page drafts would be a separate PR; Director approval/publication belongs to W1e. Settings would add an allowlisted singleton type/partial unique index while retaining existing history and guards. No migration is written now. Likely assertion changes are exact hub/navigation maps and the still-unauthorised type fixture, only after those types are explicitly approved. Existing permission lists and staff-authority assertions should remain unchanged for settings; any later legal approval grant requires a separately reported exact-set change. Never weaken a denial to make a test pass.

## Owner decisions — defaults

| Decision | Recommended default |
|---|---|
| Settings structure | Typed organisation singleton, independent EN/BM prose, coordinated structural references |
| Contacts/hours/maps | Reuse approved W1a saved revisions; no duplicated global fallback or operational lookup |
| Who edits | Existing Director/Marketing/Administrator manage; HR views only; no new grants |
| Tracking/verification/secrets | Not content. Deployment/reporting secret configuration only, with separate authorisation |
| Headers/footer/search/notice | Bounded manual wording and existing media/page keys; no HTML, live behaviour or scheduling |
| Canonical URLs/robots/sitemap/redirects | W1e release/deployment design; no editor controls in this slice |
| Legal pages initially | Keep separately owned/reviewed product destinations; do not import text or build an editor yet |
| Future legal drafts | Only after product operator authorises it; allowlisted types, append-only EN/BM revisions, bounded sections |
| Legal approval | Director-only application authority plus external operator/legal review, exact revision hashes; distinct from completeness |
| Clinic privacy notice | Separate clinic-operator/PDPA review before any intake; product Privacy Policy is not a substitute |
| Re-entry | Manual new content, no old code/data/text/images/credentials import |
| Next build | Separate explicit settings or legal-drafts authorisation; this report authorises neither |

Still unauthorised: settings/legal-page implementation, publishing/releases/approval code, public routes/read APIs, Cloudflare calls, redirects, intake/parent forms, feedback, analytics/GSC integration, tracking, old-site imports/use and any clinical/financial/staff workflow. Document-only validation: git diff --check and single-file scope. No application gate or database used for Job 3.
