# Next Panel gap: Not yet submitted, with existing due rules

Prepared after Waiting-payment1218 publication. Coordinator delegated technical approval and independent Staff/Inventory pre-code reviews were received on 27 November; implementation now follows this bounded contract. Final source, runtime and publication gates remain separate. Source: actual Panel demo To claim description, PanelClaimDirectoryService::overdueClaimIds and current Submitted umbrella, PanelClaimService::submit and PanelClaimDueRule::dueAt/isOverdue/describe. Same active branch/organisation and claims permission; no financial/status/action policy change.

## Concrete gap and bounded read

On-time Prepared claims currently sit inside the broad Submitted tab; Pending lists approved invoices with no claim, while Overdue only surfaces never-submitted Prepared claims already late. A dedicated `not_submitted` view labelled Not yet submitted would show current status=prepared AND no submitted event, regardless of whether due yet. It must not be called Ready to submit: actual submission still checks source claimability, payments and lock version. Prepared claims edited after an earlier submission are deliberately outside this first-submission view and remain reachable through existing Submitted/All/Follow-up contracts. Rejected/Queried/Paid/Cancelled are not renamed or included.

Keep existing Pending, Overdue and Submitted untouched. Pending navigation remains the existing route for invoices without claims; this slice does not falsely unite two different record types or promise full demo To claim parity. New rows reuse current claim/patient/Panel/invoice identity, claimed amount and existing View claim/manual-submission destination. No new bulk submit, portal action or permission.

## Due information from actual existing rule

For the bounded20 returned rows only, batch current Panel rule plus source visit registered_at within same organisation. Evaluate the unchanged PanelClaimDueRule in branch timezone, exactly as existing Overdue does. Show due date and rule description; timed daily/weekly/monthly rules also show their due time. For days_after_visit, present date-only: it is late only after the due date, so never display midnight as a cut-off. Equal timed due instant is not overdue (existing strict lt). Mark overdue using existing helper, no new3-day approximation or urgency policy.

Visible help: these are Prepared claims with no recorded submission; dates follow the Panel's current submission rule, and the date-range filter still uses claim creation. Open the claim to review it and record the submission; listing is not a guarantee that the current underlying invoice still passes submission checks. Do not project medical notes, diagnosis, attachments or staff fields beyond existing minimized row.

## Files and tests

Narrow existing directory/controller whitelist and page tab/conditional due fields, optional small bounded due projection helper if needed; new backend and actual-template tests; TEAM continuation. Full matching filtered count before20-row pagination using current list pattern; no list-global financial total, arbitrary200 cap or new migration. Existing amount/name/Panel/claim/invoice/date semantics and all prior summaries unchanged. Due hydration should remain bounded with1vs20 constant query proof, not one query per row.

Tests: before/on/after day deadline; exact timed instant and just after; daily/weekly/monthly and branch-local midnight; Prepared never submitted vs Prepared-after-previous-submission (real edit flow); other statuses excluded; current archived Panel rule behavior matches Overdue; branch/organisation/role denial; full count beyond page and filtered empty; existing submission refusal/idempotency/source guard unchanged. Real browser on-time+late rows, empty, page2, keyboard and390/1440 light/dark. Independent Staff/backend plan review precedes code.

Paid-date and all-branch policy remain explicitly separate. This proposal delivers an actual missing first-submission worklist and due context without changing the established lifecycle or claiming the complete six-tab workspace is finished.
