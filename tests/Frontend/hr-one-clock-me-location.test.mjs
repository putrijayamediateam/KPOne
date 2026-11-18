import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// One Clock in, step 1 (owner, 18 Nov 2026): the Me press runs the branch's location check inside the camera dialog
// (consent once, then one location read), so nobody is sent to the Attendance page to clock in.
const read = (path) => readFileSync(path, 'utf8');
const photo = read('resources/js/composables/useClockPhoto.ts');
const dialog = read(
    'resources/js/components/hr/attendance/ClockPhotoDialog.vue',
);
const consent = read(
    'resources/js/components/hr/attendance/ClockLocationConsent.vue',
);
const shortcut = read('resources/js/composables/useClockShortcut.ts');
const me = read('app/Domain/DepartmentSpace/MeSpace.php');

test('the clock state sends the location check instead of a page to visit', () => {
    assert.match(me, /'location' => is_array\(\$my\['location'\] \?\? null\)/);
    assert.match(me, /'consentNeeded' => \(bool\)/);
    // The ring never carries the last result or a position.
    assert.doesNotMatch(me, /needsAttendancePage/);
    assert.doesNotMatch(
        me.slice(me.indexOf("'location' =>")),
        /^\s*'lastResult'/m,
    );
    assert.doesNotMatch(shortcut, /needsAttendancePage/);
    assert.match(shortcut, /clock\.location = data\.location \?\? null/);
});

test('the press reads the state fresh, so a consent or press made elsewhere counts', () => {
    assert.match(shortcut, /void pressFresh\(returnFocusTo\)/);
    assert.match(shortcut, /const pressFresh = async/);
    assert.match(shortcut, /await load\(\);/);
});

test('open() starts with consent when it is still needed, else with one location read, else the camera', () => {
    assert.match(photo, /\| 'consent'\s*\| 'locating'/);
    assert.match(
        photo,
        /state\.step = state\.location\s*\? state\.location\.consentNeeded\s*\? 'consent'\s*: 'locating'\s*: 'starting'/,
    );
    // The reset clears the location with the rest of the flow.
    assert.match(photo, /state\.extra = \{\};\s*state\.location = null;/);
});

test('the location is read once, at the press, never cached, and every outcome still records the press', () => {
    assert.equal(photo.match(/getCurrentPosition/g)?.length, 1);
    assert.match(photo, /maximumAge: 0/);
    assert.match(photo, /consent\s*\? \{ location_consent: true \}/);
    assert.match(photo, /location_unavailable: 'failed'/);
    assert.match(photo, /error\.code === 1 \? 'denied' : 'failed'/);
    assert.match(
        photo,
        /WITHOUT_LOCATION = \{ location_unavailable: 'declined' \}/,
    );
    // Nothing about the reading is kept in the browser.
    assert.doesNotMatch(photo, /localStorage|sessionStorage/);
});

test('the dialog runs consent, then the read, then the camera, and ignores a read for a closed flow', () => {
    assert.match(dialog, /<ClockLocationConsent/);
    assert.match(dialog, /@agree="checkLocation\(true\)"/);
    assert.match(dialog, /@without="withoutLocation"/);
    assert.match(dialog, /@cancel="close"/);
    assert.match(dialog, /data-test="clock-location-checking"/);
    assert.match(dialog, /Checking your location…/);
    assert.match(
        dialog,
        /if \(state\.key !== key \|\| state\.step !== 'locating'\) \{\s*return;/,
    );
    assert.match(
        dialog,
        /state\.extra = \{ \.\.\.state\.extra, \.\.\.extra \};\s*state\.step = 'starting';\s*await begin\(\);/,
    );
    assert.match(dialog, /void checkLocation\(false\)/);
});

test('consent: the branch wording word for word, in English and Malay, with two equal outline choices', () => {
    assert.match(consent, /Location check at this branch/);
    assert.match(
        consent,
        /Read this once\. You can clock \{\{ word \}\} without it\./,
    );
    assert.match(
        consent,
        /Draft wording until the clinic's PDPA review\. Synthetic data only\./,
    );
    assert.match(consent, /\{\{ location\.consentEn \}\}/);
    assert.match(consent, /lang="ms"[\s\S]*\{\{ location\.consentBm \}\}/);
    assert.match(consent, /I understand, check my location/);
    assert.match(consent, /Clock \{\{ word \}\} without location/);
    // Neither choice is filled or preselected; both use the same outline class.
    assert.equal(consent.match(/:class="choice"/g)?.length, 2);
    assert.doesNotMatch(consent, /bg-primary/);
    assert.match(consent, /min-h-12/);
    assert.match(consent, /aria-label="Close"/);
});
