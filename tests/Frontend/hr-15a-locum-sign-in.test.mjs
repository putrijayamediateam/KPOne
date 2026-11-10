import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-15a locum sign-ins (HR-PLAN 21.3; B.E.R.N.I.S.S for the owner, 2026-11-10).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const file = read('resources/js/pages/HR/StaffFile.vue');
const staff = read('resources/js/pages/Staff/Index.vue');

test('a locum file asks for a sign-in by email and HR creates no account', () => {
    assert.match(file, /data-test="locum-sign-in"/);
    assert.match(file, /Email the locum will sign in with/);
    assert.match(file, /`\/hr\/records\/\$\{w\.value\.id\}\/sign-in`/);
    assert.match(file, /HR creates no\s+account\./);
    assert.match(file, /title="Cancel the sign-in request\?"/);
});

test('the Staff page links a locum account, only for whoever may create staff accounts', () => {
    assert.match(
        staff,
        /v-if="locumSignIns && locumSignIns\.requests\.length"/,
    );
    assert.match(staff, /`\/staff\/locum-sign-ins\/\$\{request\.id\}\/link`/);
    assert.match(staff, /with\s+the Locum role only/);
    assert.match(staff, /role="alert"[\s\S]{0,120}staff-locum-link-error/);
    assert.match(staff, /No locum account to link yet\. Create it first\./);
});
