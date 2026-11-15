import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Registration/Edit.vue');
const edit = read('resources/js/pages/Registration/V2/Edit.vue');

test('Edit visit keeps the Classic request', () => {
    for (const piece of ['visit_type', 'coverage_type', 'lock_version']) {
        assert.ok(classic.includes(piece), `Classic lacks ${piece}`);
        assert.ok(edit.includes(piece), `V2 lacks ${piece}`);
    }
});

test('Edit visit puts the rail at 1280 px with the bar below it and guards unsaved changes', () => {
    assert.ok(edit.includes('rail-from="xl"'));
    assert.ok(edit.includes('<V2StickyBar>'));
    assert.ok(edit.includes('<V2StickySummary'));
    assert.ok(
        edit.includes(
            '<V2LeaveGuard :dirty="form.isDirty && !form.processing" />',
        ),
    );
});
