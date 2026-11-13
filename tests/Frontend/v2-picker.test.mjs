import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const s = readFileSync('resources/js/components/v2/V2Picker.vue', 'utf8');

test('V2Picker: saved value in words, clear when not required, bounded results', () => {
    assert.match(s, /selectedLabel\?: string/);
    assert.match(s, /!required && displayLabel && !disabled/);
    assert.match(s, /`Clear \$\{label\}`/);
    assert.match(s, /const MAX = 25/);
    assert.match(s, /update:modelValue', null/);
});

test('V2Picker: Add row only without an exact case-insensitive match; parent creates; adding and addError states', () => {
    assert.match(s, /toLowerCase\(\) === text\.toLowerCase\(\)/);
    assert.match(s, /`Add '\$\{query\.trim\(\)\}'`/);
    assert.match(s, /emit\('add', query\.value\.trim\(\)\)/);
    assert.match(s, /'Adding…'/);
    assert.match(s, /addError/);
});

test('V2Picker: phone sheet, 44px rows, polite count, Esc returns focus, selection not by colour alone', () => {
    assert.match(s, /fixed inset-x-0 bottom-0/);
    assert.match(s, /sm:absolute/);
    assert.match(s, /min-h-11/);
    assert.match(s, /role="status" aria-live="polite"/);
    assert.match(s, /`\$\{n\} results`/);
    assert.match(s, /'No match'/);
    assert.match(s, /event\.key === 'Escape'/);
    assert.match(s, /trigger\.value\?\.focus\(\)/);
    assert.match(s, /m5 12 5 5 9-10/);
    assert.match(s, /aria-selected="option\.id === modelValue"/);
});

test('V2Picker: searchUrl is debounced, cancels the older call, imports nothing from Classic', () => {
    assert.match(s, /setTimeout\(load, 250\)/);
    assert.match(s, /controller\?\.abort\(\)/);
    assert.doesNotMatch(s, /components\/ui/);
});

test('V2Picker round 2: phone Close, visible count with cap line, flips above, Clear returns focus', () => {
    assert.match(s, /items-center justify-between sm:hidden/);
    assert.match(s, /close\(true\)"[\s\S]{0,200}Close/);
    assert.match(s, /data-slot="v2-picker-count"/);
    assert.match(s, /Showing the first \$\{MAX\}\. Type to narrow\./);
    assert.match(s, /flip\.value = below < 260 && above > below/);
    assert.match(s, /sm:bottom-full/);
    assert.match(s, /--v2-picker-max/);
    assert.match(s, /Known limit: the phone system Back gesture/);
    assert.match(s, /function clear|const clear = /);
    assert.match(s, /nextTick\(\(\) => trigger\.value\?\.focus\(\)\)/);
});
