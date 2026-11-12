import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL(
        '../../resources/js/pages/Patient/MergeRequests.vue',
        import.meta.url,
    ),
    'utf8',
);

test('RESTYLE-2: Recent decisions keeps a space between "Merge" and the patient number', () => {
    const recent = page.slice(page.indexOf('Recent decisions'));
    // A span that starts with a space is trimmed by Vue, which printed "MergeKP-0000…".
    assert.match(
        recent,
        /<\/span>\s*\{\{ ' ' \}\}\s*<span class="font-mono">\{\{\s*request\.mergedPatientNumber\s*\}\}<\/span>/,
    );
    assert.doesNotMatch(recent, /<span class="font-mono">\s+\{\{/);
});

test('RESTYLE-2: the decision buttons are 44 px tall below 1024 px', () => {
    const buttons = page.match(/<Button[\s\S]*?>/g) ?? [];
    assert.ok(buttons.length >= 3);

    for (const button of buttons) {
        assert.match(button, /max-lg:h-11/);
    }
});
