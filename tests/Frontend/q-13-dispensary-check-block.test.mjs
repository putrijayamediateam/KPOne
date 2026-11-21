import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Q-13 (owner decision on DSP-23; Daniel's note): the doctor's own removed row says "Medicine ready for your check" in
// a wrapping warning block under the status. Plain text: not interactive, not a live region, no sound or toast.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const board = read('resources/js/components/patient-board/PatientBoard.vue');
const queue = read('resources/js/pages/Queue/Index.vue');
const queueTypes = read('resources/js/types/queue.ts');
const boardTypes = read('resources/js/types/patientBoard.ts');
const block = board.slice(
    board.indexOf('data-testid="dispensary-check-waiting"') - 400,
    board.indexOf('data-testid="dispensary-check-waiting"') + 900,
);

test('the block shows the title and the instruction in the warning tokens, with a triangle icon', () => {
    assert.match(board, /v-if="row\.dispensaryCheckWaiting"/);
    assert.match(block, /Medicine ready for your check/);
    assert.match(block, /Open the consultation to verify\./);
    assert.match(
        block,
        /block w-full rounded-md border border-warning\/25 bg-warning\/10 p-2 text-xs break-words text-warning-strong/,
    );
    assert.match(block, /<AlertTriangle[\s\S]*?aria-hidden="true"/);
});

test('the block is plain text: no link, button, hover, pointer cursor, live region or sound', () => {
    for (const tag of [
        '<Link',
        '<button',
        '<Button',
        'hover:',
        'cursor-pointer',
        'aria-live',
        'role="status"',
        'role="alert"',
        'Audio',
        '@click',
    ]) {
        assert.ok(!block.includes(tag), `${tag} must not be in the block`);
    }
});

test('the block sits under the status chips, before the completed time and the not-ready badge', () => {
    assert.ok(
        board.indexOf('Returned</span') <
            board.indexOf('data-testid="dispensary-check-waiting"'),
    );
    assert.ok(
        board.indexOf('data-testid="dispensary-check-waiting"') <
            board.indexOf('data-testid="call-readiness-badge"'),
    );
});

test('the field is carried from the queue payload to the board row, and typed', () => {
    assert.match(queue, /dispensaryCheckWaiting: row\.dispensaryCheckWaiting,/);
    assert.match(queueTypes, /dispensaryCheckWaiting\?: boolean;/);
    assert.match(boardTypes, /dispensaryCheckWaiting\?: boolean;/);
});
