import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const squash = (value) => value.replace(/\s+/g, ' ');

test('UX-5: desktop consultation-page toasts show at the bottom left', () => {
    const source = read('resources/js/lib/focusToastPlacement.ts');

    assert.match(source, /FOCUS_DESKTOP_TOAST_MEDIA = '\(min-width: 1024px\)'/);
    assert.match(source, /position: 'bottom-left'/);
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
    assert.match(
        read('resources/js/pages/Clinical/Show.vue'),
        /focusMode: true/,
    );
    assert.match(
        read('resources/js/layouts/AdminLayout.vue'),
        /<Toaster v-bind="STAFF_TOAST_PLACEMENT" \/>/,
    );
});
