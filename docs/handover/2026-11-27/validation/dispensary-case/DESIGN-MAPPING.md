# Dispensary case Clean Look — bounded Stage1 plan

Prepared against /workspace/KPOne-ca-integration/resources/js/pages/Dispensary/Show.vue on26November. Existing source/preview is owned by the coordinator and remains untouched. Dispensary agent confirms no current Show source edits; it is completing the Puchong clinical flow independently. No implementation or historical assertion edit yet.

## Design basis and scope

Read actual uploaded demo project/PharmacyCase.dc.html and Clean Look spec sections2,4,5,8 and Stage1 mapping. Demo has patient/allergy context left, editable medicines/services middle, summary/actions right, quiet cards and14px corners, readable sentence-case labels, blue primary and semantic warnings. Preserve current server-driven workflow, field list, saved/unsaved comparisons, scan/batch behavior, existing doctor verification, permission/view-only guards and main wording. Do not copy demo data, its remote font, fixed-height scrolling, alternate shell, generic completion transition or hidden-by-CSS authorization.

One existing page, existing shared PageHeader/StatusBadge/OperationalSelect/Button/ScanInput/DoctorCheckActions and current theme tokens. No new CSS theme/wrapper/switch. Template class changes only, plus two presentation-only class constants if approved; all clinical script logic remains byte-identical. No feature, service, route, request payload, label-print, billing, stock or permission changes.

## Exact source mapping

| Current source lines | Planned in-place treatment | Preserved behavior |
|---|---|---|
|1297 root frame;1301–1351 headers|Keep existing PageHeader title/descriptions, test-pinned header classes and conditional actions. Existing shared header supplies26px hierarchy. Root typography14px,24px section rhythm; preserve all max-lg44px selectors, add desktop36px minima for local size-sm controls.|One patient-name location; read-only/print permissions and routes unchanged.|
|1452 outer columns|Recommend three columns from xl rather than lg, retaining280px left/300px right and minmax0 centre.1024–1279 stays stacked so dense dose/batch controls are usable;1440 retains existing three-column hierarchy. Requires precise ds-01d assertion approval below.|Same DOM and reading order: patient/allergy, editors, summary/actions.|
|1455 patient;1486/1623 allergy panels|Patient card uses existing bg-card/radius/shadow tokens, sentence-case13px label and16px name. Confirmed allergy→border-success/25 bg-success-wash; unconfirmed→border-warning/25 bg-warning-wash; Recorded text→text-success. Keep all safety wording/statuses.|OTC confirmation, allergy override, return-to-doctor decisions and risk text unchanged.|
|1374 waiting;1398 handover;1410 sent-back|Quiet waiting surface, warning-wash for handover and sent-back. Keep icons and role=status, no colour-only meaning.|No altered handover instruction, sent-back reason, supervisor attribution or edit lock.|
|1672 error;1681 unsaved|Error→destructive/10 surface and destructive text/border; unsaved→warning-wash. Wrap Save all at narrow widths without obscuring text.|Existing error live region and saved-line handling unchanged.|
|1715 medicine container;1719 rows;1833 fields;2180 add medicine;2520 services|bg-card with14px radius and light shadow; rows use quiet dividers and16px padding, no nested shadows. Field labels13px, values14px, metadata12–13px; retain tablet2-column layout, widen to4 only when sufficient. Keep every field, selected-batch description and disabled held-batch behavior.|Doctor order beside prepared values, all hidden/read-only controls, scan hints and unsaved tracking unchanged.|
|1281 inputClass;1283 batchHintClass;1962/2414 hint text|inputClass presentation only: current h9 plus field-fill/input border/foreground/focus-ring tokens. Scan hint brand→primary/ring tokens; hint text→primary. No function/computed/watcher edits.|Scan focus/live region and non-error distinction preserved.|
|2127 removed;2521/2649/2682 headings|Sentence-case medium15px section headings; muted removed rows remain struck through with restore action. Semantic success text for performed services, warning text for existing caution copy.|No removals/restores or performed/not-performed state changes.|
|2770 summary;2801 actions;2820 room select|Keep shared card classes (including DSP26 exact action class) and replace uppercase header typography. Room trigger min36desktop/max-lg44, width bounded to card. Existing permission-conditional action order retained.|Call patient, start, return, complete/check reasons unchanged.|
|2890 confirmation panel|Pink→border-info/25 bg-info-wash; remain inline, unchanged confirmation text/buttons and conditional check-vs-complete operation. Wrap actions when needed.|No modal conversion, no extra confirmation, no clinical transition change.|
|2928 supervisor review|Shared card/heading styling only; shared DoctorCheckActions remains untouched.|Two-person check controls and prepared-by-you refusal preserved.|

Line references are from the inspected integration snapshot and will be regenerated after isolated-tree base selection. If a shared child component itself needs correction, flag it separately before expanding source ownership.

## Existing assertion impact — submit as one exact batch

1. **dd-57-dispensary-dark.test.mjs**, test “the warnings keep their light-mode colours”: replace only three Show expectations (amber allergy surface, red action error, pink confirmation) with the mapped semantic token classes. Keep the separate DispensaryAttentionPanel expectation unchanged. Preserve both per-line raw-palette/dark checks; semantic tokens already adapt through theme definitions.
2. **dark-1.test.mjs**, test DARK-1 S1-007 only: confirmed OTC expected classes→border-success/25 bg-success-wash; unconfirmed→border-warning/25 bg-warning-wash; Recorded text→text-success. Keep all other DARK-1 groups and both true/false state assertions unchanged.
3. **dsp-17-scan-pick-batch.test.mjs**, “the hint is a brand ring…”: update expected presentation constant to primary/ring tokens and rename title to blue scan hint. Preserve explicit no-destructive check, two polite hints, batch-pick/refocus behavior and all other assertions.
4. **ds-01d-dispensary-layout.test.mjs**, first layout test only: retain Invoices' existing lg three-column expectation; use xl three-column expectation for Dispensary, documenting1024–1279 stacked readability. Keep widths, column IDs and all position/behavior assertions. This is the only proposed layout-breakpoint change.

No need to edit DSP26 exact headers/action guard, DD25 title/name, DD27 shared StatusBadge tone, DSP8 mobile/tablet controls, or clinical/scan/batch/stock assertions. Do not weaken any of those as collateral. If focused tests reveal another genuine expectation change, report a supplemental exact group before editing.

## Responsive and accessibility validation

390/768: all control targets44px, labels wrap, selected batch/help visible, left→main→right reading order, confirmation text/action rows stay within viewport.1024: proposed stacked layout avoids squeezing four dose columns between sidebars.1440: three columns with bounded centre width. Light/dark states: preparing, confirmation, saved/unsaved error, OTC allergy confirmed/unconfirmed, waiting/sent-back, doctor view-only. Existing live regions, scan autofocus/focus restoration, disabled rules and warning icons remain. No sticky footer or height-clipped nested scroller introduced.

## Implementation and gate plan

After root confirms this mapped design and obtains the six precise assertion approvals, create a dedicated isolated tree from published shell/current appropriate base with exact integration Show snapshot recorded. Modify Show only (plus approved test expectations); do not touch active integration/Dispensary preview. Freeze clinical script byte parity excluding the two explicitly presentation-only constants. Run all existing frontend tests referencing Show, relevant shared-control tests, lint/format/types/build in the serialized slot, and isolated synthetic visual/interaction checks. Reuse current Dispensary backend workflow proof for unchanged behavior, while still checking real controls after restyle. No commit/push without separate owner approval.

Risks: globally shared children may retain older styles but are outside this initial page slice; exact palette assertions require approval rather than CSS overrides that merely evade them. Demo's data/actions are illustrative, not workflow authority. No backend regression is expected from a presentation-only diff, but live save/check/confirmation guards must remain observable in browser proof.

## Focused-test impact discovery

Existing focused118 tests on the isolated presentation diff:112 pass,6 fail, all appearance-pinned expected groups. Two additional exact groups are now included in PROPOSED-ASSERTION-BATCH.patch: ds-01b-3-otc-dispense retains !isOtc and services test ID while updating its class match to rounded-xl/border/bg-card/shadow-card; dsp-23-doctor-check retains role=status, sent-back test ID and no-destructive check while changing border-warning/40 bg-warning/10 to border-warning/25 bg-warning-wash. Total proposed batch is six groups, ten appearance expectations. No repository test file has been changed.
