import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// REG-9: the Patient and Registration screens use theme tokens, not fixed pale palette colours that have no dark style.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const files = [
    'resources/js/pages/Patient/Create.vue',
    'resources/js/pages/Patient/Edit.vue',
    'resources/js/pages/Patient/Index.vue',
    'resources/js/pages/Patient/Show.vue',
    'resources/js/pages/Patient/MergeRequests.vue',
    'resources/js/components/patient/PatientMergePanel.vue',
    'resources/js/pages/Registration/Show.vue',
    'resources/js/pages/RegistrationReview/Show.vue',
];

test('these screens keep no pale palette fill, text or border without a dark style', () => {
    for (const path of files) {
        const source = read(path);

        assert.doesNotMatch(
            source,
            /(?<!dark:)\b(?:bg|text|border)-(?:amber|red|sky|pink)-\d/,
            path,
        );
    }
});

test('the old emerald accents on the Patient screens are tokens', () => {
    for (const path of files.slice(0, 5)) {
        const source = read(path);

        assert.doesNotMatch(source, /\bemerald-\d/, path);
    }
});

test('warning notices use the warning tokens', () => {
    for (const path of [
        'resources/js/pages/Patient/Create.vue',
        'resources/js/pages/Patient/Edit.vue',
        'resources/js/pages/Registration/Create.vue',
    ]) {
        assert.match(
            read(path),
            /border-warning\/25 bg-warning\/10 text-warning-strong/,
            path,
        );
    }
});

test('the Visit Reason search box and its chips are at least 40 px tall', () => {
    const picker = read('resources/js/components/visit/VisitReasonPicker.vue');

    assert.match(picker, /h-10 w-full rounded-md border border-input/);
    assert.doesNotMatch(picker, /\b(?:h|min-h)-9\b/);
});
