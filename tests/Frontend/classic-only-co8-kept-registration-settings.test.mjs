// Classic only, CO-8: the Classic half of v2-registration-settings, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/RegistrationSettings/Index.vue');
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');

test('Classic Registration settings posts its route and payload with the lock version and keeps the reload path and wording', () => {
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
    }
});
