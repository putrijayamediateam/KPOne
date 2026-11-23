import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const flat = (s) => s.replace(/\s+/g, ' ');
const show = {
    classic: read('resources/js/pages/Inventory/ControlledRegisterShow.vue'),
};
const list = {
    classic: read('resources/js/pages/Inventory/ControlledRegister.vue'),
};
const print = read('resources/js/pages/Inventory/ControlledRegisterPrint.vue');

test('STK-40b: a check that differs reads "Difference: reason needed" until its reason is added, on the Classic pages and on the print', () => {
    for (const [name, source] of Object.entries(show)) {
        assert.match(
            flat(source),
            /check\.reasonNeeded \? 'Difference: reason needed' : 'Differs'/,
            name,
        );
        assert.match(
            flat(source),
            /The person who recorded this check adds the reason\./,
            name,
        );
    }

    for (const [name, source] of Object.entries(list)) {
        assert.match(source, /reasonNeeded: boolean;/, name);
        assert.match(
            flat(source),
            /v-if="row\.reasonNeeded"[^>]*label="Difference: reason needed"|label="Difference: reason needed"[^>]*data-testid="cd-row-reason-needed"/,
            name,
        );
    }

    assert.match(
        flat(print),
        /check\.reasonNeeded \? 'Difference: reason needed'/,
    );
});

test('STK-40b: only the checker gets the reason step; it is added once, and Other needs a note', () => {
    for (const [name, source] of Object.entries(show)) {
        const s = flat(source);
        assert.match(
            s,
            /const ownReasonNeeded = computed\( \(\) => props\.register\.checks\.find\(\(check\) => check\.canAddReason\) \?\? null, \);/,
            name,
        );
        assert.match(
            s,
            /reasonErrors\.value = \{ reason: 'Choose a reason\.' \};/,
            name,
        );
        assert.match(
            s,
            /reasonErrors\.value = \{ note: 'Add a note to explain the difference\.' \};/,
            name,
        );
        assert.match(
            s,
            /The reason is added once and cannot be changed\./,
            name,
        );
    }

    // Classic shows the form on the check itself.
    assert.match(flat(show.classic), /v-if="check\.canAddReason"/);
});

test('STK-40b (Daniel, owner 16 Nov): no second filled button beside "Add the reason", and the next checker is told an earlier difference still waits for its recorder', () => {
    for (const [name, source] of Object.entries(show)) {
        const f = flat(source);
        assert.match(
            f,
            /Check \{\{ waitingReason\.sequence \}\} differs from the register and still waits for its reason from the person who recorded it\. You can still record your check\./,
            name,
        );
        assert.match(f, /data-testid="cd-earlier-reason-waiting"/, name);
    }

    assert.match(
        flat(show.classic),
        /:variant="ownReasonNeeded \? 'outline' : 'default'"/,
    );
});
