import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('StatusConfirmationDialog sends the opposite of the state the page shows NOW, on every confirm', () => {
    const s = read(
        'resources/js/pages/Staff/Partials/StatusConfirmationDialog.vue',
    );
    // The form is made once with `!props.isActive`; after a save the prop changes, so the value must be set again.
    assert.match(
        s,
        /form\.is_active = !props\.isActive;\s+return form\.patch\(/,
    );
    assert.match(s, /`\/staff\/\$\{props\.staffId\}\/status`/);
});

test('Staff Create shows the server refusal given under the key `assignment`', () => {
    const s = read('resources/js/pages/Staff/Create.vue');
    assert.match(s, /<InputError\s+:message="errorFor\('assignment'\)"/);
    assert.match(s, /<InputError\s+:message="form\.errors\.assignments"/);
});
