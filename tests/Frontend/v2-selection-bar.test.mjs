import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (p) =>
    fs.readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const s = read('resources/js/components/v2/V2SelectionBar.vue');
const layout = read('resources/js/components/v2/V2WorkLayout.vue');

test('V2SelectionBar: props, emits and no logic beyond them', () => {
    for (const p of [
        'visible: boolean',
        'summary: string',
        'actionLabel: string',
        'railFrom?: RailFrom',
        'secondaryLabel?: string',
        'announce?: string',
        'clearedMessage?: string',
        'hint?: string',
        'actionDisabled?: boolean',
        'busy?: boolean',
    ]) {
        assert.ok(s.includes(p), p);
    }

    assert.match(s, /clearedMessage: 'Selection cleared'/);
    assert.match(s, /defineEmits<\{ action: \[\]; secondary: \[\] \}>/);
});

test('V2SelectionBar reads the rail breakpoint V2WorkLayout provides; the prop is only an override', () => {
    assert.match(layout, /provide\(\s*V2_RAIL_FROM/);
    assert.match(layout, /slots\.rail \? props\.railFrom : null/);
    assert.match(s, /inject\(V2_RAIL_FROM, null\)/);
    assert.match(s, /if \(props\.railFrom\) \{\s*return props\.railFrom;/);
    assert.match(s, /layoutRailFrom \? layoutRailFrom\.value : '2xl'/);
});

test('V2SelectionBar renders nothing and announces nothing while the rail shows (a media query, not CSS)', () => {
    assert.match(s, /lg: '\(min-width: 64rem\)'/);
    assert.match(s, /xl: '\(min-width: 80rem\)'/);
    assert.match(s, /'2xl': '\(min-width: 96rem\)'/);
    assert.match(
        s,
        /window\.matchMedia\(MIN_WIDTH\[effectiveRailFrom\.value\]\)/,
    );
    assert.match(s, /props\.visible && !railShowing\.value/);
    assert.match(s, /v-if="shown"/);
    assert.match(
        s,
        /shown\.value \? \(props\.announce \?\? props\.summary\) : clearedNote\.value/,
    );
    assert.doesNotMatch(s, /lg:hidden|xl:hidden|2xl:hidden/);
    // The rail appearing on resize is not "selection cleared".
    assert.match(
        s,
        /before && !now && !props\.visible && props\.clearedMessage/,
    );
});

test('V2SelectionBar: sticky, under dialogs, safe-area padding, 44px then 36px, ring with offset', () => {
    assert.match(s, /sticky bottom-0 z-10/);
    assert.match(s, /pb-\[max\(0\.75rem,env\(safe-area-inset-bottom\)\)\]/);
    assert.match(s, /h-11[^"]*lg:h-9/);
    assert.match(
        s,
        /focus-visible:ring-offset-2 focus-visible:ring-offset-background/,
    );
    assert.match(
        read('resources/js/components/v2/V2Dialog.vue'),
        /fixed inset-0 z-50/,
    );
});

test('V2SelectionBar: one filled action, an optional outline secondary', () => {
    assert.match(s, /v-if="secondaryLabel"/);
    assert.equal((s.match(/bg-primary px-4/g) ?? []).length, 1);
    assert.match(s, /border border-border bg-card/);
});

test('V2SelectionBar: a disabled action shows its reason (hint text or slot) and warns in development without one', () => {
    assert.match(s, /data-slot="v2-selection-bar-hint"/);
    assert.match(s, /<slot name="hint">\{\{ hint \}\}<\/slot>/);
    assert.match(s, /a disabled action needs a `hint`/);
});

test('V2SelectionBar: busy keeps the label, shows a spinner, disables both buttons and sets aria-busy', () => {
    assert.match(s, /:disabled="actionDisabled \|\| busy"/);
    assert.match(s, /:disabled="busy"/);
    assert.match(s, /:aria-busy="busy"/);
    assert.match(s, /v-if="busy"/);
    assert.match(s, /animate-spin motion-reduce:animate-none/);
    assert.match(s, /\{\{ actionLabel \}\}/);
    assert.doesNotMatch(s, /Working…/);
});

test('V2SelectionBar: a polite live region that stays mounted; cleared message once; nothing when never rendered', () => {
    assert.match(s, /role="status" aria-live="polite"/);
    assert.match(
        s,
        /shown\.value\s*\?\s*\(props\.announce \?\? props\.summary\)\s*:\s*clearedNote\.value/,
    );
    assert.match(s, /clearedNote\.value = ''/);
    assert.ok(s.indexOf('aria-live="polite"') < s.indexOf('v-if="shown"'));
});

test('V2SelectionBar: the wrapper is display: contents so the sticky bar sticks inside the page column', () => {
    assert.match(s, /data-slot="v2-selection-bar-region" class="contents"/);
});

test('V2SelectionBar: imports nothing from Classic ui', () => {
    assert.doesNotMatch(s, /@\/components\/ui\//);
});

test('V2SelectionBar: busy keeps the full-strength action; only a disabled action and Clear dim', () => {
    assert.match(
        s,
        /actionDisabled && !busy\s*\? 'cursor-not-allowed opacity-50'/,
    );
    assert.match(s, /busy \? 'cursor-progress'/);
    const action = s.slice(
        s.indexOf(':disabled="actionDisabled || busy"'),
        s.indexOf('@click="emit(\'action\')"'),
    );
    assert.doesNotMatch(action, /disabled:opacity/);
});

test('V2SelectionBar: hintTone gives warnings role=status and errors role=alert, each with its word', () => {
    assert.match(s, /hintTone\?: 'neutral' \| 'warning' \| 'error'/);
    assert.match(s, /hintTone: 'neutral'/);
    assert.match(s, /'alert'/);
    assert.match(s, /'status'/);
    assert.match(s, />Error:\s*<\/span/);
    assert.match(s, />Warning:\s*<\/span/);
    assert.match(s, /text-destructive/);
    assert.match(s, /text-warning-strong/);
});
