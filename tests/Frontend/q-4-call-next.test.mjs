import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

// Q-4 Call next (owner decisions 2026-11-05; Yasmin's sketch, Daniel's seven points).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const helper = {};
Function(
    'exports',
    ts.transpileModule(read('resources/js/lib/call-next.ts'), {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.CommonJS,
        },
    }).outputText,
)(helper);

const candidate = (overrides = {}) => ({
    visitNumber: 'KPV-1',
    queueNumber: 'A-012',
    patientName: 'Synthetic Ahmad',
    patientNumber: 'KP-00000001',
    priority: 'normal',
    carryOver: false,
    operationalDate: '2026-08-25',
    roomName: 'Room 2',
    visitLockVersion: 2,
    queueLockVersion: 1,
    ...overrides,
});

test('the button label stays steady and never shows the next number', () => {
    const own = {
        mode: 'own',
        doctorName: 'Dr Synthetic',
        candidate: candidate(),
    };
    assert.equal(helper.callNextLabel(own), 'Call next');
    assert.equal(
        helper.callNextLabel({ ...own, mode: 'branch' }),
        'Call next for Dr Synthetic',
    );
    assert.equal(
        helper.callNextLabel({ ...own, mode: 'branch', doctorName: null }),
        'Call next',
    );
});

test('the dialog names the patient and where the TV sends them', () => {
    assert.equal(
        helper.callNextTitle(candidate()),
        'Call A-012 Synthetic Ahmad?',
    );
    assert.equal(
        helper.callNextDescription(candidate()),
        'The waiting-room TV calls them to Room 2.',
    );
    assert.equal(
        helper.callNextDescription(candidate({ roomName: null })),
        'The waiting-room TV sends them to the counter.',
    );
    assert.equal(
        helper.callNextSwapMessage(candidate({ queueNumber: 'A-013' })),
        'The next patient changed to A-013 Synthetic Ahmad. Check and call again.',
    );
});

test('any change of patient or version needs a fresh confirmation', () => {
    assert.equal(helper.sameCandidate(candidate(), candidate()), true);
    assert.equal(
        helper.sameCandidate(candidate(), candidate({ visitNumber: 'KPV-2' })),
        false,
    );
    assert.equal(
        helper.sameCandidate(candidate(), candidate({ queueLockVersion: 2 })),
        false,
    );
    assert.equal(helper.sameCandidate(candidate(), null), false);
    assert.equal(helper.CALL_NEXT_SWAP_PAUSE_MS, 1000);
});

test('the control explains why it is unavailable, swaps politely and never calls on its own', () => {
    const control = read('resources/js/components/queue/CallNextControl.vue');

    // Disabled but still focusable, with a muted one-line reason tied to it.
    assert.match(
        control,
        /:aria-disabled="unavailable \|\| processing \? 'true' : undefined"/,
    );
    assert.match(
        control,
        /aria-describedby="\s*!ready && block\.reason \? 'call-next-reason' : undefined\s*"/,
    );
    assert.match(
        control,
        /id="call-next-reason"\s*class="text-xs text-muted-foreground/,
    );
    // The confirmed patient is a copy, so a poll never changes the dialog silently.
    assert.match(
        control,
        /confirmed\.value = \{ \.\.\.props\.block\.candidate! \}/,
    );
    // A refusal re-polls and shows the latest patient: live region, a short pause, focus on the title.
    assert.match(control, /errors\.next_changed \|\| errors\.next_none/);
    assert.match(control, /aria-live="polite"/);
    assert.match(control, /:disabled="processing \|\| paused"/);
    assert.match(control, /CALL_NEXT_SWAP_PAUSE_MS/);
    assert.match(
        control,
        /titleRef\.value\?\.\$el as HTMLElement \| undefined\)\?\.focus\(\)/,
    );
    // Exactly one POST, only from submit(): nothing calls automatically after a swap.
    assert.equal(control.match(/router\.post\(/g)?.length, 1);
    assert.doesNotMatch(control, /showLatest[\s\S]*?\bsubmit\(\)/);
    // Mira: a failed reload is said out loud, the real reason is shown when nobody can be called, and the
    // doctor sent is the one confirmed with the patient.
    assert.match(
        control,
        /The queue could not be reloaded\. Close this and try again\./,
    );
    assert.match(
        control,
        /props\.block\.reason \?\? 'Nobody is waiting who can be called now\.'/,
    );
    assert.match(control, /doctor_user_id: confirmedDoctorId\.value/);
    assert.match(control, /for \(let attempt = 0; attempt < 2; attempt\+\+\)/);
    // Yasmin: the swap is also a visible muted line, not only screen-reader text.
    assert.match(control, /data-testid="call-next-notice"/);
    // Nobody left: Close only.
    assert.match(control, /\{\{ gone \? 'Close' : 'Cancel' \}\}/);
    assert.match(control, /v-if="confirmed && !gone"\s*ref="confirm"/);
    // Daniel: Urgent is danger, carry-over is neutral.
    assert.match(control, /label="Urgent"\s*tone="danger"/);
    assert.match(control, /label="Carry-over"\s*tone="neutral"/);
    // 390 px: full-width 44 px button and dialog buttons.
    assert.match(control, /class="min-h-11 w-full sm:min-h-9 sm:w-auto"/);
});

test('the queue page shows the control only when the server sends a block and makes Apply secondary', () => {
    const page = read('resources/js/pages/Queue/Index.vue');

    assert.match(page, /<CallNextControl\s+v-if="live\.callNext"/);
    assert.match(page, /:variant="live\.callNext \? 'outline' : 'default'"/);
    assert.match(page, /:refresh="refreshBoard"/);
    assert.doesNotMatch(
        read('resources/js/pages/Clinical/Show.vue'),
        /CallNextControl/,
    );
});
