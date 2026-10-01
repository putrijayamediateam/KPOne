import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Medicine setup joins catalogue identity, linked inventory, tariffs, and opening stock in one form', () => {
    const source = read('resources/js/pages/Medicine/Index.vue');

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

    assert.match(source, /toSen\(data\.prices\.self_pay_rm\)/);
    assert.match(source, /toSen\(row\.unit_cost_rm\)/);
    assert.match(source, /amount: string \| number/);
    assert.match(
        source,
        /typeof amount === 'number' \? String\(amount\) : amount\.trim\(\)/,
    );
    assert.match(source, /<PanelTariffEditor/);
    assert.match(source, /<CatalogueOptionPicker/);
    assert.match(source, /CatalogueFormSectionHeading/);
    assert.match(source, /<InventorySkuPicker/);
    assert.match(source, /<LocationPicker/);
    assert.match(source, /<SupplierPicker/);
});

test('Clinical Service setup offers reusable categories, self-pay and panel tariffs without medicine stock fields', () => {
    const source = read('resources/js/pages/ClinicalService/Index.vue');

    assert.match(source, /type="service_category"/);
    assert.match(source, /self_pay_rm/);
    assert.match(source, /panel_default_rm/);
    assert.match(source, /<PanelTariffEditor/);
    assert.match(source, /Default pricing/);
    assert.match(source, /Panel-specific price overrides/);
    assert.doesNotMatch(source, /opening_stock|sku_code|batch_number/);
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
