import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Execute the real Queue page's row action mapper, rather than a second implementation.
const source = readFileSync(
    new URL('../../resources/js/pages/Queue/Index.vue', import.meta.url),
    'utf8',
);
const actions = source.match(
    /can: (\{\s*viewPatient: row\.can\.viewPatient,[\s\S]*?\n\s*\}),\n\s*source: row/,
);
assert.ok(actions, 'Queue row action mapper remains discoverable');
const map = new Function('row', 'props', `return (${actions[1]});`);
const row = {
    can: {},
    canCall: false,
    canCallTreatment: true,
    status: 'serving',
    isHeld: true,
};

test('held narrow-authority row exposes treatment call without doctor call or recall', () => {
    const result = map(row, {
        treatmentRooms: [{ id: 1, name: 'Synthetic room' }],
    });
    assert.equal(result.callTreatment, true);
    assert.equal(result.call, false);
    assert.equal(result.recall, false);
});

test('treatment call stays hidden without backend capability or an active room', () => {
    assert.equal(
        map(
            { ...row, canCallTreatment: false },
            { treatmentRooms: [{ id: 1 }] },
        ).callTreatment,
        false,
    );
    assert.equal(map(row, { treatmentRooms: [] }).callTreatment, false);
    assert.equal(map(row, {}).callTreatment, false);
});
