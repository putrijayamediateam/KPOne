import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const tabs = readFileSync(
    new URL('../../resources/js/components/v2/V2Tabs.vue', import.meta.url),
    'utf8',
);

test('V2 tabs give the focus back to the chosen tab when a page rebuilds the strip after a key press', () => {
    // The memory lives in a plain script block, so it survives the old strip being thrown away (script setup is per strip).
    assert.match(
        tabs,
        /<script lang="ts">[\s\S]*let keyboardChoice:[\s\S]*<\/script>\s*<script setup lang="ts">/,
    );
    assert.match(tabs, /keyboardChoice = \{\s*label: props\.ariaLabel,/);
    // Only a key press sets it: the one assignment sits inside the keyboard handler, never in a click handler or on load,
    // so a mouse click or a fresh page load never moves the focus.
    assert.equal((tabs.match(/keyboardChoice = \{/g) ?? []).length, 1);
    assert.ok(
        tabs.indexOf('keyboardChoice = {') > tabs.indexOf('const move = ('),
    );
    assert.doesNotMatch(tabs, /@click="[^"]*keyboardChoice/);
    // It is used once, only for the same strip and tab, and it expires.
    assert.match(tabs, /chosen\.label === props\.ariaLabel/);
    assert.match(tabs, /chosen\.value === props\.modelValue/);
    assert.match(tabs, /Date\.now\(\) - chosen\.at < KEYBOARD_FOCUS_MS/);
    assert.match(tabs, /keyboardChoice = null/);
    assert.match(tabs, /focus\(\{ preventScroll: true \}\)/);
});
