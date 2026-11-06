import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

// Q-2c (owner decision 2026-11-06; Yasmin's sketch): the next step starts while a finished one waits.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const helper = {};
Function(
    'exports',
    ts.transpileModule(read('resources/js/lib/treatment-progress.ts'), {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.CommonJS,
        },
    }).outputText,
)(helper);

const now = Date.parse('2026-11-06T03:00:00Z');
const ecgStarted = {
    label: 'ECG',
    state: 'started',
    startedAt: '2026-11-06T02:57:00Z',
    doneAt: null,
};
const waitingNebuliser = [
    { label: 'Nebuliser', doneAt: '2026-11-06T02:54:00Z' },
];

test('without waiting steps the chip stays exactly as before', () => {
    assert.equal(
        helper.treatmentChipLines(ecgStarted, 3, undefined, now),
        null,
    );
    assert.equal(helper.treatmentChipLines(ecgStarted, 3, [], now), null);
    assert.equal(
        helper.treatmentWaitingAriaLabel(ecgStarted, 3, [], now),
        null,
    );
});

test('a waiting finished step is a second line and "Ready to resume" appears once, last', () => {
    assert.deepEqual(
        helper.treatmentChipLines(ecgStarted, 3, waitingNebuliser, now),
        [
            'ECG: started 3 min ago',
            'Nebuliser: done 6 min ago · Ready to resume',
        ],
    );
    const ecgDone = {
        ...ecgStarted,
        state: 'done',
        doneAt: '2026-11-06T02:59:00Z',
    };
    assert.deepEqual(
        helper.treatmentChipLines(ecgDone, 1, waitingNebuliser, now),
        ['ECG: done 1 min ago', 'Nebuliser: done 6 min ago · Ready to resume'],
    );
    assert.equal(
        helper.treatmentWaitingAriaLabel(ecgStarted, 3, waitingNebuliser, now),
        'Treatment progress: ECG started 3 minutes ago; Nebuliser done 6 minutes ago, ready to resume',
    );
});

test('more than two waiting steps show "+N more done"', () => {
    const many = ['Nebuliser', 'Wound dressing', 'IV drip', 'ECG'].map(
        (label) => ({ label, doneAt: '2026-11-06T02:50:00Z' }),
    );
    assert.equal(helper.TREATMENT_WAITING_SHOWN, 2);
    assert.deepEqual(
        helper.treatmentChipLines(
            { ...ecgStarted, label: 'Observation after medicine' },
            0,
            many,
            now,
        ),
        [
            'Observation after medicine: started just now',
            'Nebuliser: done 10 min ago',
            'Wound dressing: done 10 min ago',
            '+2 more done · Ready to resume',
        ],
    );
});

test('the badge renders waiting lines in the same chip, with tokens only', () => {
    const badge = read(
        'resources/js/components/queue/TreatmentProgressBadge.vue',
    );
    assert.match(badge, /treatmentChipLines\(/);
    assert.match(
        badge,
        /class="flex items-start gap-1\.5 border-t border-current\/20 pt-1"\s*data-testid="treatment-progress-waiting"/,
    );
    assert.match(
        badge,
        /treatmentProgressTone\(\s*props\.progress\?\.waiting\?\.length \? 'done' : props\.progress\?\.state,?\s*\)/,
    );
    assert.equal(badge.match(/role="status"/g)?.length, 1);
    assert.doesNotMatch(badge, /green-|emerald-|amber/);
});

test('the dialog offers the next step when the main step is done and keeps cancel separate', () => {
    const actions = read(
        'resources/js/components/queue/TreatmentProgressActions.vue',
    );
    assert.match(
        actions,
        /<label v-if="!state \|\| state === 'done'" class="grid gap-2"/,
    );
    assert.match(
        actions,
        /Waiting for the doctor to resume\. You can start the next step now\./,
    );
    assert.match(actions, /\? 'Start next step'/);
    assert.match(
        actions,
        /Cancel “\$\{progress\?\.label\}” \(entered in error\)/,
    );
    // The two error lines never share an id when both parts are visible.
    assert.equal(actions.match(/id="treatment-progress-error"/g)?.length, 1);
    assert.equal(
        actions.match(/id="treatment-progress-cancel-error"/g)?.length,
        1,
    );
    assert.doesNotMatch(actions, /v-if="state !== 'done'"/);
});

test('Mira: errors show beside the control in use, and the badge clock runs only when needed', () => {
    const actions = read(
        'resources/js/components/queue/TreatmentProgressActions.vue',
    );
    assert.match(actions, /v-if="error && errorAction !== 'cancel'"/);
    assert.match(
        actions,
        /errorAction === 'cancel' \|\|\s*state === 'started'/,
    );
    const badge = read(
        'resources/js/components/queue/TreatmentProgressBadge.vue',
    );
    assert.match(
        badge,
        /!!props\.live \|\| !!props\.progress\?\.waiting\?\.length/,
    );
    assert.match(badge, /watch\(ticking, syncTimer\)/);
});
