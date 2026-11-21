import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const block = read('resources/js/components/inventory/PackConversionBlock.vue');
const flat = block.replace(/\s+/g, ' ');

test('STK-52: the checker sees no number, the proposer and a third person do', () => {
    // The checker's line never interpolates the waiting number; the other line does.
    const checkerLine = flat.slice(
        flat.indexOf('<p v-if="block.waiting.canCheck"'),
        flat.indexOf('<p v-else class="font-medium">'),
    );
    assert.doesNotMatch(checkerLine, /basePerPack|packUnit/);
    assert.match(checkerLine, /a change to the pack size/);
    assert.match(
        flat,
        /describe\(\s*block\.waiting\.packUnit,\s*block\.waiting\.basePerPack,?\s*\)/,
    );
    assert.match(flat, /v-if="block\.waiting\.canWithdraw"/);
});

test('STK-52: the blind check box has no default and Check waits for a number', () => {
    assert.match(flat, /Count them yourself and type the number\./);
    assert.match(flat, /checked_base_per_pack: ''/);
    assert.match(
        flat,
        /\(!block\.waiting\.stop && check\.checked_base_per_pack === ''\)/,
    );
    assert.match(flat, /confirm-disabled-reason="Pick a reason to continue\."/);
    assert.match(flat, /<option value="" disabled>Pick a reason<\/option>/);
});

test('STK-52: propose shows a live line, stays off until valid and keeps the ml limit on screen', () => {
    assert.match(
        flat,
        /Dispensary will hand out whole \{\{ packWord \}\}s of \{\{ count \}\} \{\{ unit \}\}, once someone else has checked this\./,
    );
    assert.match(flat, /:disabled="!proposalValid \|\| proposal\.processing"/);
    assert.match(
        flat,
        /Stock stays counted in \{\{ unit \}\}\. Receiving, opening balances and counts are typed in \{\{ unit \}\}/,
    );
    assert.match(flat, /Stop using pack conversion/);
    assert.match(flat, /Show earlier versions/);
    assert.doesNotMatch(block, /v-html|localStorage|sessionStorage/);
});

test('STK-52: the block sits outside the SKU form and Pilot readiness shows waiting checks', () => {
    const skus = read('resources/js/pages/Inventory/Skus.vue').replace(
        /\s+/g,
        ' ',
    );
    assert.match(skus, /<\/form> <!-- STK-52: saves on its own/);
    assert.match(
        skus,
        /<PackConversionBlock :sku-public-id="sku\.publicId" :block="sku\.packConversion" :filters="skus\.filters" \/>/,
    );
    const readiness = read('resources/js/pages/Inventory/Readiness.vue');
    assert.match(readiness, /data-testid="pack-conversions-waiting"/);
});
