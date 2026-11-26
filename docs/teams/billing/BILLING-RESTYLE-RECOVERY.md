# Billing restyle recovery — 26 November 2026

PR1143 preserved an approved BillingWorkspace restyle and the owner's DD29 change from compact30px to36px desktop controls, retaining44px below1024. It is not wholly superseded by PR1141: the latter adds collection/daily composition, but does not include the workspace changes.

Recover the exact original BillingWorkspace template/CSS and DD29 changes from7545a98e0139dcf3b69fcdc8e66820dc0784d3d6. For CashClosings, retain1141's frame, permission-gated links and context; recover only remaining original body layout/semantic status tokens. Both local header links also receive the same36desktop/44mobile target sizing under the owner's delegated technical decisions. No shared primitive or financial script changes.

Fresh-main validation candidate began at normal merge b71f71486496e1b1dc7b567e2a493ac528272452 (1141 plus main eec5d5444, including BIL36/37). Publication must carry only this scoped recovery on1141, not unrelated upstream main files. Run focused gates on that actual publication candidate and preserve the full original script sections, monetary expressions, readonly authority and billing actions. The DD29 assertion adjustment is the already-recorded owner decision; no new direct owner answer is claimed.

This is preservation and reconciliation of reviewed work, not complete Billing demo content parity. PR1143 remains preserved until the coordinator reconciles release status; this change does not close or merge it.
