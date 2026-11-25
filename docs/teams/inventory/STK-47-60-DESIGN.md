# STK-47 and STK-60: central store, HQ view and the weekly request day (design note, revision 1)

Owner decision 19 (20-21 Nov 2026): a central store in Cheras; suppliers deliver to the store; branches request from it
on a weekly request day (Thursday) with a reminder. TEAM.md decision 47: (b) the Cheras central store **is the
existing organisation warehouse** (a location with no branch); (c) the request day **warns only, never blocks**; and
STK-60 uses the existing two-stage transfer. Build before Monday: owner by tick box, 25 Nov 2026, relayed by
B.E.R.N.I.S.S.

## 1. What already works on main

- An organisation store location (no branch) holds stock; purchase orders can deliver to it; stock requests move stock
  from it to a branch in two stages (request, approve, dispatch, receive, with shortages resolved by someone else,
  STK-6). Holders of `inventory.warehouse.organisation` (CA Supervisor, Store Admin) act for the store.
- So no new stock movement, transfer type or permission is needed. STK-47/60 adds the views and the rhythm around it.

## 2. HQ view (STK-47)

A new page, **Inventory > Central store**, for holders of `inventory.warehouse.organisation`:

- **Store stock:** each SKU in the store with quantity on hand, nearest expiry, and how many weeks it covers at the
  branches' combined 30-day dispensing rate. Cost columns only for cost-view holders.
- **Branch stock:** for each SKU, each branch's quantity against its reorder level (below level in warning tone, in
  words "Below level"), so the store sees who is short before they ask.
- **Requests to the store:** every open stock request whose source is the store, grouped by branch and status
  (Waiting for approval, Approved, Dispatched, Received with differences), oldest first, each linking to its existing
  page. The approve, dispatch and receive actions stay where they are today.
- **This week:** per branch, whether it has sent its request this week ("Sent Thu 27 Nov", "Not sent yet").

Read only: the page writes nothing.

## 3. Weekly request day (STK-60)

- **Setting:** one organisation setting, the request day (Monday to Sunday, default **Thursday**), and an optional
  cut-off time (default none). Edited on the Central store page by holders of the existing
  `inventory.references.manage.organisation` (Director, CA Supervisor, Administrator, Store Admin where held), versioned
  and audited (changed field names only). No new permission.
- **Warning, never a block:** a branch that starts a stock request to the store on another day sees "The store's
  request day is Thursday. You can still send this; the store may pack it with next Thursday's requests." The request
  is saved as usual. Urgent requests have nothing extra to do.
- **Reminder (inside KPOne only):** on the request day, and on the day before, holders of
  `inventory.stock_requests.create.branch` at a branch that has not yet sent this week's store request see a notice on
  the Inventory page and the Approvals home: "Store request day is Thursday. Your branch hasn't sent one this week."
  with a "Start a store request" link. Nothing is sent outside KPOne (no email, WhatsApp or push).
- **"This week"** runs Monday to Sunday in the branch's time zone.

## 4. Data

One small table, `inventory_store_settings` (organisation, request weekday 1 to 7, optional cut-off time,
`lock_version`, updated by and at), with an append-only history of versions. No change to stock requests or movements.
A stock request "to the store" is one whose source location has no branch, as today.

## 5. Every write

Only the setting is written: fresh permission check under lock, `DB::transaction`, row lock, `lock_version`,
idempotency key, audit with field names only.

## 6. Pages

Clean Look, shared components only; tables from 1024 px, cards below. Daniel's look first.

## 7. Tests

Store stock and cover weeks; branch levels and "Below level"; requests grouped and ordered; "sent this week" at
branch-local week boundaries; the setting saved, versioned and refused without permission; the off-day warning shown
and the request still saved; the reminder shown on the day and the day before only to the right people at branches that
haven't sent; nothing outside KPOne; 403 for roles without warehouse permission; cost columns only for cost holders.
