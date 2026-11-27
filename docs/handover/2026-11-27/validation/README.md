# KPOne continuation evidence

26 Nov 2026, local only. Synthetic account `demo.ca@kpone.test` and synthetic patient created through existing domain services. Database `kpone_preview_test`, PostgreSQL 18.1, task-owned loopback port 55439. No production service or data used.

Before UI: main frontend at 23639679e, served from the isolated BIL-38 worktree (BIL-38 changes no frontend). After UI: recovered #1125 shell plus approved card/sheet fixes and continuation fixes in /workspace/KPOne.

- Registration before/after: 1440 px light.
- Patient Billing before/after: 1440 px light, synthetic registered OTC visit without a completed invoice. These are layout checks, not billing workflow acceptance.
- Registration after: 1440 px dark and 390 px light; phone has no document-level horizontal overflow.
- Shell open panel: 1440 px light.

The screenshots do not certify every Stage 0 component, every role, a complete CA flow, or independent design approval. Registration's full restyle and visible heading remain in the separate Stage 1 branch.
