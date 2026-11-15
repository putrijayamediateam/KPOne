import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Inventory/Index.vue');
const home = read('resources/js/composables/useInventoryHome.ts');
const page = read('resources/js/pages/Inventory/V2/Index.vue');
const registry = read('config/v2-pages.php');

const pairs = (source) =>
    [
        ...source.matchAll(
            /\{\s*value:\s*'([^']*)',\s*label:\s*'([^']*)'\s*\}/g,
        ),
    ].map((m) => `${m[1]}=${m[2]}`);
const block = (source, name) => {
    const start = source.indexOf(name);
    assert.ok(start >= 0, name);

    return source.slice(start, source.indexOf('];', start));
};

test('V2 Inventory home: the same 16 task links, in the same order, as the Classic desktop groups', () => {
    const desktop = classic.slice(
        classic.indexOf('lg:grid lg:grid-cols-3'),
        classic.indexOf('lg:hidden'),
    );
    const classicLinks = [
        ...desktop.matchAll(
            /href="([^"]+)"[\s\S]*?>\s*([^<]+?)\s*<\/ActionLink/g,
        ),
    ].map((m) => `${m[1]}=${m[2]}`);
    const v2Links = [
        ...block(home, 'inventoryTaskGroups = ').matchAll(
            /href:\s*'([^']+)',\s*label:\s*'([^']+)'/g,
        ),
    ].map((m) => `${m[1]}=${m[2]}`);

    assert.equal(classicLinks.length, 16);
    assert.deepEqual(v2Links, classicLinks);

    // The controlled register link needs the same permission in both.
    for (const source of [classic, home]) {
        assert.ok(
            source.includes("'inventory.controlled_register.view.branch'"),
        );
    }

    assert.match(
        home,
        /canViewControlledRegister\s*\?\s*\[\s*\{\s*href: '\/inventory\/controlled-register'/,
    );
});

test('V2 Inventory home: same route, query keys and tab rules as Classic', () => {
    const classicVisit = classic.slice(
        classic.indexOf('const visit ='),
        classic.indexOf('const changeTab'),
    );
    const v2Visit = home.slice(
        home.indexOf('const visit ='),
        home.indexOf('const changeTab'),
    );

    for (const source of [classicVisit, v2Visit]) {
        assert.match(source, /router\.get\(\s*'\/inventory'/);
        assert.match(source, /preserveState: true/);
        assert.match(source, /replace: true/);
    }

    const keys = (source) =>
        [
            ...source.matchAll(
                /^\s*(tab|search|location|status|movement_type|date_from|date_to|batch)[,:]/gm,
            ),
        ].map((m) => m[1]);
    assert.deepEqual(keys(v2Visit), keys(classicVisit));
    assert.deepEqual(keys(v2Visit), [
        'tab',
        'search',
        'location',
        'status',
        'movement_type',
        'date_from',
        'date_to',
        'batch',
    ]);

    // Status off on Movements, movement type and dates only on Movements, batch off on Stock.
    assert.match(v2Visit, /status:\s*tab === 'movements' \? undefined/);
    assert.match(v2Visit, /movement_type:\s*tab === 'movements'/);
    assert.match(v2Visit, /date_from:\s*tab === 'movements'/);
    assert.match(v2Visit, /date_to:\s*tab === 'movements'/);
    assert.match(v2Visit, /batch:\s*tab === 'stock' \? undefined/);

    for (const source of [classic, home]) {
        assert.match(source, /visit\(\{ tab, page: 1 \}\)/);
        assert.match(source, /applyFilters = \(\) => visit\(\{ page: 1 \}\)/);
    }
});

test('V2 Inventory home: same status and movement filter values as Classic, and the same tab values', () => {
    assert.deepEqual(
        pairs(block(home, 'inventoryStatusOptions = ')),
        pairs(block(classic, 'const statusOptions = ')),
    );
    assert.deepEqual(
        pairs(block(home, 'inventoryMovementOptions = ')),
        pairs(block(classic, 'const movementOptions = ')),
    );

    const tabValues = (source, name) =>
        [...block(source, name).matchAll(/value: '(\w+)'/g)].map((m) => m[1]);
    assert.deepEqual(
        tabValues(home, 'inventoryTabs = '),
        tabValues(classic, 'const tabs = '),
    );
});

test('V2 Inventory home: same props, the Classic hold label rule, and the Classic panels unchanged', () => {
    for (const prop of [
        'inventory:',
        'operations:',
        'receiptMemoryContext:',
        'referenceData:',
        'errors?:',
    ]) {
        assert.ok(page.includes(prop), `V2 prop ${prop}`);
        assert.ok(classic.includes(prop), `Classic prop ${prop}`);
    }

    assert.match(
        home,
        /formatInventoryHoldLabel\(formatInventoryReason\(row\.holdReason\)\)/,
    );
    // Page 2c: the Classic Operations panel is no longer on the V2 home; its parts are V2 sections.
    assert.ok(!page.includes('<InventoryOperationsPanel'));
    assert.match(page, /<ReorderSection\s+:operations="operations"/);
    assert.match(page, /<OlderRecordsSection :operations="operations" \/>/);
    assert.ok(!page.includes('<InventoryReferenceDataPanel'));
    assert.match(page, /<StockSetupSection\s+:reference-data="referenceData"/);
    assert.match(
        page,
        /:expected-branch-id="\s*operations\.expectedBranchId\s*"/,
    );
    assert.match(page, /:per-page="25"/);
});

test('V2 Inventory home: uses the V2 blocks, is registered, and Classic does not import V2', () => {
    for (const blockName of [
        'V2WorkLayout',
        'V2Tabs',
        'V2Toolbar',
        'V2Table',
        'V2Chip',
        'V2Pager',
        'V2DisclosureBar',
    ]) {
        assert.ok(page.includes(`components/v2/${blockName}.vue`), blockName);
    }

    assert.match(page, /PageFrame/);
    assert.match(page, /usePageCrumbs\(\(\) => \[\{ title: 'Inventory' \}\]\)/);
    assert.match(registry, /'Inventory\/Index',/);
    assert.ok(
        !classic.includes('components/v2') &&
            !classic.includes('useInventoryHome'),
    );
});

test('V2 Inventory home: Daniel V2 checklist points (phone filter sheet, cards below 1280, staff words)', () => {
    // A phone gets search plus one "Filters (n)" button that opens a sheet with every filter, Reset and Apply.
    assert.match(page, /Filters\{\{/);
    assert.match(page, /<V2Dialog[\s\S]*?title="Filters"[\s\S]*?Reset filters/);
    assert.match(page, /'flex-1 sm:hidden'/);
    // Tables turn into cards below 1280 px (the V2 sidebar is showing).
    assert.equal((page.match(/cards="xl"/g) ?? []).length, 3);
    // Movement types keep the Classic values, in sentence case.
    assert.match(page, /inventoryMovementOptions\.map/);
    assert.match(page, /:label="sentence\(row\.typeLabel\)"/);
    // No system zone name ("Asia/Kuala_Lumpur") on screen.
    assert.ok(!page.includes('times in ${inventory.branchTimezone}'));
    assert.match(page, /replaceAll\('_', ' '\)/);
});

test("V2 Inventory home: Daniel's verdict (kept-mounted stock panels, filter wording, minus sign)", () => {
    const bar = read('resources/js/components/inventory-v2/StockPanelBar.vue');
    // A closed bar keeps its form (v-show, never v-if), and the Classic forms get 44 px controls below 1024 px.
    assert.match(bar, /<div\s+v-show="open"/);
    assert.ok(!/v-if="open"/.test(bar));
    assert.match(bar, /max-width: 1023\.98px/);
    assert.ok(!bar.includes("type='submit'"), 'submits are flattened too');
    assert.match(bar, /min-height: 2\.75rem/);
    assert.equal((page.match(/<StockPanelBar/g) ?? []).length, 0);
    // "2 filters applied" beside Clear, "Filters (2)" on the phone button; no "extra filters".
    assert.match(page, /appliedFilters === 1\s*\?\s*'filter'\s*:\s*'filters'/);
    assert.ok(!page.includes('extra\n'));
    assert.ok(!/\}\} extra/.test(page));
    assert.match(page, /minus\(row\.quantityDisplay\)/);
    assert.match(page, /\\u2212/);
});

test('V2 Inventory home round 2: no repeated panel heading, inert when closed, V2DateRange, plural units', () => {
    const bar = read('resources/js/components/inventory-v2/StockPanelBar.vue');
    assert.match(bar, /:inert="!open"/);
    assert.match(bar, /> section > h2:first-child/);
    assert.match(
        page,
        /<V2DateRange[\s\S]*?v-model:from="dateFrom"[\s\S]*?v-model:to="dateTo"/,
    );
    assert.ok(!page.includes('<DateField'));
    assert.match(page, /\$\{name\}s/);
    assert.ok(!/\{\{ row\.stockUnit \}\}/.test(page));
    // Classic keeps its own unit words.
    assert.match(classic, /\{\{ row\.quantity \}\} \{\{ row\.stockUnit \}\}/);
});

test('V2 Inventory home: the chosen dates are echoed day first under both date ranges', () => {
    assert.equal((page.match(/\{\{ dateEcho \}\}/g) ?? []).length, 2);
    assert.match(page, /formatDate\(dateFrom\.value\)/);
    assert.match(page, /`\$\{from\} to \$\{to\}`/);
});
