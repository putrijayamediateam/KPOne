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
    // UI-RESTYLE D4b (owner-approved): the action error banner uses the shared danger tokens.
    assert.ok(
        show.includes(
            'border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive',
        ),
    );
    // UI-RESTYLE D4c (owner-approved): the verify panel moved from pink to the primary tokens, which carry their own dark colour.
    assert.ok(
        show.includes('border border-primary/40 bg-primary/10 p-4 text-sm'),
    );
});
