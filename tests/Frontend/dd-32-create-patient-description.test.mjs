import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const flat = (s) => s.replace(/\s+/g, ' ');

test('DD-32: Create patient says what it does in plain words, Classic and V2', () => {
    for (const file of [
        'resources/js/pages/Patient/Create.vue',
        'resources/js/pages/Patient/V2/Create.vue',
    ]) {
        const source = flat(read(file));
        assert.ok(
            source.includes(
                "Add a patient to the clinic's patient list. This does not register a visit.",
            ),
            file,
        );
        assert.ok(!source.includes('canonical identity'), file);
    }
});
