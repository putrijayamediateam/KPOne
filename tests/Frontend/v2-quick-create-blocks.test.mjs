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

test('V2PatientPickList: one radio group with a roving tab stop and arrow, Home and End keys', () => {
    const s = v2('V2PatientPickList');
    assert.match(s, /role="radiogroup"/);
    assert.match(s, /:aria-label="label"/);
    assert.match(s, /ArrowDown/);
    assert.match(s, /ArrowUp/);
    assert.match(s, /'Home'/);
    assert.match(s, /'End'/);
    assert.match(s, /:tab-stop="tabStop\(index\)"/);
    assert.match(
        v2('V2PatientPickRow'),
        /:tabindex="tabStop === false \? -1 : 0"/,
    );
});

test('V2OptionGroup uses aria-pressed consistently (every option is a pressed button inside a named group)', () => {
    const s = v2('V2OptionGroup');
    assert.match(s, /role="group"/);
    assert.doesNotMatch(s, /role="radio"/);
    assert.match(s, /:aria-pressed=/);
});

test('V2StickyBar reads the rail breakpoint from V2WorkLayout; the prop overrides; no rail keeps sm:hidden; always never hides', () => {
    const bar = v2('V2StickyBar');
    assert.match(bar, /inject\(V2_RAIL_FROM, null\)/);
    assert.match(bar, /props\.railFrom \?\? layoutRailFrom\?\.value \?\? null/);
    assert.match(bar, /from === null\s*\? 'sm:hidden'/);
    assert.match(bar, /lg: 'lg:hidden', xl: 'xl:hidden', '2xl': '2xl:hidden'/);
    assert.match(bar, /if \(props\.always\) \{\s*return '';/);
    assert.match(
        bar,
        /railFrom\?: RailFrom;\s+always\?: boolean;\s+hideFrom\?: 'sm' \| RailFrom;/,
    );
    assert.match(bar, /:class="hideClass"/);
});

test('V2StickyBar: the row is capped to form width, right-aligned from 640px with natural-width buttons, stacked full-width below', () => {
    const bar = v2('V2StickyBar');
    assert.match(bar, /data-slot="v2-sticky-bar-row"/);
    assert.match(
        bar,
        /flex w-full max-w-3xl flex-col gap-2 sm:flex-row sm:justify-end/,
    );
    assert.match(
        bar,
        /\[&>\*\]:h-11 \[&>\*\]:w-full sm:\[&>\*\]:w-auto sm:\[&>\*\]:min-w-40/,
    );
    assert.match(bar, /pb-\[max\(0\.75rem,env\(safe-area-inset-bottom\)\)\]/);
});
