import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const guard = read('resources/js/components/v2/V2LeaveGuard.vue');

test('V2LeaveGuard: holds only GET visits to another path while dirty; the same words as the dialog guard', () => {
    assert.match(guard, /router\.on\('before'/);
    assert.match(guard, /if \(bypass \|\| !props\.dirty\)/);
    assert.match(guard, /visit\.method !== 'get'/);
    assert.match(guard, /target\.pathname === window\.location\.pathname/);
    assert.match(guard, /return false;/);
    assert.match(guard, /title="Discard your changes\?"/);
    assert.match(guard, /confirm-label="Discard changes"/);
    assert.match(guard, /cancel-label="Keep editing"/);
    assert.match(guard, /beforeunload/);
    assert.match(guard, /stop\(\);/);
    assert.doesNotMatch(guard, /components\/ui/);
});

test('Staff/Edit: leave guard, person crumb, plain wording', () => {
    const page = read('resources/js/pages/Staff/V2/Edit.vue');
    assert.match(page, /<V2LeaveGuard :dirty="form\.isDirty" \/>/);
    assert.match(page, /usePageCrumbs/);
    assert.match(page, /Clinic management/);
    assert.match(page, /changed on the person's own page/);
    assert.doesNotMatch(page, /Protected ownership/);
    assert.match(page, /That email is already used by another staff member\./);
});
