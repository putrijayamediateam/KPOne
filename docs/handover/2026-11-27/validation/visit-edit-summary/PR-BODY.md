Visit Edit now shows a read-only list of fields changed from the initially loaded draft. Staff can review the affected fields before saving without repeating panel-note or member-reference contents.

The existing page script is unchanged except for importing the summary. Save payloads, versions, panel-note commands/watchers, validation and conflict messages are preserved. Comparison follows current service behavior for self-pay fields, PHP whitespace and ordered structured reasons; legacy reasons are ignored when the existing submit transform omits them. The summary adds no controls, requests, confirmation or readiness verdict.

Validation: five new actual-component tests; 2,214 full frontend tests; types/build/full lint/format; 14 existing backend reason tests/62 assertions. The first type process was killed under memory pressure; the same-source serial retry passed without config changes. No existing assertions changed.

Sixteen synthetic browser captures cover responsive light/dark states, field-name-only panel changes, self-pay behavior, and a real server stale-version refusal retaining the summary and original error. The outgoing version was deliberately changed for this controlled test. Visit records remained unchanged; no successful update or full demo parity claim.
