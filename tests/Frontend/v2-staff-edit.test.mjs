import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Staff/V2/Edit.vue');
const classic = read('resources/js/pages/Staff/Edit.vue');

test('Staff/Edit is registered for V2 and sends the same fields as Classic (PATCH /staff/{id})', () => {
    assert.match(read('config/v2-pages.php'), /'Staff\/Edit'/);
    assert.match(page, /form\.patch\(`\/staff\/\$\{props\.staff\.id\}`\)/);
    assert.match(page, /<V2WorkLayout/);
    assert.doesNotMatch(page, /components\/ui\/(button|card|badge|dialog)/);

    for (const key of [
        'name',
        'email',
        'staff_number',
        'job_title',
        'department_id',
    ]) {
        assert.ok(new RegExp(`\\b${key}:`).test(classic), `classic ${key}`);
        assert.ok(new RegExp(`\\b${key}:`).test(page), `v2 ${key}`);
    }
});
