import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const v2 = (n) => read(`resources/js/components/v2/${n}.vue`);
const code = (n) => v2(n).replace(/\/\*[\s\S]*?\*\//g, '');

test('V2Field: a visible label tied to the control, a hint, and an announced error', () => {
    const s = v2('V2Field');
    assert.match(s, /<label :for="id"/);
    assert.match(s, /described-by/);
    assert.match(s, /role="alert"/);
    assert.match(s, /v-if="required"[\s\S]*aria-hidden="true"/);
});

test('V2SearchCombobox: combobox and listbox roles, keyboard, chip slot, and no matching logic', () => {
    const s = v2('V2SearchCombobox');
    assert.match(s, /role="combobox"/);
    assert.match(s, /role="listbox"/);
    assert.match(s, /role="option"/);
    assert.match(s, /aria-activedescendant/);
    assert.match(s, /ArrowDown/);
    assert.match(s, /ArrowUp/);
    assert.match(s, /'Enter'/);
    assert.match(s, /'Escape'/);
    assert.match(s, /name="chip"/);
    assert.match(s, /h-11[^"]*lg:h-9/);
    assert.doesNotMatch(
        code('V2SearchCombobox'),
        /fetch\(|axios|router|usePage|\.filter\(/,
    );
});

test('V2ChipChoice: remove x with a name, own 44px targets, a visible limit with a disabled state, inactive note, add entry', () => {
    const s = v2('V2ChipChoice');
    assert.match(s, /role="group"/);
    assert.match(s, /update:primary/);
    assert.match(s, /Make primary/);
    assert.match(s, />\s*Primary\s*</);
    assert.match(s, /:aria-label="`Remove \$\{option\.label\}`"/);
    assert.match(s, /h-11 min-w-11/);
    assert.match(
        s,
        /Up to \{\{ max \}\} \{\{ noun \}\}\. \{\{ modelValue\.length \}\} chosen\./,
    );
    assert.match(s, /Limit reached\. Remove one to add another\./);
    assert.match(s, /:disabled="option\.disabled \|\| atLimit"/);
    assert.match(s, /inactiveMessage/);
    assert.match(s, /emit\('add'\)/);
    assert.match(s, /:disabled="atLimit && !addAtLimit"/);
    assert.doesNotMatch(code('V2ChipChoice'), /fetch\(|axios|router|usePage/);
});

test('The new blocks import nothing from the Classic ui components', () => {
    for (const n of ['V2Field', 'V2SearchCombobox', 'V2ChipChoice']) {
        assert.doesNotMatch(v2(n), /from '@\/components\/ui\//);
    }
});
