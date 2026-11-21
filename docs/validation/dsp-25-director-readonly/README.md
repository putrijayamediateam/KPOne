# DSP-25: the Director's read-only dispensary case (validation shots)

Synthetic `.test` data on a task-owned preview database, signed in as the demo Director. Branch under review: `feature/dsp-25-director-readonly-dispensary`.

- `visit-link-390-light.png`, `visit-link-1440-light.png`: the visit page with the Dispensary card (status and "Open dispensary case (read only)") under Visit Reason.
- `review-completed-*` (an OTC case with returns) and `review-dispensing-*` (a consultation case still being dispensed), at 390 and 1440 px, light and dark.

Measured on every shot:

| Check | Result |
|---|---|
| Sideways overflow | 0 px |
| Buttons, inputs, selects or text boxes on the page | 0 |
| Back link height | 44 px at 390, 36 px at 1440 |
| Banner position | first block under the top bar |
