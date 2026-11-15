import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Q-2b (owner decision 2026-11-05, Daniel's recommendation): the shared board's
// Status column widens from 9% to 15% so status, not-ready and treatment chips
// stop wrapping to many lines; Payment narrows from 24% to 18% to make room.
const board = readFileSync(
    new URL(
        '../../resources/js/components/patient-board/PatientBoard.vue',
        import.meta.url,
    ),
    'utf8',
);

test('Status is 15% and Payment 18% wide; the other columns are unchanged', () => {
    const widths = [
        ...board.matchAll(/<th class="w-\[(\d+)%\][^"]*">([^<]+)<\/th>/g),
    ].map(([, width, label]) => [label.trim(), Number(width)]);
    assert.deepEqual(widths, [
        ['Patient', 15],
        ['Queue', 7],
        ['Arrived', 9],
        ['Visit notes', 12],
        ['Doctor', 10],
        ['Payment', 18],
        ['Duration', 8],
        ['Status', 15],
    ]);
    assert.equal(
        widths.reduce((sum, [, width]) => sum + width, 0),
        94,
        'the percentage columns keep the same total, leaving room for Actions',
    );
    assert.match(
        board,
        /<th class="w-\[112px\] px-3 py-2 text-right">Actions<\/th>/,
    );
});

test('the Payment cell keeps its two-line Panel name and note tooltip', () => {
    const payment = board.slice(
        board.indexOf('data-label="Payment"'),
        board.indexOf('data-label="Duration"'),
    );
    assert.match(payment, /:title="paymentTooltip\(row\)"/);
    assert.match(
        payment,
        /<TooltipContent class="max-w-80 break-words">[\s\S]*?v-if="row\.panelNote"[\s\S]*?\{\{ row\.panelNote \}\}/,
    );
});
