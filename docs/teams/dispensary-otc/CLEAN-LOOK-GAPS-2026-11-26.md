# Dispensary Clean Look gap reconstruction — 26 Nov 2026

Evidence: uploaded `KPOne Clean Look Demo.zip`, especially `project/Pharmacy.dc.html`, `PharmacyCase.dc.html`, `PharmacyCompleted.dc.html` and `ReturnsToCheck.dc.html`; main `23639679e`; DSP-28 `51d316cf`. The original B4/B6–B10 wording was not recovered. The labels below describe observed gaps and **do not claim to recover the original numbering**. Domain/design review is still required before building missing behavior.

| Demo capability | Repository evidence | Continuation |
|---|---|---|
| To prepare, Waiting for doctor's check, Done today, case links and label action | `resources/js/pages/Dispensary/Index.vue`, DSP-27 on main | B1/B2 delivered; restyle remains |
| Returns checked this month | DSP-28 branch changes ReturnsToCheck and return service | B3 recovered, pending validation/review |
| Controlled return filter | DSP-28 branch | B5 recovered, pending validation/review |
| Check return opens case | Main ReturnsToCheck uses `caseUrl#medicine-returns` | Preserve this read-only list boundary; do not copy demo inline mutation form |
| Preparation filters: consultation/OTC type and doctor | Demo Pharmacy `prep.filters`; main list currently exposes tab/search/page | Candidate missing filter work; confirm role visibility and backend query before implementation |
| Doctor-check filter: Mine / All doctors | Demo Pharmacy `docchk.filters` | Candidate gap; requires existing own/branch visibility to be respected, never a frontend-only role filter |
| Return waiting-age filter | Demo Pharmacy returns has 3 days / 2 days / Yesterday / Today | Candidate gap; define branch-local day boundaries before implementation |
| Return history outcome filter | Demo Pharmacy `retdone.filters` | Confirmed absent: DSP-28 filters only `view` and `controlled`; outcomes are displayed, not filtered |
| Expanded patient details on list rows | Demo Pharmacy row `more` includes clinical details | Do not copy without domain privacy review; use existing case-page permissions and disclosures |
| Call, recall, labels and return-to-doctor shortcuts | Demo Pharmacy row extra actions and PharmacyCase; main case owns these actions | Preserve existing case workflow; list shortcuts must use existing permissioned actions |

## Explicit exclusions from recorded owner decisions

- No Ready to hand over tab. Doctor Verify completes the case.
- Doctor checking remains on Consultation; no approvals-inbox changes.
- No Follow-up link from the demo completed page.
- The returns list is read only; the actual check stays on the case page.
- No demo role switcher, fake counts, proposal badges, or fabricated clinical fields.
- Real patients remain outside this continuation; all local examples remain synthetic.

## Needed before an implementation PR

Map the remaining candidate gaps to the team's original B4/B6–B10 labels only if new evidence is supplied. Otherwise use new descriptive slice names and obtain precise domain/design scope. Verify all existing return invariants: second person checks; controlled medicines cannot be quarantined; quarantine never returns to usable stock; recording a medicine return does not itself issue a refund.

## Scoped continuation after DSP-28

These are proposed descriptive slices, not replacements for the missing original B numbers. Evidence was checked against the uploaded demo configuration and the recovered source on 26 Nov 2026. The demo is a visual reference; its fake roles, data and click handlers are not backend requirements.

1. **Preparation type and doctor filters.** Demo `Pharmacy.dc.html` / `C0.tabs[prep].filters` names Type (All, Consultation A, Medicine only B) and Doctor (All, named doctors, OTC counter). `DispensaryListService::page` currently accepts only actor/tab/page/search, although rows already expose `caseType` and `doctorName`. Proposed scope: server-side type and attending-doctor filters within the existing active branch and read gate, options from permitted branch staff, reset page on change and preserve tab/search. Resolve whether OTC counter is a distinct type option or an unassigned-doctor option before implementation. No new patient fields or role grants.
2. **Waiting-for-doctor ownership filter.** Demo `docchk.filters` offers Mine / All doctors. Current service scopes cases to active organisation/branch and status, with no actor-doctor filter. Proposed scope: explicitly filter the assigned doctor using the authenticated actor for Mine; keep the existing read-only Doctor role and perform Verify only in Consultation. The coordinator must settle default and which staff see Mine; the demo's role switcher is not authority.
3. **Return waiting-age filter.** Demo `returns.filters` offers All / 3 days / 2 days / Yesterday / Today. DSP-28 uses recorded time only for ordering and has no age filter. Proposed scope: filter waiting returns by branch-local calendar day using UTC half-open bounds, preserve controlled selection and case-page check link. Resolve whether “3 days” means exactly three days ago or three days and older; define handling of older returns so they cannot disappear from the default All view.
4. **Checked return outcome filter.** Demo `retdone.filters` offers All / Quarantined / Disposed / Refused. DSP-28 already emits `outcome` and confines checked rows to the current branch-local month, but does not accept an outcome filter. Proposed scope: allowlist existing stored outcomes, apply only to checked view, combine with controlled filter and reset pagination. Keep refusal details out of new free-text fields; no new return mutation. Confirm whether counts reflect the selected outcome or the whole tab before building.

Validation for each filter slice: new request coverage for valid and invalid values, cross-branch isolation, empty results and pagination; temporal filters also cover timezone/month/day boundaries. Preserve all existing assertions unless owner approval explicitly names a needed change. Domain/design review remains pending; no new filter behavior above was implemented in this recovery.

## Recovery evidence and integration boundary

DSP-28 recovered from `origin/review-dsp28` (`51d316cf`) onto `23639679e`. Its PharmacySpace change only uses `waitingTotal()` for the returns count. The coordinator separately owns correcting both home shortcuts to `/dispensary`; apply both changes during integration. This recovery does not change staff permissions, return checking, stock movements, refund logic or the doctor Verify transition.
