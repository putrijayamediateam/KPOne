import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/HR/Attendance.vue');
const card = read(
    'resources/js/components/hr/attendance/LocationCheckSettings.vue',
);

test('the location is read once, at the press, and never tracked or kept in the browser', () => {
    assert.equal(page.match(/getCurrentPosition\(/g)?.length, 1);
    assert.doesNotMatch(
        page + card,
        /watchPosition|localStorage|sessionStorage/,
    );
    assert.match(
        page,
        /const locate = [\s\S]{0,1200}getCurrentPosition[\s\S]{0,400}clock_position: \{\s*latitude: position\.coords\.latitude,\s*longitude: position\.coords\.longitude,\s*accuracy: position\.coords\.accuracy,/,
    );
    assert.match(page, /maximumAge: 0/);
    // A refusal or a failure still sends the press.
    assert.match(
        page,
        /location_unavailable: error\.code === 1 \? 'denied' : 'failed'/,
    );
    assert.match(page, /location_unavailable: 'failed'/);
    // Off at the branch: the press is sent exactly as before, with nothing extra.
    assert.match(page, /if \(!location\) \{\s*send\(kind, \{\}\);/);
});

test('consent is shown once, in English and Bahasa Melayu, and clocking in without location is always offered', () => {
    assert.match(page, /data-test="location-consent"/);
    assert.match(page, /consentEn[\s\S]{0,400}lang="ms"[\s\S]{0,300}consentBm/);
    assert.match(
        page,
        /data-test="location-without"[\s\S]{0,200}withoutLocation\(/,
    );
    assert.match(page, /location_unavailable: 'declined'/);
    assert.match(
        page,
        /data-test="location-agree"[\s\S]{0,200}locate\(clock\.consentFor \?\? 'in', true\)/,
    );
    assert.match(page, /data-test="location-note"/);
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
