import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const s = fs.readFileSync(
    new URL(
        '../../resources/js/components/v2/V2SelectionBar.vue',
        import.meta.url,
    ),
    'utf8',
);

test('V2SelectionBar: props, emits and no logic beyond them', () => {
    for (const p of [
        'visible: boolean',
        'summary: string',
        'actionLabel: string',
        "railFrom?: 'lg' | 'xl' | '2xl'",
        'secondaryLabel?: string',
        'announce?: string',
        'actionDisabled?: boolean',
        'busy?: boolean',
    ]) {
        assert.ok(s.includes(p), p);
    }

    assert.match(s, /railFrom: '2xl'/);
    assert.match(s, /defineEmits<\{ action: \[\]; secondary: \[\] \}>/);
});

test('V2SelectionBar: hidden from the rail breakpoint up, one class per breakpoint', () => {
    assert.match(s, /lg: 'lg:hidden', xl: 'xl:hidden', '2xl': '2xl:hidden'/);
    assert.match(s, /:class="hideFrom"/);
});

test('V2SelectionBar: sticky, safe-area padding, 44px then 36px, one filled action and an optional outline secondary', () => {
    assert.match(s, /sticky bottom-0/);
    assert.match(s, /pb-\[max\(0\.75rem,env\(safe-area-inset-bottom\)\)\]/);
    assert.match(s, /h-11[^"]*lg:h-9/);
    assert.match(s, /v-if="secondaryLabel"/);
    assert.equal((s.match(/bg-primary px-4/g) ?? []).length, 1);
    assert.match(s, /border border-border bg-card/);
});

test('V2SelectionBar: a polite live region that stays mounted and is empty when hidden', () => {
    assert.match(s, /role="status" aria-live="polite"/);
    assert.match(
        s,
        /props\.visible \? \(props\.announce \?\? props\.summary\) : ''/,
    );
    const live = s.indexOf('aria-live="polite"');
    assert.ok(live > 0 && live < s.indexOf('v-if="visible"'));
});

test('V2SelectionBar: imports nothing from Classic ui', () => {
    assert.doesNotMatch(s, /@\/components\/ui\//);
});
