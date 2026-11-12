import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UI-RESTYLE D4a (owner: "start D4a on a branch"; GUIDE section 19): the Dispensary page frame, header and left column
// (patient card, allergy panels, OTC allergy step and form) and the allergy-match warning. Layout and colour only.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const flat = (text) => text.replace(/\s+/g, ' ');
const show = read('resources/js/pages/Dispensary/Show.vue');
const left = flat(
    show.slice(
        show.indexOf('data-testid="dispensary-left"'),
        show.indexOf('data-testid="dispensary-main"'),
    ),
);
const warning = flat(
    read('resources/js/pages/Dispensary/Partials/AllergyMatchWarning.vue'),
);
const form = flat(
    read('resources/js/pages/Dispensary/Partials/OtcAllergyRecordForm.vue'),
);
const palette =
    /\b(bg|text|border|accent)-(slate|gray|zinc|amber|pink|red|emerald|green|blue)-\d/;

test('the left column and the warning use tokens only, with no dark: overrides', () => {
    for (const part of [left, warning, form]) {
        assert.equal(palette.test(part), false);
        assert.equal(part.includes('dark:'), false);
    }
});

test('the patient card is a 14 px card with a sentence-case label and a 17 px name', () => {
    assert.ok(left.includes('rounded-[14px] border bg-card p-4 text-sm'));
    assert.ok(
        left.includes(
            '<p class="text-[13px] font-medium text-muted-foreground"> Patient </p>',
        ),
    );
    assert.ok(left.includes('text-[17px] leading-snug font-semibold'));
    assert.equal(left.includes('uppercase'), false);
});

test('both allergy panels use the success and warning tokens', () => {
    assert.equal(
        (
            left.match(
                /'border-success\/30 bg-success\/10' : 'border-warning\/30 bg-warning\/10'/g,
            ) ?? []
        ).length,
        2,
    );
});

test('the allergy-match warning keeps its red tone, words and required tick', () => {
    assert.ok(warning.includes('border-destructive/40 bg-destructive/10'));
    assert.ok(warning.includes('class="size-4 accent-destructive"'));
    assert.ok(warning.includes('I have checked with the patient/doctor'));
});

test('the header button is 36 px from 1024 px; the page keeps its wide frame', () => {
    assert.ok(
        flat(show).includes(
            '<Button as-child variant="outline" size="sm" class="lg:h-9">',
        ),
    );
    assert.ok(show.includes('max-w-[1500px] px-4 py-6'));
    assert.equal(form.includes('sm:'), false);
});
