import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const show = read('resources/js/pages/Dispensary/Show.vue');
const completed = read('resources/js/pages/Dispensary/Completed.vue');
const labels = read('resources/js/pages/Dispensary/Labels.vue');

test('no Dispensary row spans more columns than the two-column tablet grid has', () => {
    // A span wider than the grid makes the browser add near-zero-width columns (the 768 px defect).
    assert.ok(show.includes('sm:grid-cols-2 lg:grid-cols-4'));
    assert.equal(/\bsm:col-span-[3-9]\b/.test(show), false);
    assert.ok(show.includes('sm:col-span-full'));
    assert.ok(show.includes('sm:col-span-2 lg:col-span-3'));
});

test('Dispensary controls are at least 44 px tall on phones only', () => {
    for (const page of [show, completed]) {
        assert.ok(page.includes('max-sm:[&_[data-slot=button]]:min-h-11'));
        assert.ok(
            page.includes(
                'max-sm:[&_input:not([type=radio]):not([type=checkbox])]:min-h-11',
            ),
        );
        assert.ok(page.includes('max-sm:[&_select]:min-h-11'));
        assert.ok(
            page.includes('max-sm:[&_[data-slot=select-trigger]]:min-h-11'),
        );
        assert.ok(page.includes('max-sm:[&_textarea]:min-h-11'));
    }

    assert.ok(labels.includes('@media screen and (max-width: 639.98px)'));
    assert.ok(labels.includes('min-height: 44px;'));
});

test('one reprint among several labels reads "was", more read "were"', () => {
    assert.ok(
        labels.includes(
            "'1 of these labels was printed before. It will print marked REPRINT.'",
        ),
    );
    assert.ok(labels.includes('of these labels were printed before.'));
});

test('the OTC allergy card says the check also uses catalogue drug groups (DSP-1b)', () => {
    const text = show.replace(/\s+/g, ' ');
    assert.ok(
        text.includes(
            'compared with medicine names and catalogue drug groups by text only',
        ),
    );
    assert.equal(
        text.includes('compared with medicine names by text only'),
        false,
    );
});
