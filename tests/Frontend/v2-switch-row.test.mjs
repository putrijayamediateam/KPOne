import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const s = fs.readFileSync(
    new URL(
        '../../resources/js/components/v2/V2SwitchRow.vue',
        import.meta.url,
    ),
    'utf8',
);

test('V2SwitchRow: a real switch with label, help and the state in words', () => {
    for (const p of [
        'modelValue: boolean',
        'label: string',
        'help?: string',
        'disabled?: boolean',
        'disabledReason?: string',
    ]) {
        assert.ok(s.includes(p), p);
    }

    assert.match(s, /role="switch"/);
    assert.match(s, /:aria-checked="modelValue"/);
    assert.match(s, /modelValue \? 'On' : 'Off'/);
    assert.match(s, /<label\s+:for="id"/);
    assert.match(s, /:aria-describedby="note \? `\$\{id\}-note` : undefined"/);
});

test('V2SwitchRow: a 44px target, 36px from 1024px, ring with offset, motion respected', () => {
    assert.match(s, /h-11 w-14[^"]*lg:h-9/);
    assert.match(
        s,
        /focus-visible:ring-offset-2 focus-visible:ring-offset-background/,
    );
    assert.match(s, /motion-reduce:transition-none/);
});

test('V2SwitchRow: a disabled switch is aria-disabled, cannot toggle, and needs a visible reason', () => {
    assert.match(s, /:aria-disabled="disabled \? 'true' : undefined"/);
    assert.match(s, /if \(!props\.disabled\)/);
    assert.match(
        s,
        /props\.disabled && props\.disabledReason \? props\.disabledReason : props\.help/,
    );
    assert.match(s, /a disabled switch needs a `disabledReason`/);
});

test('V2SwitchRow: only emits update:modelValue and imports nothing from Classic ui', () => {
    assert.match(
        s,
        /defineEmits<\{ 'update:modelValue': \[value: boolean\] \}>/,
    );
    assert.doesNotMatch(s, /@\/components\/ui\//);
    assert.doesNotMatch(s, /usePage|router\./);
});

test('V2SwitchRow: the On/Off word is aria-hidden and the docs say the page owns saving, the visible result and the confirm step', () => {
    assert.match(
        s,
        /aria-hidden="true"\s*>\{\{ modelValue \? 'On' : 'Off' \}\}/,
    );
    assert.match(s, /The block only changes the form value/);
    assert.match(s, /visible result \("Saved"\)/);
    assert.match(s, /privacy\s+\* or money effect/);
});
