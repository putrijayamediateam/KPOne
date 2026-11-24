import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-22b locum doctor (HR-PLAN 29.3; the owner's role decision, 24 Nov 2026).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const file =
    read('resources/js/pages/HR/StaffFile.vue') +
    read('resources/js/composables/hr/useStaffFilePage.ts');
const staff = read('resources/js/pages/Staff/Index.vue');

test('HR chooses Locum or Locum doctor when asking for a sign-in', () => {
    assert.match(file, /<legend[^>]*>\s*Sign-in for\s*<\/legend>/);
    assert.match(file, /type="radio"[\s\S]{0,200}value="locum"/);
    assert.match(file, /type="radio"[\s\S]{0,200}value="locum_doctor"/);
    assert.match(file, /Only the Director\s+gives that role\./);
    assert.match(file, /role: signIn\.role,/);
    assert.match(file, /:message="errors\.role"/);
    assert.match(file, /'Locum doctor with a KPOne sign-in'/);
});

test('the Staff page says which roles to create for each request', () => {
    assert.match(staff, /data-test="staff-locum-role"/);
    assert.match(staff, /\{\{ request\.roleLabel \}\} · asked on/);
    assert.match(staff, /both the Locum and Resident Doctor roles\./);
    assert.match(
        staff,
        /A locum doctor also gets the Resident Doctor\s+role\./,
    );
});
