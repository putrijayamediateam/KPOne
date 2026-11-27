# Stage0 shared surface tokens — isolated review packet

Candidate worktree: /workspace/KPOne-surface-tokens, base published shell commit 5d81b5060. Preparation only: no commit, push, PR creation or assertion edits. Source is exactly three files; no dependency/lockfile, PHP, route, migration, permission or behavior changes.

## Plan, files and specification

1. resources/css/app.css maps radius-xl to the existing14px radius token and supplies the existing card shadow to legacy rounded-xl/border/bg-card surfaces. Nested card surfaces suppress that default shadow.
2. resources/js/components/ui/sheet/SheetContent.vue uses the popover surface/foreground and popup shadow tokens.
3. resources/js/components/ui/sheet/SheetOverlay.vue uses the specified18% light /50% dark backdrop instead of80% in both themes.

Design reference: /workspace/KPOne/docs/design/CLEAN-LOOK-SPEC.md sections2,4,5: radius14px; soft light card shadow/no dark card shadow; no nested-card shadow; sheet shares popup surface/shadow; glass-popup backdrop18%/50%. The reconstructed spec is not present in this isolated published-shell checkout; this packet references the existing coordinator-held document and recorded approval, without adding another spec revision or claiming new historical Daniel signoff.

This is the already reviewed surface follow-up split from the larger Stage0 implementation. It does not claim to implement every sheet requirement (such as consumer-specific phone full-screen layout) or repair unrelated shared-control touch sizes.

## Exact parity and reused evidence

Original approved-review artifact: /workspace/kpone-review-bundles/STAGE0-card-sheet-tokens.patch, SHA256: 69beee55fe4aba4ed57f7765b73d50f25f5a3dd737756930cc944a9bd95972d5.

Current exported diff: surface-tokens.patch, SHA256: cbd347040a63874dbc8a568056a1637c179f4883ef7a9befae93caff0fafac7c. Raw diff bytes differ only in Git blob abbreviation length and hunk context labels. After removing only that metadata, patch operations and context are identical. Reverse apply-check of the original patch against this candidate passes. All three resulting source files are byte-identical to /workspace/KPOne, the source used for prior visual evidence; SHA256s and proof are in source-parity.json.

Reuse prior root evidence instead of repeating browser work: registration-before/after-1440-light.png, billing-before/after-1440-light.png, registration-after-1440-dark.png, registration-after-390-light.png, shell-after-1440-light.png, staff-design-evidence.log and independent staff-design-review.md under /workspace/kpone-validation. The prior browser report states correct Registration/Billing destinations and no mobile overflow; independent shell review covers keyboard/mobile/theme behavior. This is reused exact-source evidence, not a new browser run or a claim every page consumer has been visually audited.

## Gates

- PASS60 scoped existing frontend tests, no failures/skips: clean-look-tokens, clean-look-shell, dsp-26-doctor-view, cat-2-price-list-check, classic-only-co6-kept-v2-inventory-requests/orders, hr-15g-locum-pay, classic-only-co10-kept-returns-to-check, cat-3-price-history, dd-98-panel-invoices-phone. Run with node --test and the ten listed test paths under tests/Frontend; full result in focused-tests.log.
- PASS git diff --check and original-patch reverse apply-check.
- Prettier check reports all matched files compliant. The generated UI sheet directory follows repository ignore policy; ESLint explicitly reports both sheet files ignored. Do not misrepresent ignored primitives as linted or reformat them to widen this patch.
- Verified git diff --name-only is exactly the three implementation files; no existing test/assertion modification.
- Full frontend/type/build on this isolated candidate NOT run yet: coordinator's full integration frontend run currently owns the heavy slot. Prior Stage0 source proof exists, but it is not represented as a newly run exact-candidate gate. No extra backend suite or migration run is justified for this CSS/class-only diff.

## Self-review and risk

CSS uses low-specificity :where selectors in the components layer, so existing utility-level shadow overrides remain authoritative. Nested background/card selectors suppress the introduced default, preserving hierarchy; dark shadow-card is already none. Radius-xl mapping is intentionally global and will affect all existing consumers of that token, not only a single page. Shared sheets retain their existing Reka props/emits/slots/portal/focus/transition/position logic; only surface classes change. cn still permits caller overrides.

Residual risk is visual across shared consumers: explicit shadow utilities can intentionally override nested defaults; nested surfaces under an outer bg-card are treated as embedded. No query, mutation, actor data, audit, branch isolation or permission behavior changes. No new third-party resources or sensitive information. Larger domain restyle slices still require their own combined evidence after reconciliation.

## Proposed commit

fix(clean-look): align legacy card and sheet surfaces with shared tokens

## Proposed PR description

Align legacy card corners/shadows and side-sheet surfaces with the approved Clean Look tokens. Cards use the14px radius and soft light shadow, embedded cards avoid duplicate shadows, and sheets use the popup surface/shadow with18% light and50% dark backdrops.

Validation:60 existing scoped frontend tests pass; diff and original-patch parity checks pass; prior exact-source light/dark/mobile visual evidence reused. Record the scheduled isolated type/build and full integration result before publication. No assertion, permission, dependency or migration changes.

Publication requires a new owner approval for this separate three-file diff; the earlier shell publication authorization is not reused here.

## Dependency isolation and integration evidence

Vendor and generated routes/actions/wayfinder are real local copies from /workspace/KPOne-shell1125 at the identical published-shell base. composer.lock/package-lock.json match; no generated directory symlinks and no environment files accessed. node_modules alone reuses the existing package installation. Provenance: dependencies.json.

Coordinator integration evidence includes /tmp/kpone-ca-integrated-frontend-final.log (2235/2235 frontend pass, no skips) and /tmp/kpone-ca-final-types.log (coordinator-reported types pass). Independently verified all three token-file SHA256s match /workspace/kpone-validation/ca-integration/validated-source-sha256.json. These are combined integration results, not a claim that a full suite was rerun inside this isolated tree. Backend code is unchanged from published shell and its recorded full green gate.

Isolated vue-tsc and production build are now scheduled sequentially using copied dependencies. Build uses explicit testing configuration and coordinator-authorized task database kpone_shell_seed_verified_test for route/bootstrap context only; no database writes or HTTP interaction are part of this validation. Final status follows below.

## Final isolated gates — publication review ready

Isolated npm run types:check PASS and npm run build PASS (combined sequential command exit0), logs types.log/build.log. Wayfinder generation completed against local copied dependencies; generated directories remain real local directories. Explicit task testing/PG configuration was used; no .env or runtime HTTP checks. Final git diff --check PASS, exactly three source files modified, all reviewed hashes/patch SHA unchanged. No assertions or dependencies changed.

The pending isolated gate notes above are superseded by this final result. This candidate is ready for coordinator/owner publication review with60 scoped existing tests, byte-identical prior visual evidence and2235 passing combined frontend tests covering these same modules. No commit, push or PR was created. New publication approval and normal CI/review still apply.
