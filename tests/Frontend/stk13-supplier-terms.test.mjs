import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const module = { exports: {} };
runInNewContext(
    ts.transpileModule(read('resources/js/lib/supplier-terms.ts'), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    { exports: module.exports, module },
);
const lib = module.exports;
const page = read('resources/js/pages/Inventory/SupplierTerms.vue');

test('a pack price converts from RM to sen exactly, never through floating point', () => {
    assert.equal(lib.ringgitToSen('12.34'), 1234);
    assert.equal(lib.ringgitToSen('0.1'), 10);
    assert.equal(lib.ringgitToSen('1234567.89'), 123456789);
    assert.equal(lib.ringgitToSen(' 7 '), 700);
    assert.equal(lib.ringgitToSen(''), null);

    for (const bad of ['12.345', '-1', '1,000', 'abc', '1e3']) {
        assert.equal(lib.ringgitToSen(bad), undefined, bad);
    }

    assert.equal(lib.formatRinggit(1234), '12.34');
    assert.equal(lib.formatRinggit(5), '0.05');
    assert.equal(lib.formatRinggit(null), '');
    assert.doesNotMatch(
        read('resources/js/lib/supplier-terms.ts'),
        /parseFloat|Math\.round|toFixed/,
    );
});

test('the price is shown and sent only for a cost holder', () => {
    assert.match(
        page,
        /v-if="terms\.canSeeCost"[\s\S]{0,80}Price for one pack \(RM, optional\)/,
    );
    assert.match(
        page,
        /props\.terms\.canSeeCost \? \{ pack_price_sen: sen \} : \{\}/,
    );
    assert.match(
        page,
        /Prices are shown only to a Director or Finance Officer\./,
    );
    assert.match(page, /v-if="terms\.canManage"/);
});

test('a pack size shows without trailing zeros', () => {
    assert.equal(lib.trimQuantity('100.000'), '100');
    assert.equal(lib.trimQuantity('2.500'), '2.5');
    assert.equal(lib.trimQuantity('0.125'), '0.125');
});

test('a pack reads naturally: no repeated count, plural units', () => {
    assert.equal(lib.packLabel('Box of 100', '100.000', 'unit'), 'Box of 100');
    assert.equal(
        lib.packLabel('Strip', '10.000', 'tablet'),
        'Strip (10 tablets)',
    );
    assert.equal(lib.packLabel('Single', '1.000', 'vial'), 'Single (1 vial)');
    assert.equal(lib.packLabel('Bottle', '100.000', 'ml'), 'Bottle (100 ml)');
    assert.equal(
        lib.packLabel('Box of 1000', '100.000', 'unit'),
        'Box of 1000 (100 units)',
    );
});

test('buttons are 44 px tall below 1024 px (design guide section 12)', () => {
    assert.ok((page.match(/class="h-11 lg:h-9"/g) ?? []).length >= 5);
    assert.equal(
        (page.match(/\[&_button\]:h-11! lg:\[&_button\]:h-9!/g) ?? []).length,
        2,
    );
});
