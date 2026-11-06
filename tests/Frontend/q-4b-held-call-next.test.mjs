import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Q-4b Call next from a held consultation (owner decisions 2026-11-06; Yasmin's sketch).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const show = read('resources/js/pages/Clinical/Show.vue');
const held = read('resources/js/components/queue/HeldCallNext.vue');
const control = read('resources/js/components/queue/CallNextControl.vue');

test('the consultation page offers Call next only inside the held notice, for the doctor’s own queue', () => {
    assert.match(
        show,
        /v-if="clinical\.hold\.isHeld"\s*role="status"\s*class="flex flex-col gap-3 rounded-lg border border-warning\/25 bg-warning\/10 p-3 text-sm text-warning-strong sm:flex-row sm:items-center sm:justify-between"\s*data-testid="held-notice"/,
    );
    assert.match(
        show,
        /<div\s*v-if="canCallNextWhileHeld"\s*class="shrink-0 sm:max-w-sm"\s*>\s*<HeldCallNext\s*:branch-id="clinical\.branch\.id"\s*:visit-number="clinical\.visit\.visitNumber"[\s\S]*?@available="callNextAvailable = \$event"/,
    );
    assert.match(
        show,
        /permissions\.includes\('queue\.call\.own'\) &&\s*!page\.props\.auth\.permissions\.includes\('queue\.view\.branch'\)/,
    );
    // Offered in words only while it can be pressed, never beside "You are already seeing a patient".
    assert.match(
        show,
        /<template\s*v-if="\s*callNextAvailable &&[^"]*"\s*>You can call your next patient meanwhile\.<\/template/,
    );
    // Never in the crowded top bar, and never a second copy of the control.
    assert.equal(show.match(/<HeldCallNext/g)?.length, 1);
    assert.doesNotMatch(show, /<CallNextControl/);
});

test('the held control reads the doctor’s own block from the server and keeps it fresh', () => {
    assert.match(held, /`\/queue\/call-next\?\$\{params\}`/);
    assert.match(held, /expected_branch_id: String\(branchId\)/);
    assert.match(held, /const POLL_MS = 10_000;/);
    assert.match(held, /document\.addEventListener\('visibilitychange'/);
    assert.match(held, /document\.removeEventListener\('visibilitychange'/);
    // The dialog waits for the new block before it asks again.
    assert.match(
        held,
        /block\.value = result\.callNext;[\s\S]{0,200}?\/\/ The dialog reads the new block right after this resolves\.\s*await nextTick\(\);/,
    );
    assert.match(held, /:refresh="load"/);
    assert.match(held, /:from-visit="visitNumber"/);
    assert.match(
        held,
        /emit\(\s*'available',\s*result\.callNext\?\.state === 'ready' &&\s*result\.callNext\.candidate !== null,?\s*\)/,
    );
    assert.match(
        held,
        /onBeforeUnmount\(\(\) => \{\s*emit\('available', false\);/,
    );
});

test('Mira: actions wait while a call is sent, and the sentence never promises what is unavailable', () => {
    assert.match(show, /@processing="callNextProcessing = \$event"/);
    assert.equal(
        show.match(
            /:disabled="(?:hold|recall)Processing \|\| callNextProcessing"/g,
        )?.length,
        4,
    );
    assert.match(
        show,
        /v-if="\s*callNextAvailable &&\s*!holdProcessing &&\s*!recallProcessing\s*"/,
    );
    // A failed read turns the offer off; a superseded read waits for the newer one.
    assert.match(held, /emit\('available', false\);\s*throw error;/);
    assert.match(
        held,
        /if \(lifecycle\.canRun\(\) && newer !== null\) \{\s*return newer;/,
    );
});

test('a call from a held consultation names that consultation so a refusal returns to it', () => {
    assert.match(control, /fromVisit\?: string;/);
    assert.match(
        control,
        /\.\.\.\(props\.fromVisit\s*\?\s*\{ from: 'consultation', from_visit: props\.fromVisit \}\s*:\s*\{\}\)/,
    );
    // Still exactly one POST, only from submit().
    assert.equal(control.match(/router\.post\(/g)?.length, 1);
});
