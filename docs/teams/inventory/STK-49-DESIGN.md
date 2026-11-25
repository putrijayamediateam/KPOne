# STK-49: supplier payment terms, bill due dates and "Paid in Bukku" (design note, revision 2)

Revision 2 applies Mei Ling's review.

Owner decision 23 (20-21 Nov 2026): supplier payment terms and due dates are shown in KPOne; Finance ticks
"paid in Bukku". No payment is made from KPOne. Build before Monday: owner by tick box, 25 Nov 2026, relayed by
B.E.R.N.I.S.S.

## 1. Payment terms on the supplier

Each supplier gets one optional field, **Payment terms (days)**: a whole number from 0 to 365 (0 means "cash on
delivery"), blank means "not set". It is edited on the Suppliers settings page with the existing
`inventory.suppliers.manage.organisation` (no new permission), versioned with the supplier's existing `lock_version`
and audited as a changed field name only. The Suppliers settings page belongs to Catalogue & settings (Adlina); the
field and its label are agreed with her before build. `supplier-settings.test.mjs` pins the form's label list, so adding
the field changes an existing assertion, which goes to the owner first. The name stays clearly apart from STK-13's
per-SKU supplier terms: "Payment terms (days)" here against "Price and lead time" there.

## 2. Due date on each supplier bill

When a supplier bill (STK-22 supplier invoice) is recorded, KPOne **freezes** the supplier's terms on the bill:
`payment_terms_days` and `due_date = invoice_date + terms`, in the branch's dates. A later change to the supplier's
terms never moves a bill already recorded. With no terms set, the bill has no due date and shows "Terms not set".

## 3. Paid in Bukku

On a bill that is **Matched** or **Accepted**, a Finance Officer ticks **Paid in Bukku**, giving the date paid (today
or earlier, not before the invoice date) and an optional Bukku reference (up to 60 characters, plain text). KPOne
pays nothing and sends nothing to Bukku; the tick is a record that Finance paid it there.

- Permission: the existing `inventory.supplier_invoices.approve.branch`, which after STK-51 only the Finance Officer
  holds. No new permission. STK-49 therefore merges **after** STK-51, and a test pins the holder list. If that
  permission is ever widened, Paid in Bukku widens with it, so a later grant must be a deliberate choice.
- **A paid bill cannot be rejected or reversed** while it is ticked paid: "Undo paid" comes first (service and
  PostgreSQL). Today a matched or accepted bill has no reversal, so this guards any future one.
- A wrong tick is undone with **Undo paid** and a fixed reason ("ticked by mistake", "payment bounced", "other" with a
  note); both rows are kept. The bill is "Paid" while its newest payment record is not undone.
- No second person is needed: nothing in stock or money in KPOne changes. (If the owner wants a second person, it is a
  small change.)

## 4. What the pages show

- **Supplier bills page:** a "Due" column with the due date and, in words and colour, "Overdue by N days", "Due in N
  days" or "Paid on 3 Dec 2026"; a filter "Unpaid", "Overdue", "Paid", "All"; the list sorts unpaid bills by due date.
- **Approvals page:** unchanged (Paid in Bukku is not an approval).
- **Amounts** stay visible only to cost holders, as today.

## 5. Data

- `suppliers.payment_terms_days` (nullable smallint, CHECK 0 to 365).
- `inventory_supplier_invoices.payment_terms_days` and `due_date` (nullable, frozen at recording; a trigger keeps
  them unchanged after insert, like the other frozen fields).
- New append-only `inventory_supplier_invoice_payments`: invoice, paid_on, reference, recorded by and at, undone_by,
  undone_at, undo reason and note (encrypted), lock_version, idempotency key. PostgreSQL: no delete or truncate; only
  the undo fields change, once; a payment only on a matched or accepted invoice; one live payment per invoice
  (partial unique index).

Bills recorded before STK-49 have no frozen terms and show "Terms not set"; nothing is backfilled.

## 6. Every write

Fresh permission check under lock, branch scope, `DB::transaction`, row lock on the invoice, `lock_version`,
idempotency key, and an audit row with ids, codes and dates only (never the reference or the note).

## 7. Tests

Terms saved and audited; due date frozen at recording and unchanged when terms change later; no terms means no due
date; Paid in Bukku by a Finance Officer only, on matched or accepted bills only, with date rules; undo once with both
rows kept; the overdue, due and paid labels at branch-local midnight; the filters; the PostgreSQL rules; a two-process
race of two Finance Officers ticking the same bill and of a tick against an undo; a bill at the organisation store (no
branch) gets the right due date; bills recorded before STK-49 show "Terms not set"; a paid bill cannot be rejected.

## Daniel's conditions (25 Nov, OK with changes; build to these)

- Due column: dot plus word. "Overdue by N days" destructive; "Due in N days" neutral, warning at 3 days or fewer;
  "Paid on 3 Dec 2026" success; "Terms not set" muted. Money-free. Order: overdue, then due soonest, then paid.
- Filters Unpaid, Overdue, Paid, All: a segmented control with counts from 1024 px, the shared select on a phone, with
  the help "Overdue = unpaid and past the due date".
- Paid in Bukku: an outline row button on matched or accepted bills, removed (not disabled) for anyone but the Finance
  Officer. Sheet: date with the window under it ("Between 12 Nov and today") and the refusal beside the field;
  reference with 0 / 60; the top sentence "This only records that you paid it in Bukku. KPOne does not pay or send
  anything."; one filled "Record as paid", Cancel outline.
- Undo paid: a quiet link opening a confirm with the reasons as radios, none selected ("Pick a reason to continue."),
  the note counter for "other", and the history line kept visible ("Paid 3 Dec by <name>, undone 5 Dec: ticked by
  mistake"), names under the staff-visibility rule. A paid row says "Paid, undo it first to reject".
- Supplier form: "Payment terms (days)" with "days" beside it and the help "0 means cash on delivery. Leave blank if
  not set. Bills already recorded keep their old terms." (Adlina agrees the label.)
- At 390 px each bill is a card: supplier and invoice number, the Due line, then the action, 44 px.
