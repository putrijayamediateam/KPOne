import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// UX-17 C2: chart axes show the day first ("10/11"), never the American month-day order.
const source = readFileSync(
    new URL('../../resources/js/lib/presentation.ts', import.meta.url),
    'utf8',
);
const js = ts.transpileModule(source, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;
const { formatDayMonth } = runInNewContext(
    `${js.replaceAll('export ', '')}\n({ formatDayMonth })`,
);

test('a day and month read day first and anything else is left alone', () => {
    assert.equal(formatDayMonth('2026-11-10'), '10/11');
    assert.equal(formatDayMonth('2026-01-05'), '5/1');
    assert.equal(formatDayMonth('2026-11-10T08:00:00Z'), '10/11');
    assert.equal(formatDayMonth('not a date'), 'not a date');
    assert.equal(formatDayMonth(''), '');
});
