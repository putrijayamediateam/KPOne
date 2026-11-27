# CI-MQ-1: using GitHub's merge queue with `tests.yml`

`.github/workflows/tests.yml` now also runs on the `merge_group` event (`checks_requested`). Nothing is switched on by the
code: the owner changes the GitHub settings below. Until then the workflow behaves exactly as before.

## What the workflow does for a queued candidate

- `attest` validates the group: `github.event.merge_group.head_sha` and `base_sha` are full lowercase 40-hex SHAs, the
  head equals the SHA this run was triggered for, head is not base, and the ref is `refs/heads/gh-readonly-queue/...`
  and equals `merge_group.head_ref`. It then checks out that exact head with full history and proves HEAD is the
  candidate and that the candidate contains `base_sha`.
- `static`, `backend (shard 1..3)` and `ci` run unchanged on the attested SHA. Job names are unchanged.
- Concurrency uses the run id for `merge_group`, so queued runs never cancel each other. Only `pull_request` runs cancel
  older runs of the same PR.
- Pull request, push to `main` and exact-SHA dispatch checks are unchanged.

## Settings for the owner (you switch these; Claude never does)

1. Settings → General → Pull Requests → tick **Allow auto-merge**.
2. Settings → Rules → Rulesets → New branch ruleset (or edit branch protection) targeting `main`:
   - **Require a pull request before merging** (keep your current review rule).
   - **Require status checks to pass**, adding exactly these checks (source: GitHub Actions):
     `attest`, `static`, `backend (shard 1)`, `backend (shard 2)`, `backend (shard 3)`, `ci`.
   - **Require merge queue**. Suggested: merge method Merge commit (as today), build concurrency 1, minimum group size 1,
     maximum group size 1 while we learn it, status check timeout 60 minutes.
3. Do not enable the queue before the Puchong CA live test on Monday 30 Nov 08:30 MYT unless you want it tested live.

## How to verify after the queue is on

1. Open a small docs PR and click **Merge when ready**.
2. In Actions, a `tests` run for event `merge_group` appears on a branch named `gh-readonly-queue/main/pr-N-...`.
   `attest` must show "Validate merge-group candidate" and "Attest merge-group checkout and base" green, and the
   required checks `attest`, `static`, `backend (shard 1..3)` and `ci` must all report.
3. The PR merges by itself when they pass. If it hangs at "Waiting for status", a required check name does not match:
   compare with the names above.
4. Queue two PRs at once and confirm the second run is not cancelled by the first.
