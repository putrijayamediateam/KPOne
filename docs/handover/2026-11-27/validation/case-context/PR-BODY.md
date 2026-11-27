The Dispensary patient and summary cards currently pack identity, visit facts and counters into small dense rows. Group the existing patient name and KP number beside a decorative icon, separate wrapping Visit/Doctor facts, and give Summary rows a 40px minimum height with dividers.

Stacked on #1158. Only Show.vue and the team delivery note change. Existing readonly/OTC conditions and count expressions remain exact; the script changes only by importing the decorative icon. No additional patient facts, inferred demographics, actor-attributed metric, action, permission or workflow state.

Validation: 156 existing focused frontend checks unchanged, scoped lint/format, full frontend types/build and script parity. Actual synthetic browser proof passes 16 light/dark captures at 390/1440 with long patient/doctor names, dirty counters, readonly and empty OTC states; no overflow/errors and Summary rows at least 40px. No new backend suite is claimed for this presentation-only change.

This addresses only the available-data patient/context composition gaps in the reference demo; missing payer/age/queue/call-provenance fields and shared shell behavior remain outside scope.

Independent Design source/visual review is clear for the exact two-file patch; long-name, readonly and zero-OTC captures were independently inspected.
