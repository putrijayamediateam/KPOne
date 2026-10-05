import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Medicine setup joins catalogue identity, linked inventory, tariffs, and opening stock in one form', () => {
    const source = read('resources/js/pages/Medicine/Index.vue');
    const money = read('resources/js/lib/money.ts');

    for (const field of [
        'generic_name',
        'category',
        'default_dosage_amount',
        'inventory_sku_public_id',
        'sku_code',
        'self_pay_rm',
        'panel_default_rm',
        'opening_stock',
        'unit_cost_rm',
        'batch_number',
        'expiry_date',
    ]) {
        assert.ok(
            source.includes(field),
            `Medicine setup must include ${field}.`,
        );
    }

    assert.match(source, /rmToSen\(data\.prices\.self_pay_rm\)/);
    assert.match(source, /rmToSen\(row\.unit_cost_rm\)/);
    assert.match(money, /amount: string \| number/);
    assert.match(
        money,
        /typeof amount === 'number' \? String\(amount\) : amount\.trim\(\)/,
    );
    assert.match(source, /<PanelTariffEditor/);
    assert.match(source, /<CatalogueOptionPicker/);
    assert.match(source, /CatalogueFormSectionHeading/);
    assert.match(source, /<InventorySkuPicker/);
    assert.match(source, /<LocationPicker/);
    assert.match(source, /<SupplierPicker/);
    assert.match(source, /const removeStockLocation/);
    assert.match(source, /Remove opening stock row/);
    assert.match(source, /\/medicines\/\$\{row\.publicId\}\/setup/);
    assert.match(source, /v-if="setup\.canManagePrices"/);
    assert.match(source, /v-if="setup\.canReceiveStock"/);
    assert.match(
        read('resources/js/components/catalogue/CatalogueOptionPicker.vue'),
        /@click="toggleOptions"/,
    );
    assert.match(
        read('resources/js/components/catalogue/CatalogueOptionPicker.vue'),
        /@blur="closeOptions"/,
    );
});

test('Clinical Service setup offers reusable categories, self-pay and panel tariffs without medicine stock fields', () => {
    const source = read('resources/js/pages/ClinicalService/Index.vue');
    const money = read('resources/js/lib/money.ts');

    assert.match(source, /type="service_category"/);
    assert.match(source, /self_pay_rm/);
    assert.match(source, /panel_default_rm/);
    assert.match(source, /rmToSen\(data\.prices\.self_pay_rm\)/);
    assert.match(source, /rmToSen\(\s*data\.prices\.panel_default_rm\s*\)/);
    assert.match(source, /<PanelTariffEditor/);
    assert.match(source, /Default pricing/);
    assert.match(source, /Panel-specific price overrides/);
    assert.match(source, /\/clinical-services\/\$\{row\.publicId\}\/setup/);
    assert.match(source, /rmToSen\(data\.prices\.self_pay_rm\)/);
    assert.match(source, /v-if="setup\.canManagePrices"/);
    assert.doesNotMatch(source, /v-if="!editing && setup\.canManagePrices"/);
    assert.doesNotMatch(source, /opening_stock|sku_code|batch_number/);
    assert.match(
        money,
        /typeof amount === 'number' \? String\(amount\) : amount\.trim\(\)/,
    );
});

test('Medicine and Clinical Service lists show default catalogue tariffs', () => {
    const medicine = read('resources/js/pages/Medicine/Index.vue');
    const service = read('resources/js/pages/ClinicalService/Index.vue');

    for (const source of [medicine, service]) {
        assert.match(source, /selfPayAmountRm: string \| null/);
        assert.match(source, /panelDefaultAmountRm: string \| null/);
        assert.match(source, /Self-pay/);
        assert.match(source, /Default Panel/);
        assert.match(source, /setup\.canManagePrices/);
        assert.match(source, /variant="catalogue"/);
        assert.match(source, /<th v-if="setup\.canManagePrices">Prices<\/th>/);
        assert.match(source, /<th class="text-right">Actions<\/th>/);
    }
});

test('catalogue edits cannot be saved when loading saved setup details fails', () => {
    const medicine = read('resources/js/pages/Medicine/Index.vue');
    const service = read('resources/js/pages/ClinicalService/Index.vue');

    for (const source of [medicine, service]) {
        assert.match(
            source,
            /form\.processing\s*\|\|\s*editLoading\s*\|\|\s*!!editLoadError/,
        );
    }
});

test('Panel tariff and descriptive dropdowns persist new options through permissioned JSON endpoints', () => {
    const panelEditor = read(
        'resources/js/components/catalogue/PanelTariffEditor.vue',
    );
    const optionPicker = read(
        'resources/js/components/catalogue/CatalogueOptionPicker.vue',
    );

    assert.match(panelEditor, /POST/);
    assert.match(panelEditor, /'\/catalogue-panels'/);
    assert.match(panelEditor, /Add new Panel/);
    assert.match(optionPicker, /`\/catalogue-options\/\$\{props\.type\}`/);
    assert.match(optionPicker, /`\/catalogue-options\/\$\{props\.type\}\?/);
    assert.match(
        optionPicker,
        /Show saved \$\{label\.toLowerCase\(\)\} options/,
    );
    assert.match(optionPicker, /error\.status === 403/);
    assert.match(optionPicker, /error\.status >= 500/);
    assert.match(
        read('resources/js/components/catalogue/SupplierPicker.vue'),
        /'\/catalogue-setup\/suppliers'/,
    );
    assert.match(
        read('resources/js/components/catalogue/LocationPicker.vue'),
        /'\/catalogue-setup\/locations'/,
    );
});

test('Catalogue option lookup avoids focus requests and debounces text searches', () => {
    const optionPicker = read(
        'resources/js/components/catalogue/CatalogueOptionPicker.vue',
    );

    assert.match(optionPicker, /const OPTION_SEARCH_DEBOUNCE_MS = 400/);
    assert.match(optionPicker, /OPTION_SEARCH_DEBOUNCE_MS/);
    assert.doesNotMatch(optionPicker, /@focus="void search\(\)"/);
    assert.match(
        optionPicker,
        /const toggleOptions = \(\) => \{[\s\S]*?clearTimeout\(timer\);[\s\S]*?void search\(\);/,
        'Opening the option list must cancel a pending debounced request.',
    );
});

test('Purchase orders and receipt submissions carry estimated and actual unit costs', () => {
    const source = read(
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
    );
    const receiptTypes = read('resources/js/types/inventory-operations.ts');

    assert.match(source, /estimatedUnitCostRm/);
    assert.match(source, /unit_cost_sen: rmToSen\(line\.estimatedUnitCostRm\)/);
    assert.match(source, /actual purchase unit cost RM/);
    assert.match(receiptTypes, /unit_cost_sen\?: number/);
    assert.match(receiptTypes, /unitCostSen: line\.unit_cost_sen/);
});
