# BIL-5 design note: issued invoice and receipt copies

Status: owner decisions taken 2026-11-05 (section 6); being built as BIL-5.

## 1. The gap

Invoice and receipt prints (`BillingController::printInvoice`, `printReceipt`, page `Billing/Print.vue`) are drawn
from live records every time. A reprint shows today's letterhead, footer, Bill to, status and "Due now", not what
the patient was given. Nothing records that a paper copy was ever printed. TEAM.md section 7 lists this as a known
limit; the Medical Certificate (DOC-02b) already solves the same problem with an encrypted, immutable snapshot.

## 2. What can change after a document is first printed

- Invoice: an adjustment (only before any payment), Bill to (any time), payments and Panel coverage (so "Due now"
  changes), void (then a new invoice). Letterhead and footer can change at any time.
- Receipt: only its status (posted to reversed) and the letterhead or footer. Its amount, method and number never
  change.

## 3. Proposed shape (team recommendation)

- **Printing becomes a recorded action.** The Print button asks the server to issue the copy (a POST with an
  idempotency key), then opens the print view of that issued copy. The print view itself stays read-only.
- **Snapshot per version.** The first print of a document stores an encrypted, immutable snapshot of exactly what
  is printed (all print props, including the letterhead text and the logo bytes, as the MC does) and labels it
  **ORIGINAL**. A later print of the same content prints the stored snapshot labelled **COPY**.
- **If the invoice changed since its last issued copy** (adjustment, Bill to, payments, status, letterhead or
  footer), the next print issues a new version labelled **REVISED (version n)**. Earlier versions stay readable in
  a short "Issued copies" list on the Billing page (number, version, when, by whom, ORIGINAL/REVISED/COPY count).
- **Receipts:** same rule. A reversed receipt's copy shows the snapshot plus a current-status line "This receipt
  was later reversed (recording error)". The snapshot itself never changes.
- **Documents printed before BIL-5** have no snapshot: their first print after this change becomes their ORIGINAL
  (marked "issued from current records on <date>"), since earlier paper was not recorded.
- **Storage:** new append-only tables `issued_billing_documents` (document type, invoice or payment, version,
  label, content hash, encrypted snapshot, issued by and at, idempotency key) and `issued_billing_document_prints`
  (one row per print of an issued copy). PostgreSQL refuses update, delete and truncate. Audit
  `billing.document_issued` and `billing.document_reprinted` (type, version, label; no content).
- **No change to amounts, numbering, permissions or who may print** (`billing.print.branch`: CA, CA Supervisor).

## 4. Questions for the owner

1. When is a copy frozen: at first print, per version (recommended), or automatically at finalisation and payment
   even if never printed?
2. When an invoice changed after it was printed, should the next print issue a REVISED version (recommended), or
   always reprint the first ORIGINAL with a "changed since" note?
3. Labels on paper: ORIGINAL / COPY / REVISED in English (recommended, the prints are English), or Malay
   (ASAL / SALINAN / DISEMAK)?
4. Who sees the "Issued copies" list: everyone who can print (recommended), or also Finance Officer and Director
   (who can open Billing but not print today)?

## 5. Not in BIL-5

- No PDF archive or file storage, no e-Invoice or tax format, no change to the print layout (D03) beyond the label
  and status line.
- No change to Panel claim packs, reports or the Medical Certificate.

## 6. Owner decisions (2026-11-05, answered by the owner in the Billing session)

1. A copy is frozen **at first print**, per version: printing is a recorded action; the first print is ORIGINAL and
   later prints of the same content are COPY.
2. When an invoice changed after its last issued copy, the next print issues a **REVISED** version (2, 3 ...);
   earlier versions stay readable in the "Issued copies" list.
3. Labels on paper are **English**: ORIGINAL, COPY, REVISED.
4. The "Issued copies" list is shown to **those who can print** (`billing.print.branch`: CA, CA Supervisor). No new
   permission.
5. The old live print pages stay viewable but are marked "PREVIEW — not an issued copy" on paper; every Billing
   print button records the print (Siti's sketch found several entry points, including the patient Invoices page).
6. The recorded print view opens in the same tab, with "Back to billing".
