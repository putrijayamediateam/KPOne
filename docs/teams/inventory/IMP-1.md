# IMP-1: catalogue import (one-off, console only)

Owner decision, 25 Nov 2026 (TEAM.md decision 51). Synthetic data only until the owner's go-live gates are closed.

## Command

```bash
php artisan inventory:import-catalogue catalogue.csv \
    --branch-prices=branch_prices.csv \
    --actor=<named administrator email> \
    --branch=PUCHONG \
    --dry-run
```

Run with `--dry-run` first: it checks both files, does every write, rolls it all back and prints the counts. Drop
`--dry-run` to import. A file with any problem is refused whole, with every problem listed; nothing is saved.

## catalogue.csv

UTF-8, first row exactly:

```
code,display_name,generic_name,strength,dosage_form,category,sku_code,barcode,purchase_unit,pack_size,stock_unit,dispensing_unit,controlled,hq_self_pay_rm,hq_panel_rm
```

- `code`, `display_name`, `purchase_unit`, `pack_size`, `stock_unit`, `dispensing_unit` are required.
- `stock_unit` must equal `dispensing_unit` (STK-1), for example `tablet`.
- `sku_code` blank means the medicine code; `barcode` is optional and must be new.
- `controlled` is `yes`, `no` or blank.
- `hq_self_pay_rm` is ringgit with up to 2 decimals (`12.50`), or blank for no price yet.
- `hq_panel_rm` must be blank: Panel prices change only by proposal and approval.

## branch_prices.csv (optional)

```
code,branch_code,self_pay_rm
```

One branch Self-pay price per medicine and branch; the medicine can be in the catalogue file or already in KPOne. Panel
patients use the HQ Panel prices.

## After the import

1. Pilot readiness: verify each SKU's base unit.
2. Opening stock per branch with the STK-35 opening import (with unit cost), then the STK-1 sign-off by someone else.
