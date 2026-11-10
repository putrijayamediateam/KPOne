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
        const source = read(path);

        for (const token of [
            'border-warning/25',
            'bg-warning/10',
            'text-warning-strong',
        ]) {
            assert.ok(source.includes(token), `${path} ${token}`);
        }
    }
});

test('the Visit Reason search box is 40 px on desktop and 44 px below 1024 px', () => {
    const picker = read('resources/js/components/visit/VisitReasonPicker.vue');

    assert.match(picker, /class="h-10 [^"]*max-lg:h-11"/);
    assert.match(picker, /class="flex min-h-10 [^"]*max-lg:min-h-11"/);
    assert.doesNotMatch(picker, /\b(?:h|min-h)-9\b/);
});
