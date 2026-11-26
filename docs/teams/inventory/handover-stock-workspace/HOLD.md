> Current status: superseded by RESUMED-DECISION.md. Owner resumed this existing slice and delegated technical decisions; the six proposed groups are now applied after independent review. Full frontend now passes2208/2208. Desktop target correction is implemented; final measured validation is recorded in the PR. The text below is the historical preservation record.

# Owner hold and draft publication — 26 November 2026

Owner requested all existing work be committed and published as scoped draft PRs before handover. No new implementation, merges or unapproved assertion changes.

This draft preserves the Stock workspace/On-hand source and five new runtime tests. Six existing assertion proposals are included as documentation ONLY; they are not applied to tests. Full frontend gate is therefore not green. See ASSERTION-BATCH.md and ASSERTION-PROPOSAL.patch.

Independent designer verified mobile44px controls and loading parity and independently passed the final five runtime tests. Remaining desktop target blocker: Hide helpful info32px, Location summary24px and four helpful-info summaries28px; agreed desktop minimum36px is not yet met. Suggested page-local lg:min-h-9 change is NOT implemented because the owner requested a hold. Earlier REVIEW.md 'pending final confirmation' must not be read as full signoff.

Source/types/build/scoped lint/format and actual synthetic browser checks are recorded in REVIEW.md. These are carried local results, not a claim that this new commit has passed CI or full backend verification. No production or real data changes.

Other Inventory demo tabs and workflows remain unfinished; this is the first bounded On-hand composition slice. No Classic/V2 switch. The operations/reference groups preserve existing functionality and unsaved drafts. Do not label this draft full demo parity or merge-ready.

The committed assertion proposal uses zero-context diff serialization to avoid trailing context whitespace in documentation. It remains UNAPPLIED; after exact approval only, apply with `git apply --unidiff-zero`. The original contextual proposal remains in the validation artifact directory.
