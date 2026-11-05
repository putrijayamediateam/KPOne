# STK-9 — barcode scanning on Inventory screens

Date: 2026-11-05. Branch `feature/stk-9-barcode-scanning` from `main` at `2974293`. Owner authorisation: Inventory
team backlog item 4 (relayed by LUCY, the coordinator, which counts as the owner's own under TEAM.md section 6
item 16), with the owner's own choices of 2026-11-05 recorded in section 6, items 22 to 24. Built by Zulkifli,
reviewed by Mei Ling, designed by Wei Jie. Synthetic data only. Not production-approved.

## What changes for staff

- A **"Scan or type barcode"** box appears on four Inventory screens:
  - **Stock count and Write-off (governed):** a scan fills the search with the SKU's code and shows its stock.
  - **Treatment room usage:** a scan fills the stock search only (not the entries search).
  - **Supplier returns:** a scan fills the search.
  - **Verified receiving:** a scan finds the order line for that SKU, highlights it with a "Scanned" badge and
    scrolls to it. The cursor stays in the scan box, so the next scan can never type into a quantity field and
    submit the receipt. A SKU that is not on the order is refused with "… is not on this receiving order. Nothing was
    changed."
- **Three ways to enter a code:**
  - a handheld USB or Bluetooth scanner, which types the code and presses Enter;
  - typing the code;
  - **Use camera** on a phone or tablet whose browser has a built-in barcode reader (Android Chrome).

  On iPhone or iPad, or on a page that is not https, the camera button explains that camera scanning isn't
  available and to use a scanner or type the code. No barcode library is added (owner decision).
- **A scan only finds.** It never ticks, fills a quantity, saves or posts anything, and the batch is still picked by
  hand. The scanner's Enter is caught by the scan box, so it never submits the surrounding form.
- **One barcode, one SKU (owner decision).** Saving a SKU with a barcode already used by another SKU in the
  organisation is refused, naming that SKU. Codes compare trimmed and ignoring case.
- **Existing duplicate barcodes** are not changed by the migration. They are listed on **Inventory → Pilot
  readiness** under "Barcodes shared by more than one SKU". A Director, CA Supervisor or Administrator (existing
  `inventory.references.manage.organisation`) can give each SKU its own code or clear the wrong one right there,
  through the existing governed SKU update (one-code-one-SKU check, audit `inventory_sku.updated`). Until then the
  scan box says the code is on more than one SKU and never picks one.
- In the **Medicine Catalogue**, a duplicate barcode is refused with the message shown under the barcode field.
- An **inactive SKU** is reported as inactive, not as found stock.

## Design

- **Lookup:** `GET /inventory/scan?code=…` (`InventoryCodeLookupService::find`).
  - Needs `inventory.view.branch` and a current assignment at the active branch.
  - Matches the organisation's SKU barcodes first, then an exact SKU code.
  - Returns `found` (SKU code, item name, unit, active), `not_found` or `shared`.
  - Read only, with no audit row (reference data only). `sensitive.no-store`, `throttle:120,1`.
  - The Dispensary team can reuse it for scanning at dispensing.
- **Uniqueness:**
  - `InventoryReferenceAdministrationService` refuses a duplicate on SKU create and update, under the existing
    organisation lock.
  - Migration `2026_11_08_000100_guard_unique_sku_barcodes` adds a PostgreSQL trigger. It refuses (23505) a new or
    changed barcode that another SKU in the organisation holds, using a transaction advisory lock per organisation
    and code so concurrent saves cannot both pass.
  - Editing other fields of a SKU that already shares a legacy code is still allowed.
  - The migration also adds a lookup index and changes no data. Its `down()` removes the trigger, function and
    index.
- **Barcode fix route:** `POST /inventory/readiness/barcode/{sku}`, gated by
  `inventory.references.manage.organisation`, `sensitive.no-store` and `throttle:30,1`. It calls
  `InventoryReferenceAdministrationService::updateSku`. The Medicine Catalogue service relabels the `barcode`
  refusal as `sku.barcode` for its form.
- **Normalisation:** SQL uses `upper(trim(…))`, which behaves the same on SQLite and PostgreSQL.
- **No new permission, movement type or ledger writer.** Existing databases need `php artisan migrate`.
- **UI:**
  - `resources/js/components/inventory/ScanInput.vue` and `resources/js/lib/scan.ts`.
  - The camera uses `BarcodeDetector` with `getUserMedia({ facingMode: 'environment' })`. It stops on a read,
    Cancel, closing the dialog, hiding the tab and leaving the page.
  - The camera ignores a double tap. A stream that arrives after the page has left is stopped. One read finishes
    before the next starts. A detector error shows a message.
  - A repeat of the same code within one second is ignored. After each lookup the box is selected, so the next
    scan replaces the code instead of adding to it.
  - Separate polite and alert live regions announce the result.
  - After a scan-triggered search reloads the page, focus returns to the scan box, so the next scanner read lands
    there. This uses browser session storage as a convenience only.

## Tests

- `tests/Feature/Inventory/BarcodeScanTest.php` (6 tests):
  - a lookup by barcode (spacing and case, a tab refused) and by SKU code, plus not found;
  - one code, one SKU in the domain, in PostgreSQL (23505) and per organisation;
  - a legacy shared code that is never resolved, is listed on Pilot readiness, and still lets the SKU's other
    fields be edited; the fix from Pilot readiness (permission, duplicate refused, clear, audit);
  - an inactive SKU, and the Medicine Catalogue error under `sku.barcode`;
  - route roles, validation, an ended assignment, and a foreign organisation;
  - the lookup changes no table.
- `tests/Frontend/stk9-barcode-scanning.test.mjs` (7 tests):
  - code cleaning;
  - wording;
  - camera support (built-in only, needs https);
  - repeat reads;
  - Enter caught and the camera stopped;
  - placement on the four screens;
  - the Readiness section.
- No existing assertion changed.

## Known limits

- The batch is still chosen by hand. Supplier GS1 barcodes that carry the lot and expiry are not read.
- Camera scanning needs a browser with a built-in barcode reader and an https page. iPhone and iPad use a scanner
  or typing.
- Dispensing has no scanning yet (the Dispensary team's piece).
- A typed exact SKU code also matches.

## Independent review (Mei Ling)

**One CI blocker and three major findings, all fixed:**
- **PHPStan:** a missing `@param` and the list shapes.
- **Receiving:** a scan moved the cursor into a quantity field, so the next scan's Enter could submit the receipt.
  The cursor now stays in the scan box.
- **No working fix screen:** "Fix in Stock setup" pointed to a page with no barcode field. The fix is now on Pilot
  readiness itself.
- **Silent catalogue refusal:** the Medicine Catalogue refusal was invisible. It is now shown under its barcode field.

**Minor findings, fixed:**
- the camera stream could leak;
- camera start-up could fail silently;
- a new scan appended to a failed code;
- an inactive SKU was reported as found;
- shared codes were listed in upper case;
- `btrim` failed on SQLite;
- one test trimmed before calling the service.

**Accepted:**
- a barcode match wins over another SKU's code;
- the search pages use a substring search;
- an order with the same SKU on two lines highlights the first;
- a raw-SQL duplicate reaching the trigger shows a 500 page (the governed path refuses it first).
