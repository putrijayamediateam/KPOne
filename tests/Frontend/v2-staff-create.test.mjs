import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Staff/V2/Create.vue');
const classic = read('resources/js/pages/Staff/Create.vue');

test('Staff/Create is registered for V2, one form, one rail, no Classic ui blocks', () => {
    assert.match(read('config/v2-pages.php'), /'Staff\/Create'/);
    assert.match(page, /<V2WorkLayout/);
    assert.match(page, /rail-below="stack-bottom"/);
    assert.match(page, /form\.post\('\/staff'\)/);
    assert.doesNotMatch(
        page,
        /components\/ui\/(button|card|badge|dialog|date-field)/,
    );
});

test('Staff/Create sends the same fields as Classic and shows the rule refusal under `assignment`', () => {
    const keys = [
        'name',
        'email',
        'staff_number',
        'job_title',
        'department_id',
        'credential_strategy',
        'password',
        'password_confirmation',
        'is_active',
        'roles',
        'assignments',
    ];

    for (const key of keys) {
        assert.ok(new RegExp(`\\b${key}:`).test(classic), `classic ${key}`);
        assert.ok(new RegExp(`\\b${key}:`).test(page), `v2 ${key}`);
    }

    assert.match(page, /errorFor\('assignment'\)/);
    assert.match(page, /echoDate\(assignment\.valid_from, 'From'\)/);
});
