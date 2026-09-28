import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const navigationSource = read('resources/js/lib/workspace-navigation.ts');
const navigationJavascript = ts.transpileModule(navigationSource, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;
const navigation = runInNewContext(
    `${navigationJavascript.replaceAll('export ', '')}\n({ mainMenuGroups, headerDestinations })`,
);

const capabilities = (overrides = {}) => ({
    registration: false,
    registrationReview: false,
    consultation: false,
    inventory: false,
    medicineCatalogue: false,
    clinicalServiceCatalogue: false,
    pricing: false,
    patientRecords: false,
    panelWork: false,
    financeWork: false,
    staff: false,
    branches: false,
    accessControl: false,
    auditLogs: false,
    publicCheckInLinks: false,
    ...overrides,
});

const labels = (groups) =>
    groups.flatMap((group) => group.destinations.map((item) => item.label));

test('Reference Data group appears only for the three new catalogue/pricing permissions', () => {
    const none = navigation.mainMenuGroups(capabilities());
    assert.ok(!none.some((group) => group.label === 'Reference Data'));

    const all = navigation.mainMenuGroups(
        capabilities({
            medicineCatalogue: true,
            clinicalServiceCatalogue: true,
            pricing: true,
        }),
    );
    const group = all.find((group) => group.label === 'Reference Data');
    assert.ok(group, 'Reference Data group must be present.');
    assert.deepEqual(
        [...group.destinations.map((item) => item.label)],
        ['Medicine Catalogue', 'Clinical Service Catalogue', 'Pricing'],
    );
    assert.deepEqual(
        [...group.destinations.map((item) => item.href)],
        ['/medicines', '/clinical-services', '/pricing'],
    );

    const medicineOnly = labels(
        navigation.mainMenuGroups(capabilities({ medicineCatalogue: true })),
    );
    assert.deepEqual([...medicineOnly], ['Medicine Catalogue']);
});

test('Reference Data destinations are not promoted into the header navigation', () => {
    const allowed = capabilities({
        medicineCatalogue: true,
        clinicalServiceCatalogue: true,
        pricing: true,
        registration: true,
    });

    const clinicHeader = navigation
        .headerDestinations(allowed, 'clinic')
        .map((item) => item.label);
    const adminHeader = navigation
        .headerDestinations(allowed, 'admin')
        .map((item) => item.label);

    assert.ok(!clinicHeader.includes('Medicine Catalogue'));
    assert.ok(!clinicHeader.includes('Clinical Service Catalogue'));
    assert.ok(!clinicHeader.includes('Pricing'));
    assert.ok(!adminHeader.includes('Medicine Catalogue'));
    assert.ok(!adminHeader.includes('Pricing'));
});

test('Medicine Catalogue page exposes search, create, edit and activate/deactivate but no delete', () => {
    const source = read('resources/js/pages/Medicine/Index.vue');

    assert.match(source, /router\.get\(\s*'\/medicines'/);
    assert.match(source, /form\.post\('\/medicines'/);
    assert.match(source, /form\.patch\(`\/medicines\/\$\{editing\.value\.publicId\}`/);
    assert.match(
        source,
        /`\/medicines\/\$\{row\.publicId\}\/\$\{row\.isActive \? 'deactivate' : 'activate'\}`/,
    );
    assert.doesNotMatch(source, /router\.delete/);
});

test('Clinical Service Catalogue page mirrors the Medicine Catalogue page shape', () => {
    const source = read('resources/js/pages/ClinicalService/Index.vue');

    assert.match(source, /router\.get\(\s*'\/clinical-services'/);
    assert.match(source, /form\.post\('\/clinical-services'/);
    assert.match(
        source,
        /form\.patch\(`\/clinical-services\/\$\{editing\.value\.publicId\}`/,
    );
    assert.match(
        source,
        /`\/clinical-services\/\$\{row\.publicId\}\/\$\{row\.isActive \? 'deactivate' : 'activate'\}`/,
    );
    assert.doesNotMatch(source, /router\.delete/);
    // No strength/dosage-form fields: clinical services are not medicines.
    assert.doesNotMatch(source, /strength_text|dosage_form/);
});

test('Pricing page keeps publication gated separately from reference management', () => {
    const source = read('resources/js/pages/Pricing/Index.vue');

    assert.match(source, /canPublish: boolean/);
    assert.match(source, /v-if="canPublish"/);
    assert.match(source, /expected_version: charge\.currentPrice\?\.version \?\? 0/);
    assert.match(source, /expected_branch_id: activeBranchId/);
    assert.match(source, /`\/pricing\/charges\/\$\{charge\.publicId\}\/publish`/);
});

test('Inventory reference data panel is gated behind canManage and covers the stock-setup chain', () => {
    const source = read(
        'resources/js/components/inventory/InventoryReferenceDataPanel.vue',
    );

    assert.match(source, /v-if="referenceData\.canManage"/);

    for (const endpoint of [
        '/inventory/opening-balances',
        '/inventory-references/items',
        '/inventory-references/skus',
        '/inventory-references/locations',
        '/inventory-references/batches',
        '/inventory-references/mappings',
    ]) {
        assert.ok(
            source.includes(endpoint),
            `InventoryReferenceDataPanel.vue must post to ${endpoint}.`,
        );
    }
});

test('Opening Balance offers Batch as a picker scoped to the selected SKU, not free text', () => {
    const source = read(
        'resources/js/components/inventory/InventoryReferenceDataPanel.vue',
    );

    // Not a plain text Input for the batch identifier anymore.
    assert.doesNotMatch(
        source,
        /v-model="openingBalanceForm\.batch_public_id"[\s\S]{0,40}<Input/,
    );
    assert.match(
        source,
        /OperationalSelect[\s\S]{0,120}v-model="openingBalanceForm\.batch_public_id"/,
    );

    // Batches are loaded on demand for the single selected SKU and are never
    // shipped in the page props.
    assert.doesNotMatch(source, /referenceData\.batches|batches: Array</);
    assert.match(
        source,
        /requestJson<[^>]*>\([\s\S]{0,60}\/inventory-references\/skus\/\$\{skuPublicId\}\/batches/,
    );
    assert.match(
        source,
        /watch\(\s*\n?\s*\(\) => openingBalanceForm\.sku_public_id,[\s\S]{0,120}openingBalanceForm\.batch_public_id = '';[\s\S]{0,80}loadSkuBatches\(skuPublicId\)/,
    );
    // A superseded response must not overwrite a newer SKU's batches.
    assert.match(source, /request === batchesRequest/);
    assert.doesNotMatch(
        read('resources/js/pages/Inventory/Index.vue'),
        /batches: Array</,
    );

    assert.doesNotMatch(source, /Paste the created Batch's identifier/);
});

test('Inventory Index renders the reference data panel ahead of existing operations', () => {
    const source = read('resources/js/pages/Inventory/Index.vue');
    const referenceIndex = source.indexOf('InventoryReferenceDataPanel');
    const operationsIndex = source.indexOf('InventoryOperationsPanel');

    assert.ok(referenceIndex > -1, 'Inventory/Index.vue must render InventoryReferenceDataPanel.');
    assert.ok(operationsIndex > -1);
    assert.ok(
        source.indexOf(':reference-data="referenceData"') <
            source.lastIndexOf('<InventoryOperationsPanel'),
    );
});
