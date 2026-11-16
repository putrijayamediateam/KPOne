import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Undo clock-out (owner, 16 Nov 2026: 15 minutes, staff only). Classic and V2 offer an outline "Undo clock-out" while
// the server sends an offer, ask to confirm first, and Review shows HR when a clock-out was taken back.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/HR/Attendance.vue');
const v2 = read('resources/js/pages/HR/V2/Attendance.vue');
const flow = read('resources/js/composables/hr/useAttendancePage.ts');

test('both pages offer an outline Undo clock-out with its time limit, only while the server offers it', () => {
    for (const [name, page] of [
        ['classic', classic],
        ['v2', v2],
    ]) {
        assert.match(
            page,
            /v-if="attendance\.my\.undo"[\s\S]*?data-test="undo-clock-out"[\s\S]*?@click="openUndo"\s*>\s*Undo clock-out[\s\S]*?Clocked out by mistake\? You can undo this\s+until\s+\{\{ attendance\.my\.undo\.until \}\}\./,
            name,
        );
    }

    assert.match(
        classic,
        /<Button\s+variant="outline"\s+class="min-h-11 self-start"\s+data-test="undo-clock-out-button"/,
    );
    assert.match(
        v2,
        /:class="button"\s+class="self-start"\s+data-test="undo-clock-out-button"/,
    );
});

test('the undo asks to confirm first, and the request comes from the shared composable', () => {
    assert.match(
        classic,
        /v-model:open="undo\.open"[\s\S]*?:title="`Undo your clock-out at \$\{attendance\.my\?\.undo\?\.pressedAt \?\? ''\}\?`"[\s\S]*?confirm-label="Undo clock-out"[\s\S]*?@confirm="confirmUndo"/,
    );
    assert.match(
        v2,
        /:open="undo\.open"[\s\S]*?confirm-label="Undo clock-out"[\s\S]*?@confirm="confirmUndo"/,
    );
    assert.match(
        flow,
        /router\.post\(\s*'\/hr\/attendance\/clock-out\/undo',\s*\{\s*session_id: offer\.sessionId,\s*expected_lock_version: offer\.lockVersion,\s*idempotency_key: undo\.key,/,
    );
    assert.doesNotMatch(v2, /clock-out\/undo/);
    assert.doesNotMatch(classic, /clock-out\/undo/);
});

test('Review shows when a clock-out was undone, in Classic and V2', () => {
    assert.equal((classic.match(/data-test="review-undone"/g) ?? []).length, 2);
    assert.equal((v2.match(/data-test="review-undone"/g) ?? []).length, 1);
    assert.match(flow, /undoneLabel\?: string \| null;/);
});
