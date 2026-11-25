# STK-47 and STK-60: central store, HQ view and the weekly request day (design note, revision 2)

Revision 2 applies Mei Ling's review.

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

**"The store"** means every active location with no branch (the organisation store; today Cheras is one). The page,
the off-day warning and the reminder use this one definition; with more than one such location, each is shown by name
and a request to any of them counts as a store request.

## 2. HQ view (STK-47)

A new page, **Inventory > Central store**, for holders of `inventory.warehouse.organisation`:

- **Store stock:** each SKU in the store with **usable** quantity on hand (the STK-2 eligibility rule: not held,
  recalled or expired, never a patient-returns location), held or expired quantity shown apart, nearest expiry, and
  how many weeks it covers at the branches' combined 30-day rate (the same rate as STK-16 reorder suggestions:
  dispensing plus unreversed treatment use); "No recent use" when the rate is zero. **No cost or value columns**
  (store value is valuation, which stays on the Director-only Stock value page).
- **Branch stock:** for each SKU, each branch's usable quantity against its reorder level (below level in warning
  tone, in words "Below level"), so the store sees who is short before they ask. 25 SKUs a page, with search. The
  warehouse permission is organisation-wide, so a CA Supervisor holding it sees every branch's quantities here: stock
  only, no patient data.
- **Requests to the store:** every open stock request whose source is the store, grouped by branch and status
  (Waiting for approval, Approved, Dispatched, Received with differences), oldest first, each linking to its existing
  page. The approve, dispatch and receive actions stay where they are today.
- **This week:** per branch, whether it has sent its request this week ("Sent Thu 27 Nov", "Not sent yet"). "Sent"
  means a store request created Monday to Sunday (the branch's time zone) that is not a draft and not cancelled; a
  rejected request still counts as sent (the branch did send one; the rejection shows on its own line).

Read only: the page writes nothing.

## 3. Weekly request day (STK-60)

- **Setting:** one organisation setting, the request day (Monday to Sunday, default **Thursday**). No cut-off time in
  this slice. Edited on the Central store page by holders of the existing
  `inventory.references.manage.organisation` (Director, CA Supervisor, Administrator, Store Admin where held), versioned
  and audited (changed field names only). No new permission.
- **Warning, never a block:** a branch that starts a stock request to the store on another day (its own branch-local
  day) sees "The store's
  request day is Thursday. You can still send this; the store may pack it with next Thursday's requests." The request
  is saved as usual. Urgent requests have nothing extra to do.
- **Reminder (inside KPOne only):** on the request day, and on the day before, holders of
  `inventory.stock_requests.create.branch` at a branch that has not yet sent this week's store request see a notice on
  the Inventory page and the Approvals home: "Store request day is Thursday. Your branch hasn't sent one this week."
  with a "Start a store request" link. Nothing is sent outside KPOne (no email, WhatsApp or push).
- **"This week"** runs Monday to Sunday in the branch's time zone.

## 4. Data

One small table, `inventory_store_settings` (organisation, request weekday 1 to 7, `lock_version`, updated by and
at), with an append-only history of versions. No change to stock requests or movements.
A stock request "to the store" is one whose source location has no branch, as today.

## 5. Every write

Only the setting is written: fresh permission check under lock, `DB::transaction`, row lock, `lock_version`,
idempotency key, audit with field names only.

## 6. Pages

Clean Look, shared components only; tables from 1024 px, cards below. Daniel's look first. A new Inventory entry
may touch `dd-12-inventory-headers.test.mjs` or the Inventory tab lists, and the Approvals home notice may touch that
page's pinned props; any such existing assertion is listed for the owner first.

## 7. Tests

Store stock and cover weeks; branch levels and "Below level"; requests grouped and ordered; "sent this week" at
branch-local week boundaries; the setting saved, versioned and refused without permission; the off-day warning shown
and the request still saved; the reminder shown on the day and the day before only to the right people at branches that
haven't sent and never to a branch that already sent; nothing outside KPOne; 403 for roles without warehouse
permission; no cost on the page; more than one store location; held, expired and returns stock left out of on hand;
zero use shows "No recent use"; rejected and cancelled requests against "sent this week"; the warning uses the
requester's branch-local day.

## Daniel's conditions (25 Nov, OK with changes; build to these)

- Order by action: "This week" first as one card per branch ("Week of 24 Nov", branch time), then tabs or a
  segmented control for Store stock, Branch stock, Requests to the store.
- This week cards: "Sent Thu 27 Nov by <name>" success; "Not sent yet" neutral before the request day, warning on
  and after it; never red.
- Store stock: "3.2 weeks" with a dot-plus-word tone ("Less than 1 week" destructive, 1 to 2 weeks warning); "No
  dispensing data" when the rate is zero. Tabular figures. No cost columns (removed, per revision 2).
- Branch stock: a matrix from 1024 px ("40 of 100", "Below level"), cards per SKU below; short-anywhere first.
- Requests to the store: grouped by branch, status chips, oldest first, an outline "Open" link each; links only.
- Request-day setting: header subline "Request day: Thursday" and an outline "Change" for holders only; sheet with
  weekday radios, "This only reminds and warns. It never blocks a request.", one filled "Save". (Revision 2 drops
  the cut-off time.)
- Off-day warning: a warning-wash alert at the top of the request form, not a modal; Send stays the one filled button.
- Reminder: info tone with an icon and one outline "Start a store request" link, on the Inventory page and the My day
  home; it goes away once the branch has sent.
- Shots at 1440 and 390, including a branch that has not sent on the request day.
