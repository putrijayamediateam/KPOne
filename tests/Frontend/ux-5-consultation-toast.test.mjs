import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const squash = (value) => value.replace(/\s+/g, ' ');

test('UX-5: desktop consultation-page toasts show at the bottom left', () => {
    const source = read('resources/js/lib/focusToastPlacement.ts');

    assert.match(source, /FOCUS_DESKTOP_TOAST_MEDIA = '\(min-width: 1024px\)'/);
    assert.match(source, /position: 'bottom-left'/);
    // No 64 px top offset: that belongs to the shared top placement.
    const placement = source.match(
        /FOCUS_DESKTOP_TOAST_PLACEMENT: ToasterProps = \{[\s\S]*?\};/,
    )?.[0];

    assert.ok(placement);
    assert.doesNotMatch(placement, /offset|\btop/);
    // Bottom left is clear because the action bar sits in the right-hand column.
    assert.match(
        read('resources/js/pages/Clinical/Show.vue'),
        /lg:grid-cols-\[minmax\(0,55fr\)_minmax\(0,45fr\)\]/,
    );
});

test('UX-5: only focus mode on a desktop width leaves the shared staff placement', () => {
    const layout = squash(read('resources/js/layouts/ClinicLayout.vue'));

    assert.match(
        layout,
        /const desktop = useMediaQuery\(FOCUS_DESKTOP_TOAST_MEDIA\);/,
    );
    assert.match(
        layout,
        /props\.focusMode && desktop\.value \? FOCUS_DESKTOP_TOAST_PLACEMENT : STAFF_TOAST_PLACEMENT/,
    );
    assert.match(layout, /<Toaster v-bind="toastPlacement" \/>/);

    // The consultation page is the only focus-mode page; the admin layout is untouched.
    const focusPages = readdirSync(
        new URL('../../resources/js/pages', import.meta.url),
        { recursive: true },
    )
        .filter((file) => file.endsWith('.vue'))
        .filter((file) =>
            /focusMode:\s*true/.test(read(`resources/js/pages/${file}`)),
        );

    assert.deepEqual(focusPages, ['Clinical/Show.vue']);
    assert.match(
        read('resources/js/layouts/AdminLayout.vue'),
        /<Toaster v-bind="STAFF_TOAST_PLACEMENT" \/>/,
    );
});
