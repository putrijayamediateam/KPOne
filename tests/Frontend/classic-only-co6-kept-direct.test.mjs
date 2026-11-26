import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Classic only, CO-6: the Classic half of stk28-stock-value.test.mjs ('Classic and the new layout show the same report
// from the same props'), kept when the V2 Stock value page was removed. Assertions copied unchanged; the V2 registry
// and props-equality lines are dropped.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Inventory/StockValue.vue');

test('STK-28: the Classic Stock value page keeps its report route, journal download, banner, tabs and page size', () => {
    // Classic no longer asks the Director to turn on the new layout.
    assert.doesNotMatch(classic, /\/spaces\/preference|Turn on the new layout/);
    assert.match(classic, /defineProps<\{ report: Report \}>\(\)/);
    assert.match(classic, /router\.get\(\s*'\/inventory\/stock-value'/);
    assert.equal(
        (classic.match(/\/inventory\/stock-value\/journal/g) ?? []).length,
        1,
    );
    // The banner shows once, above the summary (REL-STK, Daniel).
    const classicTemplate = classic.slice(classic.indexOf('<template>'));
    assert.ok(
        (classicTemplate.match(/\{\{ report\.banner \}\}/g) ?? []).length === 1,
    );
    assert.ok(
        classicTemplate.indexOf('valuation-banner') <
            classicTemplate.indexOf('valuation-summary'),
    );

    // The same four tabs and 25 rows a page; nothing exported beyond the CSV.
    for (const label of [
        'Stock value',
        'Cost and margin',
        'Losses',
        'How this is counted',
    ]) {
        assert.match(classic, new RegExp(`label: '${label}'`));
    }

    assert.match(classic, /const PAGE_SIZE = 25;/);
    assert.doesNotMatch(classic, /\.xlsx|\.pdf/i);
    // The Stock value page keeps its quantity wording from the shared Classic library (CO-3).
    assert.match(classic, /from '@\/lib\/inventory-words'/);
});

// Direct Classic pins for logic that was pinned only through equality with the deleted V2 copies (Nadiah's review).
// Every string below is read from the Classic file today.
test('Classic Inventory home keeps its route, query keys, tab and filter navigation and hold label rule', () => {
    const home = read('resources/js/pages/Inventory/Index.vue');
    const flat = home.replace(/\s+/g, ' ');

    assert.ok(home.includes("'inventory.controlled_register.view.branch'"));
    assert.match(
        flat,
        /router\.get\( '\/inventory', \{ tab: props\.inventory\.tab,/,
    );
    const query = flat.slice(flat.indexOf("router.get( '/inventory',"));
    let at = 0;

    for (const key of [
        'tab',
        'search',
        'location',
        'status',
        'movement_type',
        'date_from',
        'date_to',
        'batch',
    ]) {
        const next = query.indexOf(`${key}:`, at);
        assert.ok(
            next > at || (at === 0 && next >= 0),
            `query key ${key} in order`,
        );
        at = next;
    }

    assert.ok(flat.includes('preserveState: true, replace: true,'));
    assert.ok(
        home.includes(
            'const changeTab = (tab: string) => visit({ tab, page: 1 });',
        ),
    );
    assert.ok(home.includes('const applyFilters = () => visit({ page: 1 });'));
    assert.equal(
        (
            [
                home,
                read('resources/js/components/inventory/StockOnHandList.vue'),
                read('resources/js/components/inventory/StockBatchList.vue'),
            ]
                .join('\n')
                .match(/formatInventoryHoldLabel\(/g) ?? []
        ).length >= 2,
        true,
    );
});

test('Classic Inventory pages keep their routes', () => {
    for (const [file, pieces] of [
        [
            'resources/js/pages/Inventory/SupplierInvoices.vue',
            [
                "'/inventory/supplier-invoices'",
                '`/inventory/supplier-invoices/${invoice.publicId}/${decision.kind}`',
            ],
        ],
        [
            'resources/js/pages/Inventory/SupplierReturns.vue',
            [
                "'/inventory/supplier-returns'",
                "'/inventory/supplier-return-groups'",
            ],
        ],
        [
            'resources/js/pages/Inventory/Recalls.vue',
            ["'/inventory/recalls'", '`/inventory/recalls/batches?sku='],
        ],
        [
            'resources/js/pages/Inventory/OpeningImport.vue',
            [
                "'/inventory/opening-import/check'",
                "'/inventory/opening-import'",
            ],
        ],
        [
            'resources/js/pages/Inventory/PurchaseRequests.vue',
            [
                "'/inventory/purchase-requests'",
                '`/inventory/purchase-requests/${request.publicId}/convert`',
                '`/inventory/purchase-requests/${request.publicId}/reject`',
                '`/inventory/purchase-requests/${request.publicId}/withdraw`',
                'onMounted(',
            ],
        ],
        [
            'resources/js/components/inventory/InventoryOperationsPanel.vue',
            ['watch(() => [reorder.location, reorder.sku], loadReorderLevel);'],
        ],
        [
            'resources/js/components/inventory/InventoryReferenceDataPanel.vue',
            [
                "'/inventory/opening-balances'",
                "'/inventory-references/items'",
                "'/inventory-references/skus'",
                "'/inventory-references/locations'",
                "'/inventory-references/batches'",
                "'/inventory-references/mappings'",
            ],
        ],
    ]) {
        const source = read(file);

        for (const piece of pieces) {
            assert.ok(source.includes(piece), `${file}: ${piece}`);
        }
    }
});

test('Classic Stock value keeps its journal month rule, day limit, refusal wording and required dates', () => {
    assert.ok(
        classic.includes(
            'if (!/^\\d{4}-\\d{2}-01$/.test(f) || f.slice(0, 7) !== t.slice(0, 7)) {',
        ),
    );
    assert.ok(
        classic.includes(
            '`Choose ${props.report.filters.maxDays} days or fewer.`',
        ),
    );
    assert.ok(
        classic.includes(
            "'The journal summary could not be prepared. Try again.'",
        ),
    );
    assert.ok((classic.match(/^\s+required$/gm) ?? []).length >= 2);
    assert.match(classic, /<template v-if="journalMonth">/);
});
