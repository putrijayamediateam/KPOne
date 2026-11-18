import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const squash = (s) => s.replace(/\s+/g, ' ');
const page = read('resources/js/pages/Spaces/Show.vue');
const css = read('resources/css/space-shell-layout.css');

test('UX-24 part 2 (Daniel): the cash closing status and the roster status are the dot-and-word V2 chip', () => {
    assert.match(page, /import V2Chip from '@\/components\/v2\/V2Chip\.vue'/);
    assert.match(
        squash(page),
        /<V2Chip :tone="cashTone\(billing\.cashClosing\.status\)" :label="billing\.cashClosing\.statusText" \/>/,
    );
    assert.match(
        squash(page),
        /<V2Chip :tone="rosterTone\(hr\.roster\.status\)" :label="hr\.roster\.status"/,
    );
    // The old pill is gone from the cash closing card.
    assert.doesNotMatch(
        squash(page),
        /class="space-pill" :class="`is-\$\{billing\.cashClosing\.status\}`"/,
    );
    // Colour is not the only signal: the chip carries the word, and the tone map is explicit.
    assert.match(page, /status === 'not_counted'/);
    assert.match(page, /status === 'Waiting for approval'/);
});

test('UX-24 part 2 (Daniel): the roster week keeps its status beside the date and its right side on one line', () => {
    assert.match(
        css,
        /\.space-shell \.space-roster-head \{[^}]*display: inline-flex;/,
    );
    assert.match(
        css,
        /\.space-shell \.space-roster-short \{\s*white-space: nowrap;/,
    );
});

test('UX-24 part 2 (Daniel): the Today at a glance labels are 13 px on a phone (12 px or larger, Daniel)', () => {
    assert.match(
        css,
        /@media \(max-width: 639px\) \{[\s\S]*\.space-shell \.space-stats \.space-muted \{\s*font-size: 13px;/,
    );
});

test('UX-24 part 2 (Daniel): Consultation shows one line when nobody waits, and Pharmacy has a filled Open Dispensary list action', () => {
    assert.match(
        squash(page),
        /v-if=" consultation\.next && consultation\.waiting\.rows\.length > 0 "/,
    );
    assert.match(page, /const PHARMACY_PRIMARY = 'Open Dispensary list';/);
    assert.match(page, /pharmacyPrimary\.value \?\?/);
    assert.match(page, /v-for="l in pharmacyOpenLinks"/);
});
