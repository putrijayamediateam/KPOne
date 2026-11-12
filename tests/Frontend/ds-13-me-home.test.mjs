import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const home = readFileSync('resources/js/components/spaces/MeHome.vue', 'utf8');
const notice = readFileSync(
    'resources/js/components/spaces/ClockPhotoNotice.vue',
    'utf8',
);
const show = readFileSync('resources/js/pages/Spaces/Show.vue', 'utf8');

test('the Me home ring only opens Attendance through the shared clock shortcut', () => {
    assert.match(home, /useClockShortcut/);

    for (const id of [
        'me-time',
        'me-clock-in',
        'me-worked',
        'me-clock-out',
        'me-week',
        'me-tiles',
        'about-photos',
    ]) {
        assert.ok(home.includes(id), id);
    }

    assert.doesNotMatch(home, /router\.(post|put|patch|delete)/);
    assert.doesNotMatch(home, /break/i);
});

test('the notice sheet has a first-use and an info mode', () => {
    assert.match(notice, /About clock-in photos/);
    assert.match(notice, /first-use/);
    assert.match(notice, /info/);
});

test('the Spaces page renders MeHome for the me space', () => {
    assert.match(show, /<MeHome/);
});
