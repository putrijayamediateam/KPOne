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
    assert.match(page, /form\.post\('\/staff', \{/);
    assert.match(page, /form\.reset\('password', 'password_confirmation'\)/);
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

test('Staff/Create round 2: plain required lines, errors clear on edit, plain sign-in words, sticky action bar', () => {
    assert.match(page, /Enter the full name\./);
    assert.match(page, /Choose a department\./);
    assert.match(page, /Choose a branch\./);
    assert.match(page, /Choose at least one role\./);
    assert.match(page, /field is required/);
    assert.match(page, /clear\('name'\)/);
    assert.match(page, /form\.clearErrors\('roles'\)/);
    assert.match(page, /Google sign-in only/);
    assert.match(page, /Temporary password/);
    assert.match(page, /never\s+shown again/);
    assert.doesNotMatch(page, /OAuth|Bootstrap|pre-provisioning/);
    assert.match(page, /<V2StickyBar>/);
});
