import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
    new URL(
        '../../resources/js/components/patient-board/PatientBoard.vue',
        import.meta.url,
    ),
    'utf8',
);
// Execute the actual presentation expression, without persisting an invalid
// null-reason Consultation fixture or duplicating the fallback implementation.
const expression = source.match(
    /\{\{\s*(row\.visitNotes\s*\?\?[^}]+)\}\}/,
)?.[1];
assert.ok(expression, 'Patient Board must expose its Visit notes presentation');

test('explicit null Visit notes render the existing fallback without a database mutation', () => {
    assert.equal(
        runInNewContext(expression, { row: { visitNotes: null } }),
        'No reason recorded',
    );
});

test('authoritative Visit notes are retained instead of the null fallback', () => {
    assert.equal(
        runInNewContext(expression, { row: { visitNotes: 'Sakit tekak' } }),
        'Sakit tekak',
    );
    assert.equal(runInNewContext(expression, { row: { visitNotes: '' } }), '');
    // The separate server lifecycle test requires the actual projected value at
    // every handoff stage; an omitted projection key cannot satisfy that test.
});
