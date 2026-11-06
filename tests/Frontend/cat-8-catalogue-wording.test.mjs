import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-8 (B.E.R.N.I.S.S for the owner, 2026-11-06): sentence-case confirm titles (Daniel) and a hint that the
// Indication prints on the DSP-10 drug label, whose budget is 72 characters (2 rows of 36 at 9 pt).

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const medicine = read('resources/js/pages/Medicine/Index.vue');
const service = read('resources/js/pages/ClinicalService/Index.vue');
const picker = read(
    'resources/js/components/catalogue/CatalogueOptionPicker.vue',
);

test('activate and deactivate confirm titles are sentence case', () => {
    assert.match(medicine, /'Deactivate medicine'/);
    assert.match(medicine, /'Activate medicine'/);
    assert.match(service, /'Deactivate clinical service'/);
    assert.match(service, /'Activate clinical service'/);
    assert.doesNotMatch(
        medicine + service,
        /'(De)?[Aa]ctivate (Medicine|Clinical Service)'/,
    );
});

test('the Indication field says it prints on drug labels, connected to the input', () => {
    assert.match(
        medicine.replace(/\s+/g, ' '),
        /label="Indication" hint="Prints on drug labels: up to 72 characters\." :error="form\.errors\.default_indication"/,
    );
    assert.match(picker, /hint\?: string;/);
    assert.match(
        picker,
        /:aria-describedby="hint \? `\$\{listboxId\}-hint` : undefined"/,
    );
    assert.match(picker, /v-if="hint"\s+:id="`\$\{listboxId\}-hint`"/);
});
