import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Registration settings: look only. Same props, route, payload and lock version as Classic.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/RegistrationSettings/Index.vue');
const v2 = read('resources/js/pages/RegistrationSettings/V2/Index.vue');
const registry = read('config/v2-pages.php');
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('RegistrationSettings/Index is registered and the V2 page uses the V2 blocks', () => {
    assert.match(registry, /'RegistrationSettings\/Index'/);

    for (const block of [
        '<V2WorkLayout',
        '<PageFrame',
        '<V2FormCard',
        '<V2SwitchRow',
        '<V2StickyBar always',
    ]) {
        assert.ok(v2.includes(block), block);
    }
});

test('V2 posts the same route and payload with the lock version, and keeps the fixed rows and the reload path', () => {
    for (const key of [
        "form.post('/registration-settings'",
        'expected_branch_id: props.branchId',
        'lock_version: props.requirements.lockVersion',
        'idempotency_key: crypto.randomUUID()',
        'require_date_of_birth: props.requirements.requireDateOfBirth',
        'require_nationality: props.requirements.requireNationality',
        'require_email: props.requirements.requireEmail',
        'require_address: props.requirements.requireAddress',
        'router.reload(',
        'Registration settings saved.',
        'Reload latest settings',
        'Requires line 1, city, state, postcode and country. Line 2 stays optional.',
        'Public QR always requires date of birth for the minor and guardian check.',
    ]) {
        assert.ok(squash(classic).includes(squash(key)), `classic ${key}`);
        assert.ok(squash(v2).includes(squash(key)), `v2 ${key}`);
    }

    for (const fixed of ['Name', 'Phone number', 'NRIC/Passport', 'Gender']) {
        assert.ok(v2.includes(fixed), fixed);
    }

    assert.ok(v2.includes('Required by system'));
    assert.ok(v2.includes('Required; Unknown allowed'));
});

test('V2 has one save, filled only when something changed, and no native checkbox', () => {
    assert.ok(v2.includes('dirty ? filledButton : outlineButton'));
    assert.ok(!/type="checkbox"/.test(v2));
    assert.ok(v2.includes("title: 'Clinic management'"));
});

test('V2 says next to Save when the change takes effect', () => {
    assert.ok(
        v2.includes('Applies to new registrations from the moment you save.'),
    );
});

test('Classic imports nothing from V2', () => {
    assert.ok(!/\/V2\//.test(classic));
});
