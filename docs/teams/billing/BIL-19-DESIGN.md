# BIL-19 design note: "Recorded by" on the completed-visit Invoice page

Chosen by the owner on 2026-11-10 ("start with 1"). Sketched by Siti before building. Display only: no money rule,
permission, route or stored-data change.

## What changes

Each receipt on the completed-visit Invoice page (`Billing/Invoice.vue`, Payments) now reads like the Billing page
(BIL-2):

- Line 1: method and amount (unchanged).
- Line 2: receipt number · received date and time (branch time zone, `j M Y, g:i A`) · status.
- The "Refunded … kept" line, when there is a refund (unchanged).
- A muted last line: "Recorded by {name}".

The name follows the BIL-2 rule. It is the staff member's name when the viewer may see them
(`StaffAccessService::visibleUsers`), otherwise "Staff member". The line is always shown, so the row looks the same
for everyone. Names are looked up in one query per page.

`VisitHistoryService::financial()` adds `receivedAt` and `recordedBy` to each payment. That projection is shared by
the Invoice page and Visit history, so both payloads carry the two fields (to the same billing viewers who already
see those receipts); only the Invoice page shows them, and the history page's one extra name lookup was judged not
worth a separate code path.
`VisitHistoryService` is a shared Visit file that Billing has edited before (BIL-10). Prints are unchanged and never
carry the name.

Who sees it: the Invoice page's financial block already requires `billing.view.branch` or `billing.summary.branch`.
A Resident Doctor's Invoice page has no financial block, so no receipts or names.
