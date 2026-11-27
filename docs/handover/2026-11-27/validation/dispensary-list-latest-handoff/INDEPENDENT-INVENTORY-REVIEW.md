# Independent Inventory list-order correction review

Actual one-line source fix CLEAR: eager-load-only reorder(attempt_number,desc) replaces conflicting appended orderByDesc, allowing list first() to select actual newest attempt. Global handoffs relation remains ASC for history; no medicine search, permissions, labels policy, schema or mutation added. Read two real domain-flow regressions: return→doctor resend→start→remove yields latest0 versus prior1; completion restores existing CA reprint/doctor view eligibility while preserving historical status order. Before log demonstrates both failures on original source exactly as described. Unique case+attempt makes ordering deterministic.

Final builder gates remain separate; no independent heavy test run. This explicitly corrects prior inferred latest-count semantics and does not claim the old implementation already behaved that way.
