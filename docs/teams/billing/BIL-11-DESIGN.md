# BIL-11 design note: freeze each service's category on the invoice line

Status: owner request 2026-11-07 in the Billing session ("start with 2", after the BIL-10 refund credit row).
Draft for owner decisions (section 4). Synthetic `.test` data only. Builds on BIL-9 (#388), which must merge first.

## 1. The gap

- BIL-9's invoice category table (Service, Procedure, Investigation, Consultation, Medicine, Document, Package) reads
  each service line's category from the clinical service **when the invoice prints**.
- If someone renames a service's category after the invoice was finalised (for example "Procedure(s)" to "Other
  service"), the next print of that invoice moves money between category rows. Its content changes, so BIL-5 prints
  it as **REVISED**, although nothing about the bill changed.
- CAT-11 (#381) gives Clinical Service categories a fixed list, so renames become rarer, but a service can still be
  moved from one fixed category to another.

## 2. Proposed shape (team recommendation)

- **Copy the category onto the line.** `invoice_lines` gains one nullable text column, the service's category as it
  was when the line was built. The draft invoice copies it on each build or refresh; finalisation freezes it with the
  rest of the line (finalised lines are already immutable in PostgreSQL).
- **Print from the copy.** The category table uses the line's frozen category. The mapping is unchanged:
  "Procedure(s)"/"Procedure"/"Procedures" go to Procedure, "Investigation(s)"/... to Investigation, anything else or
  none to Service.
- **A rename between draft and finalisation** marks the draft stale, like a price change today, so staff refresh it
  and the finalised invoice carries the category current at finalisation.
- **No change** to amounts, totals, receipts (they carry no categories), Panel claims, reports or permissions.

## 3. Alternatives

- **Keep reading the live category** (today). Simplest, but a rename keeps revising old invoices.
- **Freeze the whole category table in the issued copy only.** BIL-5 already freezes what was printed, but the next
  print still recomputes from live data and becomes REVISED; it does not remove the cause.

## 4. Questions for the owner

1. **Invoices finalised before this change** have no copied category, and their lines cannot be edited (financial
   evidence is immutable). Should they:
   - **keep reading the live category** (recommended: nothing old is touched; only a later rename can still revise
     them), or
   - get a **one-time copy** of today's categories through a deliberate migration that briefly lifts the immutability
     rule for that one column (stops all future drift, but edits finalised financial records)?

## 5. Not in BIL-11

No new category, no change to the category list (Catalogue & Settings owns it), no change to BIL-9's layout, no
re-issue of existing copies.

## 6. Owner decision (2026-11-07, answered in the Billing session)

1. **Invoices finalised before BIL-11 keep reading the live category.** Nothing old is touched; every invoice
   finalised after BIL-11 carries its frozen category. Build after BIL-9 (#388) merges.

## 7. Built (2026-11-07)

- Additive migration `2026_11_12_300100`: nullable `invoice_lines.service_category_snapshot`.
- `PriceResolutionService::price()` copies the clinical service's category onto each service line when the draft is
  built and again at finalisation; `''` means frozen with no category (Service). The value is part of the line set
  the draft's hash covers, so a rename between build and finalisation makes the draft stale and staff rebuild it.
- `IssuedBillingDocumentService` groups lines by `COALESCE(service_category_snapshot, live category)`: frozen lines
  never move; lines finalised before BIL-11 (NULL) keep reading the live category (owner decision 1).
- A finalised line's frozen category cannot be changed (existing `invoice_lines_retained` guard, checked at commit).
- No change to amounts, receipts, permissions, screens or reports. No existing assertion changed.
