import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const show = readFileSync(
    new URL('../../resources/js/pages/Dispensary/Show.vue', import.meta.url),
    'utf8',
);
const flat = show.replace(/\s+/g, ' ');
const right = flat.slice(flat.indexOf('data-testid="dispensary-right"'));

test('the right column headings are sentence case, not small capitals (Daniel)', () => {
    assert.ok(
        right.includes(
            '<h2 class="mb-3 text-[15px] font-semibold"> This dispensary </h2>',
        ),
    );
    assert.ok(
        right.includes(
            '<h2 class="mb-1 text-[15px] font-semibold">Actions</h2>',
        ),
    );
    assert.equal(right.includes('uppercase'), false);
});

test('the summary figures are right-aligned in fixed-width digits', () => {
    assert.ok(
        right.includes(
            '[&_dd]:text-right [&_dd]:font-medium [&_dd]:tabular-nums',
        ),
    );
});

test('actions fill the card and are 44 px tall below 1024 px, 36 px from it', () => {
    assert.ok(
        right.includes(
            'rounded-[14px] border bg-card p-4 text-sm [&>button]:h-11 [&>button]:w-full lg:[&>button]:h-9',
        ),
    );
    assert.ok(right.includes('trigger-class="h-11 w-full lg:h-9"'));
    assert.equal(right.includes('trigger-class="h-8 w-40"'), false);
});

test('the verify panel uses the primary tokens, never pink', () => {
    assert.ok(
        right.includes('border border-primary/40 bg-primary/10 p-4 text-sm'),
    );
    assert.equal(/pink-\d/.test(right), false);
    assert.ok(
        right.includes(
            'mt-2 flex flex-col gap-2 sm:flex-row [&>button]:h-11 lg:[&>button]:h-9',
        ),
    );
});

test('button wording is unchanged (the Malay guides quote it)', () => {
    for (const text of [
        'Start Dispensing',
        'Return to Doctor',
        'Complete Dispensary',
        'Confirm and complete',
        'Verify before completing',
        'Call patient',
    ]) {
        assert.ok(right.includes(text), text);
    }
});

test('Complete Dispensary steps back to outline while the verify panel is open (Daniel)', () => {
    assert.ok(
        right.includes(
            'v-if="dispensary.can.complete" :variant="confirming ? \'outline\' : \'default\'"',
        ),
    );
});
