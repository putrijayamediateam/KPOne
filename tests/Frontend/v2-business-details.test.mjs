import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Business details: look only. Same props, routes, fields, lock versions and the same save payloads.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const squash = (t) => t.replace(/\s+/g, '').replace(/,([)\]}])/g, '$1');
const classic = read('resources/js/pages/BusinessDetails/Index.vue');
const v2 = read('resources/js/pages/BusinessDetails/V2/Index.vue');
const registry = read('config/v2-pages.php');

test('BusinessDetails/Index is registered and the V2 page uses the V2 blocks', () => {
    assert.match(registry, /'BusinessDetails\/Index'/);

    for (const block of [
        '<V2WorkLayout',
        '<PageFrame',
        '<V2Tabs',
        '<V2DisclosureBar',
        '<V2FormCard',
        '<V2Dialog',
    ]) {
        assert.ok(v2.includes(block), block);
    }
});

test('V2 posts to the same routes with the same payload fields and lock versions', () => {
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
        assert.ok(squash(v2).includes(squash(key)), `v2 ${key}`);
    }
});

test('V2 keeps every Classic field name, limit and the logo rules', () => {
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
        assert.ok(classic.includes(key) && v2.includes(key), key);
    }
});

test('V2 has two tabs and asks before a tab with unsaved work is left', () => {
    assert.match(v2, /label: 'Company'/);
    assert.match(v2, /label: 'Branches'/);
    assert.ok(v2.includes('<V2LeaveGuard'));
    assert.ok(v2.includes(':dirty="tabDirty"'));
    assert.ok(v2.includes('guard.value?.ask('));
    assert.ok(!/DiscardPrompt/.test(v2));
    assert.ok(v2.includes('tabDirty'));
});

test('V2 does not add a native select, file input, or a back link beside the crumb', () => {
    assert.ok(!/<select\b/.test(v2));
    assert.ok(!/type="file"/.test(v2));
    assert.ok(!v2.includes('← Clinic management'));
    assert.ok(v2.includes("title: 'Clinic management'"));
});

test('Classic imports nothing from V2', () => {
    assert.ok(!/\/V2\//.test(classic));
});

test('V2 never shows two filled saves, names what each save covers and confirms Remove logo', () => {
    assert.ok(v2.includes('footerFilled'));
    assert.match(
        v2,
        /footerDirty\.value && !\(props\.canEdit && changed\(branchKeys\)\)/,
    );
    assert.ok(v2.includes('Saves the letterhead only.'));
    assert.ok(v2.includes('Saves the print footer only.'));
    assert.ok(v2.includes('Keep logo'));
    assert.match(v2, /@click="removeOpen = true"/);
    assert.ok(v2.includes('every branch'));
});

test('V2 view-only shows plain text, no Save, and the permission line', () => {
    assert.ok(v2.includes('data-test="company-readonly"'));
    assert.ok(v2.includes('data-test="branch-readonly"'));
    assert.ok(v2.includes('Only the Director and Administrator'));
    assert.match(v2, /<form\s+v-if="canEdit"/);
});
