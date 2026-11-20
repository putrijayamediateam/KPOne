// Classic only, CO-5: the Classic half of v2-dispensary-returns-to-check.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Dispensary/ReturnsToCheck.vue');

test('Classic Returns to check imports nothing from V2', () => {
    assert.ok(!/\/V2\//.test(classic));
});

test('Classic Returns to check keeps its row type, props and helpers', () => {
    for (const line of [
        'const caseLink = (row: Row) => `${row.caseUrl}#medicine-returns`;',
        "'/dispensary/returns-to-check',",
        'returnReasonLabels[row.reason] ?? row.reason',
        'returnConditionLabels[row.condition] ?? row.condition',
        "(row.recordedByMe ? 'You' : row.recordedBy)",
    ]) {
        assert.ok(classic.includes(line), `Classic ${line}`);
    }
});

test('Returns to check pages by 25 on the server', () => {
    assert.match(
        read(
            'app/Domain/Clinical/Dispensary/Services/DispensaryReturnService.php',
        ),
        /->paginate\(25, \['\*'\], 'page'/,
    );
});
