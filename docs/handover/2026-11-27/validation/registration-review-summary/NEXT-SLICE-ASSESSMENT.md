# Next Registration surface assessment (proposal, not implemented)

The demo's remaining major RegisterVisit gap is its modal/sheet and four-part form organization. Current Create is a full page with selected/quick patient, visit/coverage controls, repeat-validation errors and sticky submit; the present review-summary slice supplies only the review content.

A safe next slice should first map the exact navigation/dirty-state contract: opening from Today, direct URL fallback, browser Back/Forward, close/cancel with dirty selected/quick/guardian state, processing-state close suppression and focus return to the original trigger. Existing atomic submit, retry keys, expected branch, duplicate/adult/guardian handling and errors must stay in the same form owner. Emergency-contact staging must remain supported when that independent PR is integrated; no duplicate form-owner instance or hidden background request should be introduced.

Preferred implementation boundary for independent review is extracting the existing form into a reusable surface without changing submit behavior, retaining the full-page route first. A modal host should follow only after the extraction is proven and shared layout/modal ownership is coordinated. Do not introduce a four-step validation state machine merely to imitate the demo: it can hide server errors and change existing focus/keyboard behavior. Explicitly distinguish content sections from workflow gates.

No code, route, permission or schema change is authorized by this assessment alone; root's standing bounded-continuation instruction governs the next concrete reviewed plan. Full Patient overview, all-branch totals and follow-up require separate authoritative data/owner mapping and are not substitutes for this layout work.
