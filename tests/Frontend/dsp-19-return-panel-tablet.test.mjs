import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DSP-19 (design debt agreed with Daniel 2026-11-10): the "Check this return" panel follows DD-24, so its targets are
// 44 px and its buttons stack below 1024 px, as on the rest of the Dispensary, not only below 640 px.
const section = readFileSync(
    new URL(
        '../../resources/js/pages/Dispensary/Partials/MedicineReturnsSection.vue',
        import.meta.url,
    ),
    'utf8',
);
const panel = readFileSync(
    new URL(
        '../../resources/js/pages/Dispensary/Partials/ReturnCheckPanel.vue',
        import.meta.url,
    ),
    'utf8',
);

test('outcome and reason tiles and the location picker are 44 px below 1024 px', () => {
    assert.ok(panel.includes('flex min-h-11 cursor-pointer'));
    assert.ok(panel.includes('has-[:disabled]:opacity-60 lg:min-h-9'));
    assert.ok(panel.includes('trigger-class="h-11 lg:h-9"'));
    assert.equal(panel.includes('sm:min-h-9'), false);
    assert.equal(panel.includes('trigger-class="h-11 sm:h-9"'), false);
});

test('the action buttons are full width and 44 px until 1024 px, side by side from there', () => {
    assert.ok(panel.includes('class="flex flex-col gap-2 lg:flex-row"'));
    assert.equal(
        (panel.match(/class="h-11 w-full lg:h-9 lg:w-auto"/g) ?? []).length,
        4,
    );
    assert.equal(panel.includes('sm:h-9 sm:w-auto'), false);
});

test('the record-a-return form above it follows the same 1024 px switch (Hakim)', () => {
    assert.ok(section.includes('has-[:checked]:border-brand lg:min-h-9'));
    assert.ok(section.includes("'h-11 max-w-40 tabular-nums lg:h-9'"));
    assert.ok(
        section.includes('class="flex flex-col-reverse gap-2 lg:flex-row"'),
    );
    assert.equal(
        (section.match(/class="h-11 w-full lg:h-9 lg:w-auto"/g) ?? []).length,
        2,
    );
    assert.equal(/sm:min-h-9|sm:h-9|sm:flex-row/.test(section), false);
});
