import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// REG-MON-1: two small layout fixes (DD-124 is held for a coordinator decision) for the Puchong CA live test (frontend only).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the phone country select keeps a readable width from 640 px', () => {
    const phone = read('resources/js/components/patient/PhoneInput.vue');
    assert.match(phone, /trigger-class="[^"]*\bsm:min-w-48\b[^"]*"/);
});

test('DD-130: the Cancel Visit icon sits in its own grid column', () => {
    const show = read('resources/js/pages/Registration/Show.vue');
    assert.match(
        show,
        /class="grid w-full grid-cols-\[minmax\(0,1fr\)_auto\] items-center gap-3 p-4 text-left"/,
    );
    assert.match(show, /<Ban class="size-4 shrink-0 text-destructive" \/>/);
});
