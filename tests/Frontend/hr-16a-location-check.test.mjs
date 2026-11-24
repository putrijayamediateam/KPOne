import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page =
    read('resources/js/pages/HR/Attendance.vue') +
    read('resources/js/composables/hr/useAttendancePage.ts');
// One Clock in: the press, its consent and its one location read are in the shared clock flow on Me.
const flow =
    read('resources/js/composables/useClockPhoto.ts') +
    read('resources/js/components/hr/attendance/ClockPhotoDialog.vue') +
    read('resources/js/components/hr/attendance/ClockLocationConsent.vue');
// The card's state and actions live in its composable (HR V2 Attendance PR 2), so the card source is both files.
const card =
    read('resources/js/components/hr/attendance/LocationCheckSettings.vue') +
    read('resources/js/composables/hr/useLocationCheckSettings.ts');

test('the location is read once, at the press, and never tracked or kept in the browser', () => {
    // One Clock in: the read moved to the shared clock flow on Me; the Attendance page reads nothing.
    assert.equal(flow.match(/getCurrentPosition\(/g)?.length, 1);
    assert.doesNotMatch(page, /getCurrentPosition\(/);
    assert.doesNotMatch(
        page + card + flow,
        /watchPosition|localStorage|sessionStorage/,
    );
    assert.match(
        flow,
        /const readLocation = [\s\S]{0,1200}getCurrentPosition[\s\S]{0,400}clock_position: \{\s*latitude: position\.coords\.latitude,\s*longitude: position\.coords\.longitude,\s*accuracy: position\.coords\.accuracy,/,
    );
    assert.match(flow, /maximumAge: 0/);
    // A refusal or a failure still sends the press.
    assert.match(
        flow,
        /location_unavailable:\s*error\.code === 1 \? 'denied' : 'failed'/,
    );
    assert.match(flow, /location_unavailable: 'failed'/);
    // Off at the branch: the camera opens straight away, with nothing extra.
    assert.match(
        flow,
        /state\.step = state\.location\s*\? state\.location\.consentNeeded\s*\? 'consent'\s*: 'locating'\s*: 'starting'/,
    );
});

test('consent is shown once, in English and Bahasa Melayu, and clocking in without location is always offered', () => {
    assert.match(flow, /data-test="clock-location-consent"/);
    // The text stays marked as a draft on screen until the PDPA review (B.E.R.N.I.S.S).
    assert.match(
        flow,
        /data-test="clock-location-draft"[\s\S]{0,80}Draft wording until the clinic's PDPA review\./,
    );
    assert.match(flow, /consentEn[\s\S]{0,400}lang="ms"[\s\S]{0,300}consentBm/);
    assert.match(flow, /data-test="clock-location-without"/);
    assert.match(
        flow,
        /WITHOUT_LOCATION = \{ location_unavailable: 'declined' \}/,
    );
    // Two equal choices (Daniel).
    assert.equal(flow.match(/:class="choice"/g)?.length, 2);
    assert.match(flow, /data-test="clock-location-agree"/);
    assert.match(flow, /@agree="checkLocation\(true\)"/);
    assert.match(page, /data-test="location-note"/);
    // You see your own latest result (Suresh, m3).
    assert.match(
        page,
        /data-test="location-last"[\s\S]{0,80}attendance\.my\.location\.lastResult/,
    );
});

test('Review shows the result in plain muted words, never a warning tone', () => {
    for (const id of ['review-location-in', 'review-location-out']) {
        assert.match(
            page,
            new RegExp(
                `class="text-xs text-muted-foreground"\\s*data-test="${id}"`,
            ),
        );
    }

    assert.doesNotMatch(page, /r\.location[^\n]*(destructive|danger|warning)/);
});

test('the Director turns it on at one branch with a consent text, and turning it off asks first', () => {
    assert.match(card, /'\/hr\/attendance\/location-check'/);
    assert.match(card, /data-test="location-check-blocked"/);
    assert.match(card, /:disabled="form\.processing \|\| !!blockedBy"/);
    assert.match(card, /Synthetic data only until the PDPA review\./);
    assert.match(card, /OperationalConfirmDialog[\s\S]{0,200}form\.confirmOff/);
    assert.match(
        page,
        /<LocationCheckSettings\s+v-if="attendance\.settings\.location"/,
    );
});
