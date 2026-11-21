# DSP-24: dispense a whole bottle of a liquid medicine (design note)

Status: **draft for Hakim's review and Daniel's look.** Not built. Classic only (V2 retired 19 Nov). Owner decision 17
of 20-21 Nov 2026 (`docs/decisions/2026-11-20-finance-inventory-proposals.md`): "Unit conversion per item (for
example 1 bottle = 60 ml), set once and checked by a second person." Owner answer 14 = A: the pilot item is
Paracetamol syrup. Synthetic data only.

The conversion itself (set once, checked blind by a second person, versioned) is Inventory's STK-52 (Zulkifli, rev 2,
`docs/teams/inventory/STK-52-DESIGN.md`). DSP-24 is only the Dispensary side: the CA dispenses in bottles, and stock
still moves in ml.

## 1. What changes for people

- Today a CA dispensing Paracetamol syrup types the ml (for example 120) and picks a batch with "ml on hand".
- With a **checked** STK-52 conversion for the SKU (for example 1 bottle = 60 ml), the line's Quantity box gets a
  unit choice: **Bottles** or **ml**.
  - Bottles is chosen by default when a checked conversion exists; the CA types whole bottles (1 to 1,000).
  - Beside the box, a muted line says what it means: "2 bottles = 120 ml".
  - The batch picker still shows ml on hand, with the bottles in brackets when they divide evenly
    ("180 ml on hand (3 bottles)").
- Without a checked conversion, nothing changes: the line stays in ml and no unit choice is shown.
- The drug label prints the bottles and the ml (section 5). Billing, returns and stock reports stay in ml.

## 2. Scope

- Consultation and OTC lines at Dispensary, for SKUs whose STK-52 conversion is checked.
- Whole bottles only. Part of a bottle is typed in ml, as today.
- No change to the doctor's order: the doctor still orders in the medicine's order unit. The "Doctor ordered" line
  stays as it is; the CA's bottles show beside it.
- Not in DSP-24: receiving, openings and reorder levels in bottles (STK-52's known limit, a later Inventory slice);
  bottles on the Completed page's returns (returns stay in ml).

## 3. Data

- `dispensary_items` gains two nullable columns:
  - `pack_conversion_id`, a foreign key to the exact STK-52 conversion version used;
  - `packs_dispensed`, the whole number of bottles the CA typed.
- Both are empty for a line dispensed in ml. When `packs_dispensed` is set:
  - `pack_conversion_id` must be set too;
  - `quantity_dispensed` must equal `packs_dispensed × base_per_pack` (a PostgreSQL check through a trigger that
    reads the conversion row).
- Allocations, stock movements and billing keep reading `quantity_dispensed` in ml. They see no new field.
- Migrations from `2026_11_06_200800` (after DSP-23's `200700`).

## 4. Saving a line (the STK-1 rules stay)

Zulkifli's conditions, all kept:

- The read uses `PackConversionReader::checkedForShare()` inside the line save. Lock order: case → handoff → lines →
  SKU (FOR SHARE, through `checkedForShare`) → batch. That matches the Dispensary order and Complete.
- The ml come from `toBase($conversion, $packs)` (whole-number maths), then
  `StockUnitQuantity::base($qty, $baseUnit, $baseUnit)`.
- `assertUnambiguous` is called with the base unit only, never "bottle". The SKU's `dispensing_unit` stays "ml".
- The page sends the conversion's `publicId` and version it showed. If the checked version changed in between (a new
  version was checked, or the conversion was withdrawn), the save is refused: "The bottle size for this medicine
  changed. Reload the page." Nothing is saved.
- `editItem`, `addItem` and the legacy `updateItem` share one helper, so all three paths follow the same rules.
- Complete and DSP-23's Verify re-check that each line's `pack_conversion_id` is still the SKU's checked version. If
  it is not, they refuse with the same message; the CA saves the line again.

## 5. The drug label

- The label's QTY cell holds 11 cells ("QTY: " plus 6 characters), so "1 bottle (60 ml)" does not fit there.
- **Proposal for Daniel:**
  - With bottles, the QTY cell shows the bottles in short form, "QTY: 2 btl".
  - A new labelled row under the directions says "QUANTITY: 2 BOTTLES (120 ML)".
  - The new row counts towards the 13-row limit; a label over the limit is blocked, never cut, as today.
- Without bottles, the label is byte-identical to today.
- The preview token covers the conversion version, so a label printed before a conversion change is retired.

## 6. Permissions and audit

- No new permission. Saving uses the existing `dispensary.update.branch`.
- The existing line-save audit row gains `packs_dispensed` and `pack_conversion_id` (ids and numbers only).

## 7. Screens (Daniel's look before the build)

- **Quantity box:**
  - a two-option unit choice ("Bottles" / "ml"), 44 px below 1024 px;
  - the box takes whole numbers when Bottles is chosen;
  - the "= 120 ml" line sits under it in muted text and is announced with the box (`aria-describedby`).
- **Batch picker:** ml on hand, with the bottles in brackets only when they divide evenly.
- **Label:** see section 5.

## 8. Tests

- Feature tests:
  - 2 bottles save as 120.000 ml with the conversion id and the packs;
  - the stock movement at Complete is 120.000 ml;
  - billing reads ml;
  - no checked conversion means no unit choice, and bottles posted anyway are refused;
  - a stale conversion version is refused at save and at Complete or Verify;
  - 0 or 1,001 bottles, or a fraction, is refused;
  - the label row is "QUANTITY: 2 BOTTLES (120 ML)" and the QTY cell is "2 btl";
  - a label that goes over 13 rows is blocked;
  - an ml-only label is byte-identical to today;
  - OTC and consultation lines both work.
- PostgreSQL:
  - the packs-times-size check refuses a mismatched row;
  - a two-process race between a line save and a new STK-52 version being checked, so the save sees either the old or
    the new version, never a mix (in the serial Contention suite).
- Frontend tests for the unit choice, the conversion line and the label row.
- No existing assertion is expected to change: lines without bottles behave exactly as today.

## 9. Questions for the owner

a. **Price.** Billing stays per ml, so 2 bottles of 60 ml bill as 120 ml × the per-ml price. Should the syrup instead
   be priced per bottle? A per-bottle price needs a Catalogue and Billing change outside DSP-24. Recommended: keep per
   ml for the pilot.
b. **Default unit.** With a checked conversion, should Bottles be the default? Recommended: yes, because a whole
   bottle is the usual supply for a syrup.
c. **Label.** Section 5: short "2 btl" in the QTY cell plus a "QUANTITY: 2 BOTTLES (120 ML)" row. Recommended: yes,
   with Daniel's wording.
