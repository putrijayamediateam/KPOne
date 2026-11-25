import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-22f locum free times (HR-PLAN 29.7; owner, via B.E.R.N.I.S.S, 24 Nov 2026).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/HR/OpenShifts.vue');
const tabs = read('resources/js/composables/hr/useOpenShiftsPage.ts');
const view = read('resources/js/components/hr/open-shifts/FreeTimes.vue');
const composable = read('resources/js/composables/hr/useFreeTimes.ts');

test('the Open shifts page has a Free times tab that shows its own section', () => {
    assert.match(tabs, /free: 'Free times'/);
    assert.match(page, /v-else-if="openShifts\.tab === 'free' && freeTimes"/);
    assert.match(page, /<FreeTimes/);
});

test('a locum offers a free time with an optional note only HR sees, and may withdraw it while it waits', () => {
    assert.match(view, />Offer a free time</);
    assert.match(
        view,
        /Only you and HR see it\. Do not write patient\s+details\./,
    );
    assert.match(view, /maxlength="200"/);
    assert.match(view, /\{\{ offer\.note\.length \}\} \/ 200/);
    assert.match(view, /v-if="f\.status === 'waiting'"/);
    assert.match(composable, /'\/hr\/free-times'/);
    assert.match(composable, /\/withdraw`/);
});

test('HR uses a free time inside the offered time, or declines it with a reason', () => {
    assert.match(view, /data-test="free-time-use"/);
    assert.match(view, /The\s+shift must sit inside that time/);
    assert.match(view, /v-if="using\.item\?\.takesStation"/);
    assert.match(view, /:options="freeTimes\.declineReasons"/);
    assert.match(composable, /\/use`/);
    assert.match(composable, /\/decline`/);
});

test('every write carries the lock version and an idempotency key', () => {
    assert.equal(
        (composable.match(/expected_lock_version: item\.lockVersion/g) ?? [])
            .length,
        3,
    );
    assert.match(composable, /idempotency_key: offer\.key/);
    assert.match(composable, /idempotency_key: using\.key/);
});

test('Daniel: one filled button per view, neutral waiting chip, the window said before the click', () => {
    assert.match(view, />Use this time</);
    assert.doesNotMatch(view, /'warning'/);
    assert.match(composable, /status === 'used' \? 'success' : 'neutral'/);
    assert.match(view, /Between \{\{ using\.item\?\.startLabel \}\} and/);
    assert.match(composable, /Keep the shift between/);
    assert.match(view, /Note from the locum:/);
    assert.match(view, /max-sm:sticky max-sm:bottom-0/);
    assert.match(
        page,
        /Free times offered by locums\. Use one to add a draft shift, or decline it\./,
    );
    assert.match(
        page,
        /v-if="openShifts\.canManage && openShifts\.tab === 'open'"/,
    );
});
