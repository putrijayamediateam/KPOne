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
        /class="min-w-0 flex-1 max-sm:flex-none max-sm:\[&>button\]:h-11 max-sm:\[&>button\]:px-2\.5"/,
    );
    assert.match(
        page,
        /class="shrink-0 text-\[13px\] text-muted-foreground max-sm:px-3 max-sm:py-1\.5"/,
    );
});

test('a chosen tab that is partly hidden scrolls fully into view, sideways only', () => {
    assert.match(
        page,
        /const selectOperationalTab = \(value: string\) => \{\s*selectTab\(value as QueueTab\);\s*void revealSelectedTab\(\)\.then\(updateTabFade\);/,
    );
    assert.match(
        page,
        /querySelector<HTMLElement>\('\[aria-selected="true"\]'\)/,
    );
    assert.match(page, /nav\.scrollLeft -= navBox\.left - tabBox\.left/);
    assert.match(page, /nav\.scrollLeft \+= tabBox\.right - navBox\.right/);
    assert.doesNotMatch(page, /scrollIntoView/);
});

test('the strip fades at its right edge only while more tabs are hidden to the right', () => {
    assert.match(
        page,
        /nav\.scrollWidth > nav\.clientWidth \+ 1 &&\s*nav\.scrollLeft \+ nav\.clientWidth < nav\.scrollWidth - 1/,
    );
    assert.match(
        page,
        /moreTabsRight\s*\?\s*'\[mask-image:linear-gradient\(to_right,#000_calc\(100%-2\.5rem\),transparent\)\]'\s*:\s*''/,
    );
    assert.match(
        page,
        /addEventListener\('scroll', updateTabFade, \{ passive: true \}\)/,
    );
    assert.match(page, /removeEventListener\('scroll', updateTabFade\)/);
});

test('the shared tab component is not changed by DD-30', () => {
    const tabs = read('resources/js/components/ui/tabs/OperationalTabs.vue');

    assert.match(tabs, /class="relative flex h-10 shrink-0/);
    assert.match(tabs, /:aria-selected="modelValue === tab\.value"/);
});
