import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Inventory is an executable permission-backed navigation destination', () => {
    const source = read('resources/js/lib/workspace-navigation.ts');
    const javascript = ts.transpileModule(source, {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const navigation = runInNewContext(
        `${javascript.replaceAll('export ', '')}\n({ mainMenuGroups, headerDestinations })`,
    );
    const capabilities = {
        registration: false,
        consultation: false,
        inventory: true,
        patientRecords: false,
        panelWork: false,
        financeWork: false,
        staff: false,
        branches: false,
        accessControl: false,
        auditLogs: false,
        publicCheckInLinks: false,
    };

    const groups = navigation.mainMenuGroups(capabilities);
    assert.deepEqual([...groups.map((group) => group.label)], [
        'Clinic Operations',
    ]);
    assert.deepEqual(
        [...groups[0].destinations.map((item) => item.label)],
        ['Inventory'],
    );
    assert.equal(groups[0].destinations[0].href, '/inventory');
    assert.ok(
        navigation
            .headerDestinations(capabilities, 'clinic')
            .some((item) => item.label === 'Inventory'),
    );
});

test('Inventory workspace exposes three read-only operational views and filters', () => {
    const page = read('resources/js/pages/Inventory/Index.vue');

    for (const label of [
        'Stock Overview',
        'Batch & Expiry',
        'Movements',
        'SKU / Item',
        'Medicine',
        'Location',
        'Batch',
        'Expiry',
        'Quantity',
        'Opening Balance',
        'Transfer',
        'Dispense',
    ]) {
        assert.match(page, new RegExp(label.replace('&', '&amp;|&')));
    }

    assert.match(page, /SKU, item or medicine/);
    assert.match(page, /All branch locations/);
    assert.match(page, /No inventory records/);
    assert.match(page, /<CompactPagination/);
    assert.match(page, /min-width="1040px"/);
    assert.match(page, /Movement records\s+are read-only/);
});

test('Inventory workspace has no stock or reference mutation controls', () => {
    const page = read('resources/js/pages/Inventory/Index.vue');

    assert.doesNotMatch(
        page,
        /Add Inventory|Add SKU|Add Location|Add Batch|Map Medicine|Receive Stock|Stocktake|Adjust Stock|Create Opening Balance/,
    );
    assert.doesNotMatch(page, /router\.(post|put|patch|delete)/);
    assert.doesNotMatch(page, /Procurement|Purchase Order|GRN/);
});
