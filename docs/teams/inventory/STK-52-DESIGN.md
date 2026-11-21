# STK-52: A checked pack conversion per SKU (for dispensing in whole packs)

Owner item 17 (20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`): unit conversion per item.
Owner answer 14 = A (21 Nov): the pilot is **Paracetamol syrup, bottle to ml**, built with Dispensary & OTC (Aisyah,
DSP-24). This slice is the Inventory half: the conversion record, who sets and checks it, and a read service. DSP-24
(Dispensary's note) uses it to let a CA dispense in whole bottles. **Classic layout only.** Synthetic data only.
Revision 3: the owner's answer on who checks (a new narrow permission for CA Supervisor and Director). Revision 2, after Mei Ling's review (OK with changes, 21 Nov): STK-1's untouched path spelled out, the old pack fields,
one waiting version and withdraw, a blind check, the stale-page guard, the read service's lock and cap, the ml-entry
limit, and more race and unit tests. Agreed in outline with Aisyah on 21 Nov.

## The problem

STK-1 keeps every quantity in exact thousandths of one verified base stock unit, and refuses a quantity in any other
unit (`StockUnitQuantity::base`: "Conversions are not inferred"). If syrup stock were kept in bottles, a 5 ml dose
would be 0.08333… bottle, which cannot be exact.

## The shape (agreed with Aisyah)

1. **The base stock unit is the smallest unit (ml).** Opening balances, counts, movements, reorder levels, the stock
   value and every report stay exact in ml. STK-1 does not change.
2. **A pack conversion says how many base units one pack holds:** "1 bottle = 60 ml", a whole positive number.
3. **Dispensary converts before it allocates.** A CA who dispenses 1 bottle gets 60 ml allocated and moved; the
   movement is in ml and STK-1's rule is unchanged, because what reaches it is already in the base unit. Dispensary
   records which conversion version it used on the line, so a later change never rewrites history. (That part, the
   label and the screens at Dispensary are DSP-24.)

**STK-1 stays exactly as it is.** DSP-24 calls `assertUnambiguous` with no input unit (or the base unit), never
"bottle", and passes `toBase()`'s result through `StockUnitQuantity::base($quantity, $baseUnit, $baseUnit)`. The SKU's
`dispensing_unit` stays `ml`. A proposal needs the SKU's verified base unit (an `inventory_unit_contracts` row), and
that row already counts as evidence in `skuHasEvidence()`, so the SKU's units are locked before any conversion exists.

**The old pack fields are not this.** Every SKU already carries `pack_size`, `purchase_unit` and `unit_conversion`:
unverified legacy fields, among the locked unit fields. STK-52 never reads them or copies from them. When they disagree
with the checked conversion, the edit screen shows a muted note ("The legacy pack fields say … ; Dispensary uses the
checked conversion above.").

## Data (two append-only tables)

- `inventory_pack_conversions`: one row per **version** of a SKU's conversion.
  - Fields: `public_id`, `organisation_id`, `inventory_sku_id`, `version` (1, 2, … per SKU), `pack_unit` (text, at most
    40 characters, for example "bottle"), `base_per_pack` (integer 2 to 100,000), `base_unit` (copied from the SKU when
    proposed), `proposed_by_user_id`, `proposed_at`, `idempotency_key` and `request_hash`.
  - A version's values never change.
- `inventory_pack_conversion_checks`: at most one check per version.
  - Fields: `outcome` (`checked`, `refused` or `withdrawn`), a reason code when refused (`wrong_count`, `wrong_unit`,
    `other`), the checker's own typed count (`checked_base_per_pack`, for the blind check below),
    `checked_by_user_id`, `checked_at`, `idempotency_key` and `request_hash`.
- **The current conversion** is the highest version with a `checked` outcome. A newer version waiting for its check
  does not replace it; the older checked version stays in force until then. A refused or withdrawn version never takes
  effect.
- **At most one version waits for a check per SKU**: a second proposal is refused (service, under the SKU lock, and a
  PostgreSQL trigger) until the waiting one is checked, refused or withdrawn. Only its proposer may withdraw it.
- **No empty changes:** a proposal equal to the current conversion is refused, and so is "no pack conversion" when none
  is in force.
- **To stop using conversions**, propose a "no pack conversion" version (`pack_unit` and `base_per_pack` empty). Once
  checked, the SKU has no current conversion. Nothing is deleted.
- **PostgreSQL:**
  - Both tables refuse update, delete and truncate.
  - The checker is never the proposer, and the check's organisation and checker both match the version's organisation
    (insert trigger). The checker's permission is checked fresh in the service under lock; PostgreSQL enforces the
    person rules, not role membership. A `withdrawn` row must be recorded by the proposer.
  - One check per version (unique index).
  - Versions are gapless per SKU.
  - An explicit CHECK: `(pack_unit IS NULL AND base_per_pack IS NULL) OR (pack_unit IS NOT NULL AND base_per_pack
    BETWEEN 2 AND 100000)`.
  - `pack_unit <> base_unit` (true for a set value; SQL passes it when `pack_unit` is NULL, which is the intended
    "no pack conversion" case).

## Rules

- **Who (owner, 21 Nov: "A for the bottle check"):**
  - **Set** (propose, or withdraw your own proposal): anyone holding the existing
    `inventory.references.manage.organisation`.
  - **Check** (the blind re-entry): only a holder of the **new** permission `inventory.pack_conversions.verify.organisation`,
    granted to `ca_supervisor` and `director` only.
  - The checker is never the setter (service and PostgreSQL).
  - The new permission joins `PermissionCatalogue`, is not people or access authority, and is granted by one additive
    migration (`firstOrCreate`, give only what is missing, a missing role is logged and skipped, never revoked, `down()`
    a no-op).
- **The check is a blind re-entry** (as in STK-5): the checker types "how many <base unit> in one <pack unit>" without
  seeing the proposed number. A different number is recorded as `refused` with `wrong_count`, and the proposal never
  takes effect. This is the one number that decides how much syrup a patient takes home, so a click is not enough.
- **Before a proposal:** the SKU's base unit must be verified (an `inventory_unit_contracts` row matching its stock
  unit), so a conversion never rests on an unverified unit.
- **Every write:**
  - a fresh permission check under the organisation lock, then the SKU row lock;
  - `DB::transaction`;
  - a stale-page guard: a proposal carries the latest conversion version number for the SKU (0 when none), and a check
    or withdraw carries the version's `public_id`, which must still be the waiting version (conversion writes do not
    change the SKU's own `lock_version`);
  - an idempotency key with the keyed request hash;
  - audit `inventory.pack_conversion.proposed` / `.checked` / `.refused` with ids, the version and the outcome code only.
- **Base unit and the pilot SKU:** STK-1 locks a SKU's units once it has batches, stock, a medicine link or dispensing
  records. The pilot therefore uses a **fresh** syrup SKU with `ml` as its verified base unit, opened and signed off in
  ml. No existing SKU is re-united.

## Read service (for Dispensary)

`App\Domain\Organisation\Inventory\Services\PackConversionReader`:

- `checkedFor(int $organisationId, int $skuId): ?CheckedPackConversion` returns the current checked conversion, or null.
  It also returns null for an inactive SKU, or when the conversion's `base_unit` no longer matches the SKU's stock unit.
  `CheckedPackConversion` is a small read-only value: `id` and `publicId` (so a dispensing line can store a foreign key
  to the exact version), `skuId`, `version`, `packUnit`, `basePerPack` (int) and `baseUnit`.
- `toBase(CheckedPackConversion $conversion, int $packs): string`: whole packs only, from 1 to 1,000, returned as an
  STK-1 base quantity in thousandths (for example 2 bottles → `"120.000"`), with whole-number maths only (the cap keeps
  packs × base per pack × 1000 far inside 64 bits).
- `checkedForShare(int $organisationId, int $skuId): ?CheckedPackConversion`: the same, read after locking the SKU row
  `FOR SHARE`, inside Dispensary's save transaction. Every conversion write takes the SKU row `FOR UPDATE`, so a check
  landing at the same moment is either fully seen or not seen. **Lock order (agreed with DSP-24):** case, then line,
  then SKU, then batch.

## Screen (Classic only)

- **The SKU edit screen (STK-14) gains a "Pack conversion" block:**
  - The current conversion: "1 bottle = 60 ml · version 2 · checked by <name> on <date>", or "None".
  - A version waiting: "Waiting for a check: 1 bottle = 50 ml, proposed by <name>", with **Check** and **Refuse** for
    anyone allowed except the proposer.
  - **Propose a change**: pack unit and "how many <base unit> in one pack".
  - Plain help: "Stock stays counted in <base unit>. This only lets Dispensary hand out whole packs."
- **Pilot readiness** gains a line for any SKU with a conversion waiting for a check.
- Names follow the staff-visibility rule ("Staff member" otherwise).

## Known limits

- Receiving, opening balances, counts and reorder levels are typed in the base unit until a later slice: 10 bottles is
  entered as 600 ml. The Classic help text says so, and the stock admission procedure must too.
- The stock value (WAC), stock movement summary, reorder suggestions and counts are unchanged and stay in ml.
- The two new tables are not added to `ai_read`.

## Tests

- **The new permission:** exactly `ca_supervisor` and `director` hold it (catalogue and migration); the migration is
  replay-safe and never revokes; a CA, an Administrator or a Finance Officer gets 403 on Check even when they hold the
  reference permission; the existing permission catalogue test that pins the exact permission list gains one line
  (listed for the owner before it is edited).
- **Propose and check:**
  - a proposal is refused without a verified base unit, a non-whole or out-of-range count, a pack unit equal to the
    base unit, a stale page, or no permission;
  - the checker can never be the proposer (service and a direct PostgreSQL insert);
  - a refused version never takes effect;
  - a newer version waiting leaves the older checked one in force;
  - "no pack conversion" clears it;
  - retries with the same key change nothing, and a different payload with the same key is refused.
- **Read service:** `checkedFor` follows the current checked version; `toBase` gives whole-number base quantities and
  refuses 0, negative and fractional packs.
- **PostgreSQL:** update, delete and truncate are refused; gapless versions; one check per version.
- **More rules:** a second proposal while one waits is refused; withdraw by the proposer only; no-op proposals are
  refused; the blind check refuses a mismatch as `wrong_count`; a check from another organisation is refused by a direct
  PostgreSQL insert; `checkedFor` is null for an inactive SKU or a base-unit mismatch; `toBase` refuses 0, a fraction
  and over 1,000; staff names follow the visibility rule; the Pilot readiness line appears for a waiting version; the
  migration's `down()`.
- **Race (serial Contention suite):** two proposals at once (gapless versions, one refused); two checkers on one version
  (one check); a check against a withdraw; a check against a reader using `checkedForShare` (it sees the old or the new
  version, never a mix).
- **Audit:** ids and codes only.
- **Classic frontend:** the block, the waiting state, Check and Refuse hidden from the proposer, and the help line.
- **Existing assertions:** adding a permission changes any test that pins the exact permission catalogue or a role's
  exact grants. Each is listed for the owner before and after, none is edited without approval, and the PR does not
  open until each is approved. Otherwise none expected to change. The Pilot readiness line may change that page's props; if an
  existing test pins them, the change is listed for the owner first and nothing is edited without approval.

## Permissions

- New: `inventory.pack_conversions.verify.organisation` for `ca_supervisor` and `director` (owner, 21 Nov).
- Unchanged: `inventory.references.manage.organisation` sets a conversion.

## Not in this slice

- Dispensing in packs, the label ("QTY: 1 bottle (60 ml)") and the CA's unit choice: DSP-24.
- Receiving and purchase orders in packs, and reorder levels shown in packs ("= N bottles"): later Inventory slices.
- More than one pack size per SKU, and any conversion between two non-base units.
