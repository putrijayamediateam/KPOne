# UX-17 evidence (screenshots only, never merged)

- `index.html`: the review sheet for Daniel (open it in a browser; images are embedded).
- `v2/`: Version 2 on Quick create, the Registration board and the Queue at 390, 768, 1024 and 1440 px, light and dark, plus the phone menu sheet and the jump list.
- `classic-base/` (feature/v2-gate d3a1554) and `classic-branch/` (feature/ux-17-v2-shell 0b35919), with V2 off, shot with scripts/classic-compare from PR #597: 56 of 56 identical.
Synthetic demo data only.

## UX-18 ApprovalPanel (feature/ux-18-approval-panel d190f8e)

- `approval-panel/`: the panel in Version 2 at 390 and 1440 px, light and dark: one item the viewer can decide (Approve outline, Reject destructive-outline) and one the viewer recorded (blocked text instead of buttons); plus the Reject dialog after pressing Reject with no reason (the required-reason message). Shot from a temporary, uncommitted mount on Clinic management with sample props; no page uses the panel.
