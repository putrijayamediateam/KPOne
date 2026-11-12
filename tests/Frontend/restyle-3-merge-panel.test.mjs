import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const panel = readFileSync(
    new URL(
        '../../resources/js/components/patient/PatientMergePanel.vue',
        import.meta.url,
    ),
    'utf8',
);

test('RESTYLE-3: a waiting merge request keeps a space between "Merge" and the KP number', () => {
    const waiting = panel.slice(
        panel.indexOf('v-for="request in merge.pending"'),
    );
    // A span that starts with a space is trimmed by Vue, which printed "MergeKP-0000…".
    assert.match(
        waiting,
        /<\/span>\s*\{\{ ' ' \}\}\s*<span class="font-mono">\{\{\s*request\.mergedPatientNumber\s*\}\}<\/span>/,
    );
    assert.doesNotMatch(waiting, /<span class="font-mono">\s+\{\{/);
});
