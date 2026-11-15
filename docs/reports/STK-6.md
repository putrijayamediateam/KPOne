# STK-6 — transfer shortages

Date: 2026-11-05. Branch `feature/stk-6-transfer-shortages` from `main` at `b5c7a31`. Owner authorisation: Inventory
team backlog item 1, chosen 2026-11-05 (see `docs/teams/inventory/TEAM.md` section 9). Built by Zulkifli, reviewed
by Mei Ling. Synthetic data only. Not production-approved.

## What changes for staff

- **Receiving a transfer.** For each dispatched line the receiver enters *accepted*, *short*, *damaged* and *extra*.
  The form starts with everything accepted; accepted + short + damaged must equal the dispatched quantity before
  Confirm receipt is enabled (exact to three decimals). Only the accepted quantity enters the receiving location.
- **Open differences.** Every non-zero short, damaged or extra quantity appears under the transfer as an open
  difference. A CA Supervisor, Finance Officer or Director at the receiving branch who did **not** receive the
  transfer resolves each one, choosing an outcome and entering a delivery note, inspection log or other reference:

  | Difference | Outcomes | Stock effect |
  |---|---|---|
  | Short | Lost in transit / Arrived later | none / adds the quantity to the receiving location |
  | Damaged | Written off / Usable after inspection | none / adds the quantity to the receiving location |
  | Extra | Returned to the sending location / Kept and brought in by a governed stock count | none / none (use Stock count) |

- The receiver sees "You received this transfer, so a different … must resolve it". Resolutions are permanent.

## Design

- Existing permissions only: receive `inventory.transfers.receive.branch` (CA, CA Supervisor); resolve
  `inventory.variances.approve.branch` (CA Supervisor, Finance Officer, Director). Owner decision 2026-11-05: never
  the receiver. The dispatcher is **not** excluded (see open choice below).
- Ledger: all credits use the existing `InventoryMovementService::recordTransferReceipt`, still called only from
  `StockRequestService`. The writer allowlist test is unchanged and green. No new movement type; Stock movement
  summary and Inventory movement history already classify `transfer_receipt` as transferred.
- Evidence (migration `2026_11_05_000100_create_transfer_receipt_evidence`): `inventory_transfer_receipt_lines`
  (one per dispatched line), `inventory_transfer_differences`, `inventory_transfer_difference_resolutions` and a
  nullable `inventory_stock_requests.receive_payload_hash`. All three tables are append-only (UPDATE, DELETE and
  TRUNCATE refused). PostgreSQL also checks: accepted + short + damaged = dispatched; a movement exists exactly when
  accepted > 0 and matches it; each difference equals its receipt-line quantity; one difference row per non-zero
  quantity (deferred); one resolution per difference, equal to the whole quantity, outcome valid for its kind,
  resolver different from the receiver, reference present, credit movement present exactly for the two adding
  outcomes and matching; every new `transfer_receipt` movement has receipt-line or resolution evidence (deferred).
- Concurrency: receipt and resolution lock the Stock Request row first (same order as dispatch), check
  `lock_version`, and use a retry key with a payload fingerprint; a same-key replay returns the saved result, a
  same-key different payload is refused. The UI keeps one key per open form so a resend replays.
- Audit: `inventory.stock_request.received` (unchanged), `inventory.transfer_difference.recorded`
  (`difference_count`) and `inventory.transfer_difference.resolved` (`difference_id`, `resolution_id`, `kind`,
  `outcome`). No names, quantities or reference text. A failed audit rolls everything back (503).
- Backward compatibility: a receive call without `lines` still accepts the full dispatched quantity, as before, and
  now also writes receipt-line evidence. Direct one-step transfers are unchanged (no in-transit stage).

## Tests

- `tests/Feature/Inventory/TransferShortageTest.php` (15 tests): exact catalogue roles for receive and resolve with
  every catalogue role attempting both; full, legacy and partial receipts; unreconciled, imprecise, negative,
  scientific-notation and incomplete receipts refused atomically; receipt and resolution retry keys and stale
  versions; every outcome's stock effect and reference rule; receiver self-resolution refused in the domain and
  PostgreSQL; fresh revocation, ended assignment, inactive staff, foreign branch and foreign organisation; audit
  failure rollback for receipt and resolution; append-only and evidence triggers; directory projection without
  names; HTTP permission and redirect; full before/after snapshot of every unrelated table.
- `tests/Feature/Inventory/PostgresTransferShortageTest.php`: separate connections on a disposable PostgreSQL
  database — two concurrent receipts (one wins, the other refused) and two independent approvers resolving the
  same difference (one credit, one resolution, one audit; stock conserved).
- `tests/Frontend/stk6-transfer-shortages.test.mjs` (6 tests): exact BigInt arithmetic, refusal messages, no float
  parsing, component wiring and retry keys.
- No existing assertion was changed.

## Known limits

- A difference is resolved whole, once; no partial resolution.
- Extra stock never enters stock through a transfer; keep it through a governed stock count.
- An item that arrived but was never on the dispatch is not recorded here.
- Receiving without lines (API only) still means "everything arrived". The screen always sends lines.
- The line's `received_quantity` stays the quantity accepted at receipt; later credits appear as their own
  movements and resolutions.
- PHPStan is not available in the cloud session; CI runs it.

## Owner decision at ship (2026-11-05)

The person who **dispatched** a transfer may resolve its differences; only the receiver is barred. Kept as built.
