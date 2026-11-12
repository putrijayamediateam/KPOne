import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-57: the Dispensary attention panel and the Dispensary page keep their allergy and needs-attention warnings
// readable in dark mode. Every palette colour sits on a line with its own dark: style; light mode is unchanged.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const palette =
    /(?:bg|text|border|ring)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d+/;

for (const path of [
    'resources/js/pages/Clinical/Partials/DispensaryAttentionPanel.vue',
    'resources/js/pages/Dispensary/Show.vue',
]) {
    test(`${path}: every palette colour has a dark: style on its line`, () => {
        const bare = read(path)
            .split('\n')
            .filter((line) => palette.test(line) && !line.includes('dark:'));
        assert.deepEqual(bare, []);
    });
}

test('the warnings keep their light-mode colours', () => {
    const panel = read(
        'resources/js/pages/Clinical/Partials/DispensaryAttentionPanel.vue',
    );
    const show = read('resources/js/pages/Dispensary/Show.vue');
    assert.ok(panel.includes('border-amber-200 bg-amber-50/40 dark:'));
    // UI-RESTYLE D4a (owner-approved): the allergy panels moved to the shared warning tokens.
    assert.ok(show.includes("'border-warning/30 bg-warning/10'"));
    assert.ok(
        show.includes(
            'border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:',
        ),
    );
    assert.ok(show.includes('border-pink-300 bg-pink-50/60 p-3 text-sm dark:'));
});
