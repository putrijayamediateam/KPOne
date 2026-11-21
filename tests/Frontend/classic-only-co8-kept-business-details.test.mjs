// Classic only, CO-8: the Classic half of v2-business-details, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');
const classic = read('resources/js/pages/BusinessDetails/Index.vue');

test('Classic Business details posts to its routes with the payload fields and lock versions', () => {
    for (const key of [
        "form.post('/business-details'",
        "footerForm.post('/business-details/footer'",
        'forceFormData: true',
        'organisation_lock_version: props.letterhead.organisationVersion',
        'branch_lock_version: props.letterhead.branchVersion',
        'expected_branch_id: props.letterhead.branchId',
        'lock_version: props.letterhead.footerVersion',
        'remove_logo: false',
        "'/branch-context'",
    ]) {
        assert.ok(squash(classic).includes(squash(key)), `classic ${key}`);
    }
});

test('Classic Business details keeps every field name, limit and the logo rules', () => {
    for (const key of [
        'legal_name',
        'registration_type',
        'registration_number',
        'clinic_name',
        'address_line_1',
        'address_line_2',
        'postcode',
        'phone_secondary',
        'maxlength="200"',
        'maxlength="80"',
        'maxlength="40"',
        'maxlength="254"',
        'maxlength="1000"',
        'accept="image/jpeg,image/png"',
        'not saved yet',
        'Logo will be removed when you save.',
    ]) {
        assert.ok(classic.includes(key), key);
    }
});
