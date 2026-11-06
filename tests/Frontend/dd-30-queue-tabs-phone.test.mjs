import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-30: the Queue board tab strip fits a phone (Yasmin's option A, Daniel's look).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Queue/Index.vue');

test('on phones the live line sits under a full-width strip with 44 px tabs', () => {
    assert.match(
        page,
        /ref="tabStrip"\s*class="flex items-center gap-2 border-b max-sm:flex-col max-sm:items-stretch max-sm:gap-0 max-sm:border-b-0"/,
    );
    assert.match(
        page,
        /class="min-w-0 flex-1 max-sm:flex-none max-sm:\[&>button\]:h-11"/,
    );
    assert.match(
        page,
        /class="shrink-0 text-\[11px\] text-muted-foreground max-sm:px-3 max-sm:py-1\.5"/,
    );
});

test('a chosen tab that is partly hidden scrolls fully into view, sideways only', () => {
    assert.match(
        page,
        /const selectOperationalTab = \(value: string\) => \{\s*selectTab\(value as QueueTab\);\s*void revealSelectedTab\(\);/,
    );
    assert.match(
        page,
        /querySelector<HTMLElement>\('\[aria-selected="true"\]'\)/,
    );
    assert.match(page, /nav\.scrollLeft -= navBox\.left - tabBox\.left/);
    assert.match(page, /nav\.scrollLeft \+= tabBox\.right - navBox\.right/);
    assert.doesNotMatch(page, /scrollIntoView/);
});

test('the shared tab component is not changed by DD-30', () => {
    const tabs = read('resources/js/components/ui/tabs/OperationalTabs.vue');

    assert.match(tabs, /class="relative flex h-10 shrink-0/);
    assert.match(tabs, /:aria-selected="modelValue === tab\.value"/);
});
