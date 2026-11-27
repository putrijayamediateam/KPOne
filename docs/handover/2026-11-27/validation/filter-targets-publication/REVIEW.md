# Queue/Registration target sizing — publication candidate

Branch fix/clean-look-filter-targets, tree /workspace/KPOne-filter-targets-publication, base mainbd9a42331d72cf1d2b54ad72b5671e8913d0a483. No commit/push; owner reviews this exact final main-relative candidate first. Previous full CA validation tree renamed fix/clean-look-filter-targets-validation; its whole diff is not a publication source.

## Exact scope
FILTER-TARGETS-publication.patch SHA2564f80418aaad5c5ae87d112fd4773cfc9d8ced6f1f68ff68a0a702b130fb1d672 changes two existing page templates only. Queue room picker/search/Doctor/Urgency controls retain44px minimum below1024; Queue Apply/Reset and Registration Apply/More meet36px desktop and44px mobile/tablet. Clean Look spec section4 Controls defines these minima. Both page scripts remain byte-identical to mainbd9; no payload, permission, route, feature, test assertion or dependency changes.

Original integrated overlay SHA80868bb20c92be443ac17189a634adb9a0fc01e58a6768a1c3ad0296b5086f7b applies cleanly to main. Normalized additions/removals match exactly between overlay and publication patch; only file index/hunk context metadata differs. manifest.json records exact candidate hashes.

Registration PR1132 remains separate. A three-way merge-file simulation of its final Index at ae2ff98c8 with mainbd9 and this candidate completed without conflict, preserving both restyle and target classes (merged-registration-index.vue is evidence only, not source). Do not bundle that PR into this two-file change. Future actual main changes still require fresh ancestry/parity review before publication.

## Gates and review
-216 existing tests across31 direct page consumer files PASS on this main-only candidate. No existing test changes.
- Two-page ESLint/Prettier and diffcheck PASS; both scripts exactmain.
- Full types/build queued after Billing, before Inventory; result appended below when finished.
- Original integrated live proof:30 responsive/theme checks, exact44px390/768 and36px1440, focus/nooverflow/noJSerrors; source /workspace/kpone-validation/filter-targets/REVIEW.md. Not a new main-only browser run; identical class operations verified.
- Independent Dispensary reviewer inspected exact overlay and Queue390dark/Registration1440light screenshots, found no blocker: focus visible, enlarged fields/actions aligned, no handler/permission/assertion changes. Runtime/test totals remain builder evidence, not claimed as independent reruns.

Risk: overlaps the Index template touched by Registration PR1132, but explicit merge simulation is clean. Local control classes avoid widening shared components. No full backend run for this class-only diff; eventual exact-head CI remains publication gate.

Proposed commit: fix(ui): meet Queue and Registration filter target sizes
PR title: Fix Queue and Registration filter control sizes
PR body: Queue filter fields and room selection were36px on mobile, while filter action buttons were32px on desktop. Set affected local controls to44px below1024 and36px desktop, retaining existing handlers and permissions. Two template files only; both scripts match main. Validation:216 focused tests, lint/format/diff, exact overlay parity and prior30-state measured browser proof. Append candidate type/build results before publication. Existing Registration restyle remains a separate PR; clean three-way overlap check preserves both.

Final candidate gates: isolated full TypeScript and production build PASS, sequential chain exit0. Source hashes remained unchanged and candidate diff still exactly two pages. Heavy slot released to Inventory. All required local candidate checks complete; ready for owner review of exact main-relative patch. No commit/push or new assertion edit.

## Owner-approved publication
Owner approved this exact two-file packet before publication. Fresh remote main remained bd9a42331d72cf1d2b54ad72b5671e8913d0a483. Normal commit/push produced head 5d178658236bb89a4d61b68c0092b28fae802ce8 and PR https://github.com/putrijayamediateam/KPOne/pull/1138. Committed main-relative patch SHA remains exactly 4f80418aaad5c5ae87d112fd4773cfc9d8ced6f1f68ff68a0a702b130fb1d672; working tree clean. Initial CI: attest pending (run45767628033); no CI pass claimed. No merge performed. Parent coordinator handles artifact attachment.
