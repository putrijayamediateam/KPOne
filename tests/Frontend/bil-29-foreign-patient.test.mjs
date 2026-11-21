import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-29: foreign (passport) patients are marked on the bill; SST is not calculated yet.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const note = read('resources/js/components/billing/ForeignPatientNote.vue');
const flatNote = note.replace(/\s+/g, ' ');

test('BIL-29: the note shows only when the server says so, with a neutral badge and no amount', () => {
    assert.match(note, /defineProps<\{ foreign\?: boolean \}>\(\)/);
    assert.match(flatNote, /<div v-if="foreign"/);
    assert.match(
        flatNote,
        /<StatusBadge status="foreign" tone="neutral" label="Foreign patient \(passport\)" \/>/,
    );
    assert.match(flatNote, /SST: to be confirmed \(no SST is charged yet\)\./);
    assert.doesNotMatch(note, /myr\(|Sen\b|%/);
});

test('BIL-29: Patient Billing, Manage payments and the completed Invoice show it; the print does not', () => {
    const workspace = read(
        'resources/js/components/billing/BillingWorkspace.vue',
    ).replace(/\s+/g, ' ');
    assert.equal(
        [
            ...workspace.matchAll(
                /<ForeignPatientNote :foreign="billing\.patient\.foreign" \/>/g,
            ),
        ].length,
        2,
    );
    assert.match(
        read('resources/js/pages/Billing/Invoice.vue').replace(/\s+/g, ' '),
        /<ForeignPatientNote :foreign="invoice\.patient\.foreign" \/>/,
    );
    assert.doesNotMatch(
        read('resources/js/pages/Billing/Print.vue'),
        /ForeignPatientNote|foreign/,
    );
});
