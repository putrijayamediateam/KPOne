import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-15b open shifts (HR-PLAN 21.4; B.E.R.N.I.S.S for the owner, 2026-11-10).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/HR/OpenShifts.vue');
const roster = read('resources/js/pages/HR/Roster.vue');

test('a locum asks for an open shift and sees their own asks; never who else asked', () => {
    assert.match(page, /`\/hr\/open-shifts\/\$\{shift\.id\}\/ask`/);
    assert.match(
        page,
        /`\/hr\/open-shifts\/asks\/\$\{mine\.requestId\}\/withdraw`/,
    );
    assert.match(
        page,
        /Your sign-in is not linked to a locum file yet\. Ask HR\./,
    );
    const locumPart = page.slice(
        page.indexOf("openShifts.tab === 'available'"),
        page.indexOf('<!-- Managers'),
    );
    assert.doesNotMatch(locumPart, /a\.locum|\.asks\b/);
});

test('managers confirm one locum with a confirm dialog, decline with a reason, and withdraw destructively', () => {
    assert.match(page, /`\/hr\/open-shifts\/asks\/\$\{ask\.id\}\/confirm`/);
    assert.match(
        page,
        /joins the draft roster for that week[\s\S]{0,200}once the week is approved/,
    );
    assert.match(
        page,
        /title="Withdraw this open shift\?"[\s\S]{0,400}destructive/,
    );
    assert.match(page, /decline-reason-label/);
    assert.match(page, /This is your own ask\. Someone else decides it\./);
});

test('My roster links a locum to open shifts', () => {
    assert.match(
        roster,
        /v-if="roster\.my\.canAskOpenShifts"[\s\S]{0,300}href="\/hr\/open-shifts"/,
    );
});

test('the Staff page says only the Director creates a Locum account', () => {
    const staff = readFileSync(
        new URL('../../resources/js/pages/Staff/Index.vue', import.meta.url),
        'utf8',
    );
    assert.match(
        staff,
        /data-test="locum-director-only"[\s\S]{0,80}Only the Director can create a Locum account\./,
    );
});
