import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const files = ['resources/js/components/patient-board/PatientBoard.vue'];

test('DD-15: an empty patient board says what to do, in plain words, on the patient board', () => {
    for (const file of files) {
        const source = readFileSync(
            new URL(`../../${file}`, import.meta.url),
            'utf8',
        ).replace(/\s+/g, ' ');
        assert.ok(
            source.includes(
                'No patients match. Clear the search, or try another tab.',
            ),
            file,
        );
        assert.ok(!source.includes('operational view'), file);
    }
});
