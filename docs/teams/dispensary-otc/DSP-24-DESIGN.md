# DSP-24: dispense a whole bottle of a liquid medicine (design note)

Status: **Hakim OK (21 Nov). Daniel OK with changes (21 Nov), applied below. Zulkifli confirmed sections 3 and 4
against STK-52 (built on feature/stk-52-pack-conversion, not merged).** Not built. **Depends on STK-52 rev 2 (Inventory): DSP-24 is built on top of it and merges only after
it.** Classic only (V2 retired 19 Nov). Owner decision 17
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
  - The unit starts as the one the doctor ordered in, which is ml for the syrup (Daniel, change 1). Someone typing
    "60" into a box that had quietly switched to Bottles would dispense 3,600 ml. The CA picks Bottles on purpose and
    types whole bottles (1 to 99).
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
  - `packs_dispensed`, the whole number of bottles the CA typed (1 to 99).
- Both are null for a line dispensed in ml. A PostgreSQL trigger on `dispensary_items` enforces:
  - `packs_dispensed` is null exactly when `pack_conversion_id` is null;
  - when set, `quantity_dispensed` equals `packs_dispensed × base_per_pack` of that conversion row;
  - the conversion belongs to the SKU of every allocation on the line (Hakim, must-fix 2). The item row is written
    before its allocations, so this one rule is a **deferred constraint trigger** (`DEFERRABLE INITIALLY DEFERRED`)
    on both `dispensary_items` and `dispensary_item_batch_allocations`, checked at commit, as DSP-7 does for its
    return movements. The other two rules stay an ordinary trigger on the item.
- **Every path that writes `quantity_dispensed` without bottles sets both columns to null** (Hakim, must-fix 3):
  - `removeItem`, which sets the quantity to 0;
  - the legacy `updateItem`, including partial and patient-declined saves;
  - any edit saved in ml.
  Restoring a removed line starts again from ml.
- STK-52 versions are immutable rows (confirmed by Zulkifli): both `inventory_pack_conversions` and
  `inventory_pack_conversion_outcomes` refuse update, delete and truncate. A new check is a new outcome row, and a new
  size is a new version row. The foreign key to `inventory_pack_conversions.id` is `restrictOnDelete`.
- The item trigger reads `inventory_pack_conversions.base_per_pack` and `inventory_sku_id`:
  - it refuses a "stop" version (`base_per_pack` and `pack_unit` both null);
  - it checks `packs_dispensed × base_per_pack = quantity_dispensed`;
  - it checks that `inventory_sku_id` is the line's mapped SKU.
- Allocations, stock movements and billing keep reading `quantity_dispensed` in ml. They see no new field.
- Migrations from `2026_11_06_200800` (after DSP-23's `200700`).

## 4. Saving a line (the STK-1 rules stay)

- **Lock order** (Hakim, must-fix 1): case → handoff → lines → allocations, locations and SKUs (FOR UPDATE, as today) →
  the conversion read → mappings and batches.
  - The conversion is read through `PackConversionReader::checkedForShare()` only **after** the SKU row is already
    locked FOR UPDATE: in `allocation()` at save, and in `lockedAllocations` at Complete, at DSP-23's Send and at
    Verify. A share lock taken under your own update lock costs nothing.
  - Reading the SKU FOR SHARE before locking it FOR UPDATE could deadlock two saves on the same syrup, so the note no
    longer does that.
  - `checkedForShare` locks the **SKU row** FOR SHARE (Zulkifli). Our transaction already holds that row FOR UPDATE, so
    the share lock adds nothing: no upgrade and no deadlock. Every STK-52 write takes the same SKU row FOR UPDATE, so a
    check cannot land between our read and our commit.
- The page sends the conversion's `publicId` and version. The server checks that:
  - the conversion is the **checked** version for the SKU of every allocation on the line;
  - `conversion.baseUnit === sku.stock_unit === item.unit_snapshot` (all "ml").
  Otherwise the save is refused and nothing is saved: "The bottle size for this medicine changed. Reload the page."
- The ml come from `toBase($conversion, $packs)` (whole-number maths), then
  `StockUnitQuantity::base($qty, $baseUnit, $baseUnit)`.
- `assertUnambiguous` is called with the base unit only, never "bottle". The SKU's `dispensing_unit` stays "ml".
- `editItem`, `addItem` and the legacy `updateItem` share one helper, so all three paths follow the same rules.
- **Batch picks in bottle mode:**
  - A single pick is filled automatically with the line's total ml.
  - Split picks are typed in ml, with a running total against the line's ml, so the existing "batches add up to the
    quantity" rule applies unchanged.
- **Complete, Send and Verify** re-check, inside `lockedAllocations`, that each line's `pack_conversion_id` still equals
  `checkedForShare(...)->id`. It returns null when the SKU has no conversion in use: a stop was checked, the SKU is
  inactive, or the base unit no longer matches.
  - The CA at Complete or Send sees "The bottle size for this medicine changed. Save the line again."
  - The doctor or supervisor at Verify, who cannot save a line, sees "The bottle size for this medicine changed. Send
    the case back so the CA can save the line again." That matches how DSP-23 handles a batch that is no longer
    dispensable.

## 5. The drug label

- The label's QTY cell holds 11 cells ("QTY: " plus 6 characters), so "1 bottle (60 ml)" does not fit there.
- **Proposal for Daniel:**
  - With bottles, the QTY cell shows the bottles in short form, in capitals like the rest of the label: "QTY: 2 BTL"
    (Daniel). The bottle cap is 99 (plenty for
    syrup), so the value always fits the 6 characters; if it ever doesn't, the label is blocked, never cut, as with
    DSP-10.
  - A new labelled row under the directions says "QUANTITY: 2 BOTTLES (120 ML)", or "QUANTITY: 1 BOTTLE (60 ML)" in
    the singular.
  - The new row counts towards the 13-row limit; a label over the limit is blocked, never cut, as today.
- Without bottles, the label is byte-identical to today.
- The preview token covers the conversion version, so a label printed before a conversion change is retired.

## 6. Permissions and audit

- No new permission. Saving uses the existing `dispensary.update.branch`.
- The existing line-save audit row gains `packs_dispensed` and `pack_conversion_id` (ids and numbers only).

## 7. Screens (Daniel's look: OK with changes, 21 Nov, applied)

- **Quantity box:**
  - A two-option unit choice ("Bottles" / "ml"), 44 px below 1024 px. It starts on the ordered unit (ml).
  - With Bottles, the helper says "Whole bottles only, 1 to 99." A fraction or an out-of-range number gets a line under
    the box, as Quantity does today.
  - A muted line under the box says what it means: "2 bottles = 120 ml". It is linked with `aria-describedby` and is a
    polite live region, so a screen reader hears it when the number changes.
  - If the amount differs from what the doctor ordered, a warning-token line (a dot and words, not red) adds: "Doctor
    ordered 60 ml. This is 120 ml." The CA can still save, as for quantity edits today. The line shows only when the
    numbers differ.
  - **Switching unit never silently keeps the number.**
    - When it converts exactly, it is converted ("120 ml" becomes "2 bottles").
    - Otherwise the box is cleared, and the muted line says "Unit changed. Enter the amount again."
- **Batch picker:** "180 ml on hand (3 bottles)" when the bottles divide evenly; only "170 ml on hand" otherwise.
- **The same quantity format everywhere the saved line is read** (Daniel):
  - "Prepared 2 bottles (120 ml)" in DSP-23's doctor panel;
  - the Completed page;
  - the Invoices page;
  - the Dispensary returns screen (returns are still recorded in ml, and the line shows its bottles beside the ml).
  These read `packs_dispensed` and the conversion's pack unit. Lines without bottles show as today.
- **Label:** see section 5.
- **Shots when built,** at 1440 and 390 px, light and dark, of every state above, including the doctor panel.

## 8. Tests

- Feature tests:
  - 2 bottles save as 120.000 ml with the conversion id and the packs;
  - the stock movement at Complete is 120.000 ml;
  - billing reads ml;
  - no checked conversion means no unit choice, and bottles posted anyway are refused;
  - a stale conversion version is refused at save and at Complete or Verify;
  - 0 or 100 bottles, or a fraction, is refused;
  - a conversion from another SKU is refused, at save and by the trigger;
  - save 2 bottles, Remove the line, restore it in ml: the two columns are cleared and the trigger accepts each step;
  - the legacy partial and patient-declined save clears both columns;
  - the Verify message for a changed size is the "Send the case back" one;
  - the label row is "QUANTITY: 2 BOTTLES (120 ML)" (and "1 BOTTLE (60 ML)" in the singular), and the QTY cell is
    "2 BTL";
  - a label that goes over 13 rows is blocked;
  - an ml-only label is byte-identical to today;
  - OTC and consultation lines both work.
- PostgreSQL:
  - the packs-times-size check refuses a mismatched row;
  - an allocation from another SKU inserted after a bottle line is saved is refused at commit;
  - a two-process race between a line save and a new STK-52 version being checked, so the save sees either the old or
    the new version, never a mix (in the serial Contention suite).
- Frontend tests for the unit choice, the conversion line and the label row.
- No existing assertion is expected to change: lines without bottles behave exactly as today.

## 9. Questions for the owner

a. **Price.** Billing stays per ml, so 2 bottles of 60 ml bill as 120 ml × the per-ml price. Should the syrup instead
   be priced per bottle? A per-bottle price needs a Catalogue and Billing change outside DSP-24. Recommended: keep per
   ml for the pilot.
b. **Default unit.** Daniel recommends starting on the ordered unit (ml), for safety, with Bottles picked on purpose.
   Recommended: follow Daniel.
c. **Label.** Section 5: "QTY: 2 BTL" in the QTY cell, plus a "QUANTITY: 2 BOTTLES (120 ML)" row. Recommended: yes,
   as Daniel worded it.
