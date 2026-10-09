import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Dispensary/Show.vue');
const invoices = read('resources/js/pages/Visits/History.vue');

const THREE_COLUMNS =
    'lg:grid-cols-[minmax(0,280px)_minmax(0,1fr)_minmax(0,300px)]';

test('the Dispensary page uses the same three-column layout as the Invoices page', () => {
    assert.ok(invoices.includes(THREE_COLUMNS));
    assert.ok(page.includes(THREE_COLUMNS));

    for (const id of [
        'dispensary-left',
        'dispensary-main',
        'dispensary-right',
    ]) {
        assert.ok(page.includes(`data-testid="${id}"`), id);
    }
});

test('the left column holds the patient and allergies, the centre the editable lists, the right the actions', () => {
    const at = (needle) => page.indexOf(needle);
    const left = at('data-testid="dispensary-left"');
    const main = at('data-testid="dispensary-main"');
    const right = at('data-testid="dispensary-right"');
    assert.ok(left < main && main < right);

    for (const id of [
        'dispensary-patient',
        'otc-allergy',
        'dispensary-allergies',
    ]) {
        assert.ok(
            at(`data-testid="${id}"`) > left &&
                at(`data-testid="${id}"`) < main,
            id,
        );
    }

    for (const id of [
        'add-medicine',
        'dispensary-services',
        'removed-lines',
        'dispensary-unsaved',
    ]) {
        assert.ok(
            at(`data-testid="${id}"`) > main &&
                at(`data-testid="${id}"`) < right,
            id,
        );
    }

    for (const id of [
        'dispensary-summary',
        'dispensary-actions',
        'dispensary-verify',
        'dispensary-call',
        'dispensary-complete-open',
    ]) {
        assert.ok(at(`data-testid="${id}"`) > right, id);
    }
});
