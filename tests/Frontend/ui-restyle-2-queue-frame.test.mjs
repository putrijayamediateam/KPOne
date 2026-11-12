import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// UI-RESTYLE 2 (Queue & TV, owner-authorised 2026-11-12): the Queue board frame. Layout only; the board rows, chips and tabs
// are not touched here (PatientBoard is restyled with Patients & Registration).
const path = 'resources/js/pages/Queue/Index.vue';
const source = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const template = parse(source).descriptor.template.content;

test('the page compiles', () => {
    assert.deepEqual(
        compileTemplate({ id: 'queue-frame', source: template, filename: path })
            .errors,
        [],
    );
});

test('the board keeps its 1600 px width on purpose and shows the title through PageHeader', () => {
    // Documented exception to the 1280 px frame: a live board with about 12 columns needs the width (owner decision, D1).
    assert.match(template, /max-w-\[1600px\]/);
    assert.match(template, /<PageHeader title="Consultation queue" \/>/);
    assert.doesNotMatch(template, /<h1 class="sr-only">/);
});

test('filters, pager and the treatment-room dialog are 44 px below 1024 px', () => {
    assert.match(template, /class="h-11 w-full rounded-lg[^"]*lg:h-9"/);
    assert.equal(
        (template.match(/trigger-class="max-lg:min-h-11"/g) ?? []).length,
        2,
    );
    assert.match(template, /trigger-class="h-8 w-48 max-lg:min-h-11"/);
    // Apply, Reset, four pager buttons and the two buttons of the treatment-room dialog.
    assert.equal(
        (template.match(/\sclass="max-lg:min-h-11"/g) ?? []).length,
        8,
    );
});

test('the board has 24 px above the header from 1024 px and 16 px inside the card strip', () => {
    assert.match(template, /md:px-5 lg:pt-6"/);
    assert.match(
        template,
        /gap-2 px-4 py-2 text-xs text-muted-foreground sm:flex-row/,
    );
    assert.match(template, /class="mx-4 mb-3 min-h-11"/);
    // The shown-count lines and the pager line up with the strip at 16 px.
    assert.equal(
        (
            template.match(
                /border-t px-4 py-2 text-xs text-muted-foreground/g,
            ) ?? []
        ).length,
        2,
    );
    assert.match(template, /justify-end gap-2 border-t p-4/);
});

test('the live line is 13 px and the board sits in a hairline card frame', () => {
    assert.match(template, /text-\[13px\] text-muted-foreground max-sm:px-3/);
    assert.doesNotMatch(template, /text-\[11px\]/);
    assert.match(
        template,
        /<section class="overflow-hidden rounded-xl border bg-card">/,
    );
});

test('behaviour is untouched: the same tabs, Call next, board events and refresh hooks', () => {
    assert.match(template, /<CallNextControl/);
    assert.match(template, /label="Consultation Queue status"/);
    assert.match(template, /@call="requestCall"/);
    assert.match(template, /@submit\.prevent="applyFilters"/);
    assert.match(template, /Live · every 3 seconds/);
});

test('the visible-entries line is singular for one entry', () => {
    const src = readFileSync('resources/js/pages/Queue/Index.vue', 'utf8');
    assert.match(src, /boardRows\.length === 1 \? 'entry' : 'entries'/);
});
