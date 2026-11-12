import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/BusinessDetails/Index.vue').replace(
    /\s+/g,
    ' ',
);

test('Business details has an optional short address for drug labels, limited to 96 characters', () => {
    assert.match(
        page,
        /<Label for="label_address" >Address on drug labels \(optional\)<\/Label >/,
    );
    assert.match(
        page,
        /id="label_address" v-model="form\.label_address" name="label_address" maxlength="96"/,
    );
    assert.match(
        page,
        /Used only on drug labels\. Leave empty to print the full address\. Up to 96 characters\./,
    );
    assert.match(
        page,
        /aria-describedby="label_address-help label_address-error"/,
    );
    assert.match(
        page,
        /label_address: props\.letterhead\.details\.label_address \?\? ''/,
    );
    assert.match(page, /'registration_number', 'label_address',/);
});

test('the letterhead type carries label_address and the migration keeps it at 96 characters or fewer', () => {
    assert.match(
        read('resources/js/types/letterhead.ts'),
        /label_address\?: string \| null;/,
    );
    const migration = read(
        'database/migrations/2026_11_09_000200_add_label_address_to_branch_business_profiles.php',
    );
    assert.match(migration, /string\('label_address', 96\)->nullable\(\)/);
    assert.match(migration, /char_length\(label_address\) <= 96/);
    const service = read(
        'app/Domain/Organisation/BusinessDetails/BusinessDetailsService.php',
    );
    assert.match(
        service,
        /'label_address' => \['sometimes', 'nullable', 'string', 'max:96'\]/,
    );
});
