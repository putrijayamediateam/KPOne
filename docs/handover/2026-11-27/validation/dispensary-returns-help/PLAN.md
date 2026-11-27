# Returns helpful information — bounded F04 continuation

Prepared after F02 navigation, before implementation. Standing coordinator authorization permits reviewed existing-workflow continuations; no new owner clinical decision is inferred from the uploaded demo.

## Exact reference and scope

Pharmacy.dc.html lines92–105 render a toggled right helpful-information panel, placed after primary content on narrow screens. Lines198–207 define About this tab, Today at a glance, Related pages and Good to know. The same panel applies to Returns tabs. Existing ReturnsToCheck has operational tab counts/filter/row instructions but no optional helpful panel. The separate published Returns branch has existing authorized Dispensing navigation after F02; do not import the unrelated Index stack.

Propose one page plus a focused new test and TEAM entry, isolated stacked on F02 after publication. Add local presentation-only help visibility state and native disclosures, right of content at wide widths and below content on mobile. Default shown, stable toggle focus, v-show preserves open details. Keep all present safety/workflow instructions and filters outside optional content. Existing clinical/query/filter/row expressions and handlers remain unchanged.

- About this tab: Waiting is read-only, oldest-first; open the existing case to check. Checked is the branch-local current-month list, with recorded outcomes. Do not imply that opening a row performs a check.
- At a glance (not Today): show only existing counts when present, with exact scope labels verified against waitingList's current filtered counters. Do not relabel monthly checked or all-age waiting as today's activity. No invented oldest metric, no new query, no zero substitution for absent counts. If counter semantics cannot be made clear, omit this subsection rather than infer.
- Related pages: existing authorized Dispensing route only, without duplicating a long unauthorized demo link directory. No settings/inventory/doctor link inferred from Returns permission.
- Good to know: check happens on the case; own-record separation rule and existing outcome/controlled rules only after exact service confirmation. No refund workflow, Ready state or new inline action.

Targets44px below1024 and36px desktop; native summary/keyboard focus, long-copy wrapping, dark/light, no page overflow. No forced autofocus on polling/tab changes. Expanded optional state is presentation-only and carries no clinical data beyond current page props.

## Validation and review

Staff reviews composition/copy and source guards before implementation. Inventory verifies exact existing count and outcome semantics if used. New meaningful rendered/runtime tests prove required instructions remain visible while help is hidden, disclosure/toggle behavior and missing-count fallback. Actual synthetic Waiting/Checked390/1440 light/dark, shown/hidden/open details, keyboard focus and filter state unchanged. No mutation or new permissions. Existing assertion changes, if any, must be explicitly inventoried and reviewed before editing. Run appropriate static/types/build, freeze exact patch, independent final review, then separate draft; no merge or full demo completion claim.

## Source-reviewed implementation disposition

Inventory confirmed both counters use Controlled; Waiting additionally uses selected recorded-at calendar age only on Waiting, Checked uses selected outcome only on Checked and always the branch-local checked-at current month. Render supplied zero; omit entire count disclosure when absent. Labels explicitly describe each scope; no atomic snapshot or today's-count claim. Controlled copy says cannot quarantine, with Dispose or Refuse-if-mistake alternatives, not an assertion that a return is already disposed.

Design confirmed actual table min-width960 means the right rail must depend on available content width. Implemented named CSS container threshold1280px, 280px rail plus24px gap leaving at least976px primary area. Main expands to screen-2xl only at2xl; below sufficient content width panel remains underneath. Validate1440,1536 and1920 actual shell geometry rather than assuming viewport width. Existing table contract is unchanged; this is a documented content-safe adaptation.

Three new actual-template tests pass. Existing script parity excludes only ref import and showHelpfulInfo presentation state. Existing operational template remains equivalent after whitespace and one formatter trailing comma normalization. Two old source assertions need only whitespace-normalized input after formatter rewrap; exact adaptation sent for independent review before edit.
