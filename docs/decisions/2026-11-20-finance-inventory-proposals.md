# Owner decisions: Finance (TYBE) and Inventory proposals, 20-21 Nov 2026

Source: the owner's answers in the questionnaire "KPOne decisions: Finance and Inventory proposals"
(32 questions, answered 21 Nov 2026), comparing KPOne `main` with the Finance Manager's
"TYBE Phase 1 Preview" and the inventory team's "Prototaip Sistem Inventori Klinik Putrijaya".
The owner then said "start, and yes confirm the Q24 rule". Synthetic data only; nothing here is
production approval. Each slice still needs its design note, the Lead UI/UX Designer's look for
screens, owner approval of any existing-assertion change, green CI and the owner's ship-it.

## A. Roles and access
1. New role **Branch Manager** (per branch), separate from CA Supervisor.
2. New role **Account Assistant** (HQ finance: records and views, never approves).
3. New role **Store Admin** (central store: stock and purchasing only; no patients, no money).
4. **Director** gets a **read-only** view of consultations and dispensary; every view is audited.
   (Explicit owner role decision, as AGENTS.md requires for clinical access.)
5. **Audit log** readable (read only) by Finance Officer, Account Assistant and Panel Officer,
   in addition to Director, HR Manager and Technical Admin.
6. **Cost prices** visible to Store Admin and Account Assistant, in addition to Director and
   Finance Officer. Doctors never.
7. **Branch Manager** may download their own branch's reports.

## B. Billing and money
8. Discounts unchanged (CA up to the branch limit; above it CA Supervisor, Finance or Director).
9. **Void** approved by the Branch Manager; never after the day is locked.
10. **Refund** approved by the Branch Manager; refunds **above RM300** are reviewed by the Finance
    Manager (Finance Officer) first.
11. **Daily close**: CA counts by notes, Branch Manager approves, and the day is **locked** (no
    bill of that day can be changed or voided; refund only). **New transactions pause** while the
    close waits for approval.
12. Mark **foreign (passport) patients** now; calculate **SST only after the accountant confirms**
    the rate and rules.
13. **Sales targets** per branch and per doctor; each doctor sees only their own.

## C. Consultation and dispensary
14. **Doctor checks prepared medicine before payment**: a per-branch switch (on or off).
15. **Vitals picked up from the typed note** and filled into the vitals boxes for the doctor to
    confirm (the separate boxes and triage stay).
16. **Panel limit per visit** shown to the doctor and at billing; warns, never blocks.
17. **Unit conversion** per item (for example 1 bottle = 60 ml), set once and checked by a second
    person.

## D. Stock and purchasing
18. Stock quantities still never typed in directly; add **"Report a difference"** for staff, which
    opens a count case for approval.
19. **Central store** in Cheras: suppliers deliver to the store; branches request from it on a
    **weekly request day (Thursday)** with a reminder.
20. **Store Admin prepares purchase orders; the Director approves every PO.**
21. **RFQ** before the PO: pick items, one RFQ per supplier, convert the chosen quote to a PO.
22. **Account Assistant records the supplier bill; Finance accepts.**
23. **Supplier payment terms and due dates** shown in KPOne; Finance ticks "paid in Bukku". No
    payment is made from KPOne.
24. Cost comes from the supplier receipt (as today), **and may also be typed by hand** under this
    owner-confirmed rule: only Finance Officer or Store Admin may type it; it is labelled "entered by
    hand"; it is audited; and a second person checks it before it counts.
25. **Expiry alert days per item** (default 90) plus a **6-month at-risk list sorted by cost**.
26. **Medicine sales page** (top 25 by month: units, sales, gross profit, margin, items sold below
    cost) for Director and Finance, and **7/14/30-day sales** columns in reorder suggestions.

## E. Panel
27. **Panel price changes**: the Panel Officer proposes with the agreement reference and an
    effective date; Finance approves before it applies.
28. **Panel claims created automatically** when a Panel bill is approved; the Panel Officer
    submits them.
29. The **IC stays on the claim page** for the Panel Officer (audited view), as decided earlier.

## F. Daily work screens
30. One **"Approvals & tasks" inbox** listing every approval waiting for the person; each opens
    its own page to decide (the rules behind each approval do not change).
31. A **"My day"** page as each role's first page, with work items and a personal routine
    checklist that resets daily.
32. A **Director and Finance overview** on top of Insights: targets, claims, items waiting for
    approval.

## Slices and owners (20-21 Nov 2026)
Wave 1 (base): ROLE-1 new roles and access grants (items 1-3, 5-7), Staff Experience.
In parallel (no dependency on the new roles): Billing (12, 13, 32), Dispensary & OTC (14, 17),
Queue & TV with Dispensary (4, 15), Panel (16, 28), Catalogue & settings (27), Inventory (18, 19,
21, 23, 24, 25, 26).
After ROLE-1 merges: Billing (9, 10, 11), Inventory (20, 22), Staff Experience (30, 31).
