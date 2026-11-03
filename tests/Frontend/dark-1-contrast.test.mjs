import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('S1-001 highlighted patient result has a dark-mode background', () => {
    assert.match(
        read('resources/js/pages/Registration/Create.vue'),
        /'bg-emerald-50\/80 dark:bg-emerald-950\/40'/,
    );
});

test('S1-006 selected Google-only card has dark-mode colours', () => {
    assert.match(
        read('resources/js/pages/Staff/Create.vue'),
        /bg-emerald-50\/60 dark:border-emerald-900 dark:bg-emerald-950\/30/,
    );
});

test('S1-007 OTC allergy card and Recorded text have dark-mode colours', () => {
    const source = read('resources/js/pages/Dispensary/Show.vue');
    assert.match(
        source,
        /bg-emerald-50\/50 dark:border-emerald-900 dark:bg-emerald-950\/30/,
    );
    assert.match(
        source,
        /bg-amber-50\/70 dark:border-amber-700 dark:bg-amber-950\/30/,
    );
    assert.match(source, /text-emerald-800 dark:text-emerald-300"\s*>Recorded/);
});

test('S1-008 Consultation Queue card has dark-mode colours', () => {
    assert.match(
        read('resources/js/pages/Registration/Show.vue'),
        /bg-emerald-50\/40 p-4 dark:border-emerald-900 dark:bg-emerald-950\/30/,
    );
});
