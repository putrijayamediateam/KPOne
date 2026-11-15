import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// An earlier partial dispense shows plain words, never the stored status code (Daniel, 2026-11-11).
const show = readFileSync(
    new URL('../../resources/js/pages/Dispensary/Show.vue', import.meta.url),
    'utf8',
);

test('the earlier partial dispense line uses plain words, not the stored code', () => {
    assert.ok(
        show.includes(
            "awaiting_acknowledgement: 'waiting for the doctor to acknowledge'",
        ),
    );
    assert.ok(show.includes("acknowledged: 'acknowledged by the doctor'"));
    assert.ok(show.includes("superseded: 'replaced by a later change'"));
    assert.ok(
        /exceptionStatusLabels\[\s*item\.exception\.status\s*\] \?\? 'status unknown'/.test(
            show,
        ),
    );
    assert.equal(show.includes('{{ item.exception.status }}'), false);
});
