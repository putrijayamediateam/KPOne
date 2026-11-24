import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );

const banner = read('resources/js/components/ReadOnlyBanner.vue');
const show = read('resources/js/pages/Dispensary/Show.vue');
const completed = read('resources/js/pages/Dispensary/Completed.vue');
const labels = read('resources/js/pages/Dispensary/Labels.vue');
const panel = read(
    'resources/js/pages/Clinical/Partials/DispensaryCheckPanel.vue',
);

test('DSP-26: the banner takes its own line and keeps the Director default', () => {
    assert.match(banner, /message: 'Read only\. Every view is recorded\.'/);
    assert.match(banner, /\{\{ message \}\}/);
});

test('DSP-26: the returns section hides the CA instructions for a viewer', () => {
    const returns = read(
        'resources/js/pages/Dispensary/Partials/MedicineReturnsSection.vue',
    );
    assert.match(
        returns,
        /<p v-if="!viewOnly" class="-mt-2 text-sm text-muted-foreground"> Record medicine/,
    );
    assert.match(returns, /<p v-if="!viewOnly" role="note"/);
    assert.match(completed, /:view-only="dispensary\.viewOnly"/);
});

test('DSP-26: the case and Completed pages say "View only" and never "Every view is recorded"', () => {
    for (const page of [show, completed]) {
        assert.match(
            page,
            /<ReadOnlyBanner v-if="dispensary\.viewOnly" class="m[by]-4" message="View only\. You cannot change this case\."/,
        );
        assert.ok(!page.includes('Every view is recorded'));
        assert.match(page, /\{\{ dispensary\.backLink\.label \}\}/);
    }
});

test('DSP-26: the case page removes the actions card and CA instructions for a viewer', () => {
    assert.match(
        show,
        /<section v-if="!dispensary\.viewOnly" class="flex flex-col gap-2 rounded-xl border bg-card p-4 text-sm" data-testid="dispensary-actions"/,
    );
    assert.match(
        show,
        /<p v-if="!dispensary\.viewOnly" class="text-xs"> Correct the case, then send it to the doctor again\./,
    );
    assert.match(
        show,
        /<template v-if="!dispensary\.viewOnly"> <dt class="text-muted-foreground">Not saved yet<\/dt>/,
    );
});

test('DSP-26: the CA\u2019s header is unchanged and the viewer gets its own', () => {
    assert.match(
        show,
        /<PageHeader v-if="dispensary\.viewOnly" class="mb-4 border-b pb-3" title="Dispensary" description="The medicine the CA prepared for this visit\."/,
    );
    assert.match(
        show,
        /<PageHeader v-else class="mb-4 border-b pb-3" title="Dispensary" description="Check each line, save it, then complete\."/,
    );
});

test('DSP-26: "View labels" follows canPreviewLabels and Print follows canPrintLabels', () => {
    assert.match(
        show,
        /v-if="visibleItems\.length && dispensary\.canPreviewLabels"/,
    );
    assert.match(show, /visibleItems\.length && dispensary\.canPrintLabels &&/);
    assert.match(show, /data-testid="view-labels"/);
    // Completed keeps its two outline buttons: for the doctor they read "View labels" and the back link.
    assert.match(
        completed,
        /dispensary\.viewOnly \? 'view-labels' : undefined/,
    );
    assert.match(
        completed,
        /<Link v-if="dispensary\.backLink" :href="dispensary\.backLink\.href"/,
    );
    assert.match(show, /canPreviewLabels\?: boolean;/);
});

test('DSP-26: the label preview shows no Print button or checklist for a viewer', () => {
    assert.match(labels, /labelCanPrint\?: boolean;/);
    assert.match(
        labels,
        /const canPrint = computed\(\(\) => props\.labelCanPrint !== false\);/,
    );
    assert.match(
        labels,
        /<p v-if="!canPrint" data-testid="label-view-only"> View only\. Printing is done by the CA\. <\/p>/,
    );
    assert.match(labels, /<Button v-if="canPrint" :disabled=/);
    assert.match(labels, /<p v-if="canPrint"> Label size/);
    assert.match(labels, /token: string \| null;/);
});

test('DSP-26: the doctor’s check panel links to the case in Dispensary', () => {
    assert.match(
        panel,
        /<Link v-if="check\.viewUrl" :href="check\.viewUrl"[^>]*data-testid="dispensary-check-view" >View in Dispensary<\/Link >/,
    );
});
