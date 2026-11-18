import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// "Usual quantity" per medicine (owner decision 2026-11-18): optional, counted in the order unit, blank means no prefill.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Medicine/Index.vue');
const v2Form = read('resources/js/components/medicine-v2/MedicineForm.vue');
const composable = read('resources/js/composables/medicine/useMedicineForm.ts');

test('both layouts carry the usual_quantity field with the same help text', () => {
    for (const [name, source] of [
        ['classic', classic],
        ['v2', v2Form],
    ]) {
        assert.ok(
            source.includes('v-model="form.usual_quantity"'),
            `${name} binds usual_quantity`,
        );
        assert.ok(
            source.includes('form.errors.usual_quantity'),
            `${name} shows the error`,
        );
        assert.match(
            source,
            /Prefilled when a doctor orders this medicine, and counted in the order unit/,
            `${name} help text`,
        );
        assert.match(
            source,
            /Leave empty for no prefill/,
            `${name} blank rule`,
        );
    }
});

test('the form state and the row type know about usual quantity, sent as a plain text number', () => {
    for (const source of [classic, composable]) {
        assert.match(source, /usualQuantity\?: string \| null;/);
        assert.match(source, /usual_quantity: '',/);
    }

    // The server owns the rules (positive, up to 3 decimals), so the field has no min or step that would make the
    // browser show its own popup instead of the field message and red border.
    for (const source of [classic, v2Form]) {
        assert.match(
            source,
            /v-model="form\.usual_quantity"\s+type="number"\s+step="any"/,
        );
    }
});
