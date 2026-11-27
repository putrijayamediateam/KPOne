# Patient Emergency Contact and Family Links — publication scope plan

Planning only,26 Nov2026. No new branch, source edit, commit, push or PR in this task. Coordinator priority remains completing CA integration. Current remote-tracking main at inspection is `c938a9724` (Q16c); Registration PR1132 remains OPEN at `ae2ff98c8880220bf6f83cdf56b23d468b71e94c`, so its presentation is not yet main. Refresh ancestry immediately before extracting candidates.

## Proposed sequence

1. Finish current CA Unit/Feature parallel4 and serial Contention gates; retain failure/repair evidence separately rather than labelling the old serial run green.
2. Land/review Registration1132 through coordinator's existing publication flow. No merger action is authorised to this team by this plan.
3. Prepare a new `feature/patient-emergency-contact-publication` branch from then-current main containing only the approved Emergency Contact feature. Review and obtain explicit publication authorization before commit/push/PR.
4. Prepare a separate `feature/patient-family-links-publication` from fresh main. Family has no schema or permission dependency on Emergency Contact, but publishing after Emergency Contact simplifies the shared Patient page reconciliation. If prepared in parallel, keep it independently based on main and rebase/reconcile normally when earlier PRs land; never include Emergency feature commits in Family's reviewed diff inadvertently.

These are functional feature candidates, separate from the already approved pure presentation PRs. No new product/migration/grant decision is needed if the already-approved exact scope/schema remains unchanged. Any genuinely new assertion expectation or changed schema returns as a concrete batch.

## Emergency Contact candidate

Original18-file standalone feature patch: `/workspace/kpone-validation/emergency-contact/standalone-feature.patch`, SHA256 `5d6ea8033a2c291ecf88cc6eafbf67c40d4de134559e4fadb222924734d7128b`. Original base23639679e. Runtime/new backend/card/migration/race files still match current CA integration byte-for-byte. Current frontend feature test has stylistic formatting/explicit-brace differences; preserve its tested copy only after normalization/assertion parity proof. This standalone patch is feature-only; **do not use the27-file Registration+Emergency combined overlay as a publication diff**, because it carries Registration presentation/assertion changes already belonging to1132.

Approved scope: optional patient-level encrypted contact; supplying any value requires name/relationship/phone; no visit snapshot; Registration+Patient details through existing view/update authorities; explicit remove/tombstone/version; staged registration change commits atomically; every registration replay path binds target+mode and preserves current-authorized-state semantics. Guardian/HR/search/board/TV/public remain separate. No role grant.

Exact migration `2026_11_26_010000_create_patient_emergency_contacts.php` creates the two approved tables with composite patient/org integrity and append-only command history, non-destructive down. Its current CA bytes match the reviewed feature. Approval record is in feature TEAM and the draft's final “Exact schema approval” section; earlier pending text is historical and superseded. Before publication, clarify the document's leading status while retaining exact approved migration and review provenance.

Reconciliation boundaries:
- Replay only feature changes into freshly merged Registration/Create.vue; preserve1132 cards,44px controls, visible heading/primary action arrangement and any current main query-tab behavior elsewhere.
- Preserve main Patient/Show.vue layout and add only EmergencyContactCard props/import/template; no Family card unless already main.
- Surgical additions in VisitRegistrationService, StoreVisitRequest and Patient/Registration controllers; preserve all current main visit/queue/idempotency behavior.
- Add only its controller routes/imports and contention registrations (Feature exclusion, Contention suite and one shard), keeping every current main entry.
- No TRI2 emergency alerts/lane/presence, Treatment records, Family, pricing or additional appearance assertion edits.

Existing evidence: standalone emergency13/49; actual first-create/replay races plus suite4/29; combined Patient/Visit35/194; integrated browser atomic staging/save/clear and uncertain-response retry; independent backend and UI reviews. Fresh candidate must rerun targeted patient/visit/guardian replay tests, actual two race cases and suite registration, feature frontend SSR/interaction checks, types/build/lint/Pint/PHPStan, exact migration/seed on its own test database and bounded Registration+Patient light/dark smoke. Source/script/approved-schema parity before new gates prevents accidental inclusion of later CA changes.

## Family Links candidate

Original20-file feature patch: `/workspace/kpone-validation/family-links/family-feature.patch`, SHA256 `fae0131c8adb368809661ed70795c14bb9941c1bb34e9a95ca3a733127bd9418`. All13new feature files match current CA integration except stylistic formatting/explicit-brace frontend test changes. Preserve the tested copy after normalization/assertion parity proof.

Approved F1–F3: direct five-label single pair; explicit inverse direction; no phone suggestions/transitive group or guardian authority; both endpoint patient policies; sorted patient locks, actor-bound HMAC/version retries; original links suppressed on merge with no transfer and restored on unmerge unless unlinked. Consultation shows only authorized direct relatives waiting today and Patient links, no conditions, contacts/billing or hidden counts. No permission grant. Exact independent two-table migration `2026_11_26_020000_create_patient_family_links.php` is already approved and byte-matches CA.

Reconciliation boundaries:
- Patient/Show.vue only FamilyLinksCard import/template; retain Emergency card if already main and existing patient masking.
- Clinical/Show.vue only FamilyWaitingCard import/template, preserving current Consultation restyle, Q16b and any later main shared alert work. Do not copy the CA page wholesale: it contains unmerged Treatment/TRI2 features.
- ClinicalEncounterDirectoryService and clinical.ts add only the nullable Family projection; own-doctor/branch QueueEntryPolicy and both patient-view policies remain authoritative.
- Preserve exact no-clinical-data allowlist and relative→current relationship wording in waiting card; directory remains current→relative.
- Routes, phpunit and TEAM reconcile minimally, preserving earlier Emergency/current main additions. Full original scope file list is in original-scopes.json.

Existing evidence: core11/52, projection3/10, broader48/253, five actual races5/32, independent backend review; frontend51focused, types/build,28browser states+4error views/keyboard targets. Fresh candidate repeats meaningful focused core/projection/merge/hold checks, five races+suite registration, frontend tests/type/build/lint and migrated isolated synthetic preview. Confirm current-main Patient+Consultation layouts in bounded light/dark smoke. No existing assertion was changed in the original feature.

## Publication gate and remaining limitations

Both features are implemented and independently reviewed in CA, but **neither original23639679e-based worktree is a ready current-main publication branch**. The new candidate diffs and their source manifests must be reviewed before owner publication approval. Current CA aggregate is the broad integration gate, not a substitute for exact feature extraction checks. Existing screenshots are carried evidence until candidate-specific smoke is captured. No production data or activation is authorised by this plan.
