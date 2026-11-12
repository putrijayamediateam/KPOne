import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const v2 = (n) => read(`resources/js/components/v2/${n}.vue`);

test('V2OptionGroup: named pressed buttons, 44px, cobalt tint and outline when pressed', () => {
    const s = v2('V2OptionGroup');
    assert.match(s, /role="group"/);
    assert.match(s, /:aria-label="label"/);
    assert.match(s, /:aria-pressed="option\.value === modelValue"/);
    assert.match(s, /h-11/);
    assert.match(s, /border-primary bg-primary\/10 text-primary/);
    assert.match(s, /role="alert"/);
});

test('V2FormCard: a numbered section whose title is its heading', () => {
    const s = v2('V2FormCard');
    assert.match(s, /<section[\s\S]*:aria-labelledby="id"/);
    assert.match(s, /<h2 :id="id"/);
    assert.match(s, /step\?: number/);
});

test('V2PatientPickRow: a 56px radio row, success tint when selected, identifier already masked by the page', () => {
    const s = v2('V2PatientPickRow');
    assert.match(s, /min-h-14/);
    assert.match(s, /role="radio"/);
    assert.match(s, /:aria-checked="selected"/);
    assert.match(s, /border-success bg-success-soft/);
    assert.match(s, /maskedIdentifier/);
});

test('V2StickySummary and V2StickyBar: a sticky rail from 1024px and a phone bar with safe-area padding', () => {
    const rail = v2('V2StickySummary');
    assert.match(rail, /max-sm:hidden/);
    assert.match(rail, /lg:sticky lg:top-4/);
    assert.match(rail, /<dl/);
    const bar = v2('V2StickyBar');
    assert.match(bar, /sm:hidden/);
    assert.match(bar, /env\(safe-area-inset-bottom\)/);
    assert.match(bar, /\[&>\*\]:h-11/);
});

test('The new blocks import nothing from the Classic ui components', () => {
    for (const n of [
        'V2OptionGroup',
        'V2FormCard',
        'V2PatientPickRow',
        'V2StickySummary',
        'V2StickyBar',
    ]) {
        assert.doesNotMatch(v2(n), /from '@\/components\/ui\//);
    }
});
