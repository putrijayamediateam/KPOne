# Billing release relationships

- PR1141 feature/billing-demo-collections head8013a87483f273a1e18155fc7831c39a8029b6aa: initial collection/daily frame composition, independently reviewed, all six CI green at that head. Coordinator refreshes only on release turn.
- PR1160 feature/billing-collection-search head93d162dcac39cfa6f06f76880d26b70d2dd54bbc: five-file search/age read filter slice, draft stacked on1141. Local gates and independent reviews clear; attestation initiallyqueued. No merge.
- feature/billing-close-tabs: four-file month/terminal frame composition, based1141 (parallel sibling to1160); frozen3f939a35470bb2c68f55dd20cf8db1ff2da2ebe0f71f1af46e3e6f13d67e73b6, local gates clear, final independent visual review pending. No monetary scripts changed.
- PR1143 feature/clean-look-billing-stage1 head7545a98e0139dcf3b69fcdc8e66820dc0784d3d6: preserved historical restyle draft on oldbase23639679, not wholly superseded. Unique BillingWorkspace.vue changes include semantic warning surfaces, header/layout and36desktop/44mobile controls/inputs/selects; DD29 assertion adjustment already explicitly approved. Those component/test changes are absent from1141. CashClosings.vue overlaps:1141 replaces the old standalone shell with current composition/navigation/context. Extract/reconcile unique sizing without undoing1141; never blindly merge the old shell. Parent explicitly says preserve1143 and prioritize narrow extraction after current closing-tabs publication. No close/merge action authorised here.
- PR1112 BIL36 refreshed9aafd7553,79tests1108assertions +static/sourceparity clear; coordinator merged to83c5999bb.
- PR1113 BIL37 release and refreshed CI now owned by coordinator; Billing agent does not race it.

Closing-tabs measured legacy target debt: month Daily view32desktop/44mobile, desktop Open day18; terminal Dailycashclosing32/44, native paymentmethodselect40both, Recordclose36/44. New navigation38desktop/44mobile. Keep exact scope; composition does not certify all existing page controls or pink Open day styling.

Closing target debt update: coordinator required pre-publication local repairs. Final0c6c329e source now measures Daily/Open day36desktop, terminal selector40desktop, all corresponding mobile controls44; earlier18/32/40mobile values above are historical pre-fix evidence. Independent final review refresh pending. No shared primitive or financial script changes.

Latest publication checkpoint:
- Closing-tabs is published as draft PR1165, head114fd56decdbb4b709c6fa083464a5d1bd1df6d4, base1141. Final0c6c329e independently reviewed,14 final captures and target repairs complete. Root's last remote snapshot:3/5 checks with two backend jobs pending; no merge claimed.
- Unique1143 restyle is preserved in draft PR1170 https://github.com/putrijayamediateam/KPOne/pull/1170, branchfeature/billing-workspace-restyle-recovery, head16110416ba460f3a3e31fabb4e8f3bfbdf4a6200, base1141. Four-file351a0ea5 artifact preserves original approved BillingWorkspace/DD29 and reconciles CashClosings body with1141 shell. Actual publication full2206 and focused99 passed; separate fresh-main source overlay101 focused passed. Browser3127 is pure publication, not fresh-main. Original1143 remains open intentionally; no rerun of obsolete attestation.
- FinancialWork mobile cards are the next two-file isolated slice on main eec5d5444; current source/test candidate uncommitted. Final artifactfbd3deb52dc50fd26a1d92708d1e479f2b4c53e6519636ea81035a9058ed837b.2208 full frontend/type/build pass; final local pagination sizing build+6 focused pass. Final synthetic role/browser and independent review in progress, not ready claim yet.

FinancialWork final replaces the earlier in-progress checkpoint: draft PR1178 https://github.com/putrijayamediateam/KPOne/pull/1178, branch feature/billing-work-mobile-cards, head aa3613c29bb241153f22d5406c8f0865ca65e2bb, base main eec5d5444. Final two-file51aad957 includes desktop scroll guidance/keyboard discoverability closure; independently clear. Initial CI queued. No merge, production or financial policy changes.

PR1178 refreshed normally to039d28effb40ac96221c24f3649407a8711586b6 containing main d783621; initial attestation stale-base failure confirmed from exact job logs. Both files and main-relative51aad957 patch unchanged;6 focused pass. New CI pending.
PR1187 monthly filters: https://github.com/putrijayamediateam/KPOne/pull/1187, feature/billing-month-filters head5aa3b959147620ca29a5d28082b5a1c414b47a28, stacked1165. Two-file c13f7634, independent Design and Inventory visual clear,2212 full frontend/types/build and final21focused/build/browser26states pass. No financial/authority/schema change or old assertion edits. Draft, no merge.

- PR1192 (`feature/panel-price-review-context`, e1c2a05d20f37d9547e4facac2ab4615c9da6ae7) is a four-file draft from main d783621. Independent Panel proposal context composition; no dependency on unpublished Billing frame. Patch9a0883e2, final Design clear, 2208 frontend tests/types/build pass. No financial handlers/grants/migrations changed.

- PR1198 (`feature/panel-follow-up-context`,6f941096c861e724d73fbd26561c0a2ffd3193e8) is a two-file independent draft from main f8dd19cb. Patch30c911ec, no unpublished Billing-frame dependency. Complete existing script/eligibility/amount labels preserved; final independent review clear. Current-main14 focused tests pass; detailed prior full-suite/browser attribution in panel-follow-up-context/REVIEW.md.

- PR1203 (`feature/panel-workspace-navigation`,473c9ef9e243394e0a749228dd1f40e1da47031a) stacks only nine navigation/test files on PR1198. Patchd1cd0640 independently clear; preserves financial scripts and independent grants. Root owns attachment/release. No full six-tab demo or all-body-target certification; Remittances compact fill/clear/correction controls remain completion debt.

- #1208 dedicated Queried view:57c8737f1369ea507c96380e3d679bd0090c2b3b, branchfeature/panel-queried-view, stacked on1203. Six files, new exact-state read/tab; existing summaries/Submitted/Rejected and financial writes untouched. Independent reviews clear, local gates complete, newly published draft CI pending; root serial merge only.

- #1218 Waiting-payment read: c6fe05d46640d8c9514653727aaa225ad622cf3c, feature/panel-waiting-payment stacked1208. Exact7files, single-snapshot full matching balances/count and20-row page; no grant/migration/financial-write change. Peer-reviewed draft with local gates/browser complete; remote CI newly queued, root serial merge only.


Final publication: draft [#1227](https://github.com/putrijayamediateam/KPOne/pull/1227), branch `feature/panel-first-submission`, head `76330a1923c7534b43da5fd3de7f5b6f50720c1a`, base `feature/panel-waiting-payment` / PR1218. Remote confirms exactly seven changed files, open draft; worktree clean. Staff final hash/template/visual review and Inventory source review clear at frozen6f51c873. All gates and24actual browser states above passed. CI newly queued, not claimed green; root owns attachment and serial release. No merge.


Follow-up status published draft1232 https://github.com/putrijayamediateam/KPOne/pull/1232, branchfeature/panel-follow-up-status, headff3d942d540bf8df2f6b6603abf7191e0a74ee00, stacked1227. Final seven-file7c4b56977bcfda7f12a46ed3ee61e68a365a5f12e49553ccebece65428e9e52b; Staff/Inventory hash/source/design clear. Backend12/517, fullfrontend2228 before faithful copy/harness adapter; final10/lint/format/types/build/24actual browserstates PASS. Exact one old guidance regex adapter reviewed under delegation; financial/authority assertions preserved. Count buckets describe eligible branch/Panel beforestatus, no mixed money sums or new single-statement snapshot promise. Root owns attachment/merge. Preview3163 on isolatedcloneDB; originalpreview preserved.

- #1237 remittance workspace: feature/panel-remittance-workspace @88d21dfe232222ddf2f8ca4f44291b1c5f9a79c2 → #1232;3files, finalf41aba95, independentclear, localgatespass, remoteCIpending. No financial script/backend changes or merge.

- #1237 exact88d21dfe2 now all six remote CI checks PASS; no merge.
- #1243 price decisions: feature/panel-price-decision-filter @6809d19be3ef10b2ce3d6111b9ca36a5e274fbb5 → #1237;6files54a9584d, independentclear, localgates and32actualcaptures pass, remoteCIpending. No oldassertion, money, grant or migration changes.1192price-context remains separate releaseunion dependency.

- #1243 exact6809d19be3ef10b2ce3d6111b9ca36a5e274fbb5 now all six remote CI checks PASS. No merge.
