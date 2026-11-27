# Next branch Held presentation — separate bounded slice

Canonical branch On hold columns: No., Patient, Doctor, Treatment step, Held, Actions. Existing authorized treatmentProgress and heldMinutes suffice. Do not add outcome visibility: use the same already policy-gated DTO and actual badge, including truthful unavailable fallback.

Propose explicit queueLayout=branch && queueTask=held only. Place exact existing safety/status renderer under Patient (including its triage slot, because branch Held has no dedicated Zone column). Keep readiness controls, urgent/returned/check notices, treatment controls and one parent-owned dialog key. Suppress only duplicate badge there with explicit existing showTreatmentBadge=false; render the actual badge once in dedicated Treatment step cell, with default outcome/ARIA behavior and warning tone. Doctor cell/reassignment warning and authority unchanged. Timing uses existing Held duration and label.

Own Held, branch Waiting/Serving timeline, emergency without task, Registration and branch Done remain unchanged. No endpoint, data, permission, mutation or new Resume action.

Require actual SSR branchHeld badge/triage once, unavailable/policy-withheld/unsuccessful outcomes, unchanged defaults; compiled dialog/readiness lifecycle across held→default→timeline with one active instance. Browser branchHeld390dark/1440light with retained actual unsuccessful step and controls, action hit/focus below host, state invariance. Exact existing branch-Held expectation adapters need peer review before edit. Separate draft after current timeline publication.
