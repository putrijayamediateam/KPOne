import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );

test('Panel prices are read-only on the catalogue screens and say who proposes them, with no link', () => {
    const block = read(
        'resources/js/components/catalogue/PanelPricesReadOnly.vue',
    );
    const pricing = read('resources/js/pages/Pricing/Index.vue');
    const sentence =
        'A Panel Officer proposes them in Clinic management, Panel price changes.';

    assert.ok(block.includes(sentence));
    assert.ok(pricing.includes(sentence));

    for (const path of [
        'resources/js/components/catalogue/PanelPricesReadOnly.vue',
        'resources/js/components/catalogue/ConsultationTariffPanel.vue',
        'resources/js/pages/Medicine/Index.vue',
        'resources/js/pages/ClinicalService/Index.vue',
        'resources/js/pages/Pricing/Index.vue',
    ]) {
        const source = read(path);

        assert.doesNotMatch(
            source,
            /Propose a change|canProposePanelPrices|\/panel-price-changes/,
            path,
        );
    }
});

test('the Pricing page never reads a Panel price as the current price to repeat', () => {
    const controller = read('app/Http/Controllers/PricingController.php');

    assert.match(controller, /where\('price_tier', '!=', 'panel'\)/);
});
