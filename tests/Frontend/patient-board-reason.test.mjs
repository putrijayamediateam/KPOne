import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// R1-07: the null-vs-authoritative Visit notes fallback used to live in a
// template mustache expression here (`row.visitNotes ?? 'No reason
// recorded'`), evaluated directly as JS. It now lives inside the shared
// DisclosureText component (see disclosure-text.test.mjs for its own
// fallback/tooltip/mobile-expand coverage); this file instead proves Patient
// Board wires the same, un-mutated Visit notes value and fallback into it.
const source = readFileSync(
    new URL(
        '../../resources/js/components/patient-board/PatientBoard.vue',
        import.meta.url,
    ),
    'utf8',
);

test('Patient Board passes the authoritative Visit notes value to the shared disclosure, unmodified', () => {
    assert.match(source, /<DisclosureText\b[^>]*\s:text="row\.visitNotes"/);
});

test('Patient Board keeps the existing "No reason recorded" fallback text', () => {
    assert.match(
        source,
        /<DisclosureText\b[^>]*\sfallback="No reason recorded"/,
    );
});
