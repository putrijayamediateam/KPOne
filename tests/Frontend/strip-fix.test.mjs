import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const strip = readFileSync(
    'resources/js/composables/useScrollStrip.ts',
    'utf8',
);
const space = readFileSync('resources/js/pages/Spaces/Show.vue', 'utf8');
const insights = readFileSync('resources/js/pages/Insights/Report.vue', 'utf8');

test('the strip composable brings the current item to the start and fades a hidden edge', () => {
    assert.match(
        strip,
        /el\.scrollLeft \+= item\.left - box\.left - START_PADDING/,
    );
    assert.match(strip, /moreBefore\.value = !!el && el\.scrollLeft > 2/);
    assert.match(strip, /maskImage: gradient/);
});

test('the space shell and the Insights tabs share the strip fix', () => {
    assert.match(space, /useScrollStrip\(side, '\[aria-current="page"\]'\)/);
    assert.match(insights, /useScrollStrip\(\s*reportTabs/);
    assert.match(
        insights,
        /:aria-current="item\.id === section \? 'page' : undefined"/,
    );
});

test('the Insights tabs are 44 px tall below 1024 px', () => {
    assert.match(insights, /max-lg:min-h-11/);
});
