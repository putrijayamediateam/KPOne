import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Queue/Index.vue');
const v2 = read('resources/js/composables/queue/useQueueBoardV2.ts');
const page = read('resources/js/pages/Queue/V2/Index.vue');
// Whitespace, parentheses, semicolons and trailing commas are formatter choices, not logic.
const squash = (text) => text.replace(/[\s();,]+/g, '');

/** The top-level statement that starts with `const name =` / `const name = ref(...)`, up to the next statement. */
const block = (source, name, indent) => {
    const start = source.indexOf(`\n${indent}const ${name} = `);

    assert.ok(start >= 0, `${name} exists`);

    const rest = source.slice(start + 1);
    const lines = rest.split('\n');
    const out = [lines[0]];

    for (const line of lines.slice(1)) {
        if (
            new RegExp(
                `^${indent}(const |let |type |watch\\(|onMounted\\(|onBeforeUnmount\\(|\\/\\/|\\/\\*\\*)`,
            ).test(line)
        ) {
            break;
        }

        out.push(line);
    }

    return squash(out.join('\n'));
};

const SHARED = [
    'roomOptions',
    'chooseRoom',
    'tabs',
    'completedLine',
    'servingLine',
    'error',
    'csrf',
    'effectiveNowMs',
    'elapsedMinutes',
    'doctorOptions',
    'urgencyOptions',
    'sourceRows',
    'compactTime',
    'boardRows',
    'schedule',
    'refresh',
    'replaceAppliedRefresh',
    'applyFilters',
    'selectTab',
    'clearFilters',
    'source',
    'recall',
    'refreshBoard',
    'finishCall',
    'openConsultation',
    'handleVisibility',
];

// The only V2 additions inside shared handlers: the poll waits while an overlay is open, and a failed refresh is
// remembered for the visible notice. Everything else must equal Classic.
const V2_ONLY = [
    squash(`// V2 only: wait while something is open, then ask again on the next tick.
        if (options.paused?.()) { schedule(); return; }`),
    squash('refreshFailedAt.value = null;'),
    squash('refreshFailedAt.value = new Date();'),
];
const withoutV2Additions = (text) =>
    V2_ONLY.reduce((out, piece) => out.replace(piece, ''), text);

test('every shared handler of the V2 queue logic equals the Classic one, statement for statement', () => {
    for (const name of SHARED) {
        assert.equal(
            withoutV2Additions(block(v2, name, '    ')),
            block(classic, name, ''),
            `${name} is the same in Classic and V2`,
        );
    }
});

test('the V2 additions are exactly the pause while an overlay is open and the failed-refresh time', () => {
    const body = squash(v2);

    for (const piece of V2_ONLY) {
        assert.equal(body.split(piece).length - 1, 1, piece);
    }
});

test('the V2 composable keeps the Classic polling, lifecycle and listeners', () => {
    for (const piece of [
        "document.addEventListener('visibilitychange', handleVisibility)",
        "document.removeEventListener('visibilitychange', handleVisibility)",
        'clockTimer = setInterval(() => (clockNow.value = Date.now()), 60_000)',
        'pollingLifecycle.mount()',
        'pollingLifecycle.dispose()',
        'schedule();',
        'controller?.abort();',
        'readyAlerts.reset();',
        'treatmentAlerts.reset();',
    ]) {
        assert.ok(squash(v2).includes(squash(piece)), piece);
        assert.ok(squash(classic).includes(squash(piece)), `Classic: ${piece}`);
    }
});

test('V2 and Classic use the same endpoints for every queue action', () => {
    const endpoints = ['/queue/room', '/queue/recall', '/encounter'];

    for (const endpoint of endpoints) {
        assert.ok(v2.includes(endpoint), `V2 composable: ${endpoint}`);
        assert.ok(classic.includes(endpoint), `Classic: ${endpoint}`);
    }

    const dialogs = read(
        'resources/js/components/queue-v2/QueueRowDialogs.vue',
    );
    const callNext = read('resources/js/components/queue-v2/CallNextV2.vue');
    const classicCallNext = read(
        'resources/js/components/queue/CallNextControl.vue',
    );

    assert.ok(dialogs.includes('/queue/readiness'));
    assert.ok(dialogs.includes('/queue/treatment-call'));
    assert.ok(dialogs.includes('/cancel'));
    assert.ok(callNext.includes("'/queue/call-next'"));
    assert.ok(classicCallNext.includes("'/queue/call-next'"));
});

test('Call next in V2 keeps the Classic payload keys and refusal handling', () => {
    const callNext = squash(
        read('resources/js/components/queue-v2/CallNextV2.vue'),
    );
    const classicCallNext = squash(
        read('resources/js/components/queue/CallNextControl.vue'),
    );

    for (const piece of [
        'expected_branch_id:props.branchId',
        'doctor_user_id:confirmedDoctorId.value',
        'visit_number:candidate.visitNumber',
        'visit_lock_version:candidate.visitLockVersion',
        'queue_lock_version:candidate.queueLockVersion',
        'errors.next_changed||errors.next_none',
        'CALL_NEXT_SWAP_PAUSE_MS',
        'sameCandidate(next,confirmed.value)',
    ]) {
        assert.ok(callNext.includes(squash(piece)), `V2: ${piece}`);
        assert.ok(classicCallNext.includes(squash(piece)), `Classic: ${piece}`);
    }
});

test('the V2 queue page uses only the existing queue endpoints, never a new route', () => {
    assert.doesNotMatch(page, /router\.(post|put|patch|delete)\(/);
    assert.match(page, /useQueueBoardV2\(props, \{ paused: overlayOpen \}\)/);
    assert.doesNotMatch(page, /QueueCallDialog|VisitCancellationDialog/);
});

// Payload keys of every V2 dialog, key by key, against the Classic dialog that stays untouched.
const keysOf = (source, marker) => {
    const at = source.indexOf(marker);
    const body = source.slice(at, at + 900);

    return [...body.matchAll(/\b(\w+):/g)].map(([, key]) => key);
};

test('the V2 dialogs send the same payload keys as the Classic ones', () => {
    const dialogs = read(
        'resources/js/components/queue-v2/QueueRowDialogs.vue',
    );
    const classicCall = read(
        'resources/js/components/queue/QueueCallDialog.vue',
    );
    const classicReadiness = read(
        'resources/js/components/queue/CallReadinessActions.vue',
    );
    const classicCancel = read(
        'resources/js/components/patient-board/VisitCancellationDialog.vue',
    );
    const expectKeys = (v2Marker, classicSource, classicMarker, keys) => {
        for (const key of keys) {
            assert.ok(
                keysOf(dialogs, v2Marker).includes(key),
                `V2 ${v2Marker}: ${key}`,
            );
            assert.ok(
                keysOf(classicSource, classicMarker).includes(key),
                `Classic ${classicMarker}: ${key}`,
            );
        }
    };

    expectKeys('/queue/call`', classicCall, '/queue/call`', [
        'expected_branch_id',
        'visit_lock_version',
        'queue_lock_version',
        'override_reason',
        'call_hold_id',
        'call_hold_lock_version',
        'idempotency_key',
    ]);
    expectKeys('/queue/readiness`', classicReadiness, '/queue/readiness`', [
        'expected_branch_id',
        'queue_lock_version',
        'lock_version',
        'call_hold_id',
        'reason_code',
        'idempotency_key',
        'from',
    ]);
    expectKeys('/cancel`', classicCancel, '/cancel`', [
        'expected_branch_id',
        'lock_version',
        'queue_lock_version',
        'cancellation_reason',
    ]);
    assert.ok(
        keysOf(dialogs, '/queue/treatment-call`').includes('branch_room_id'),
    );
    assert.ok(
        keysOf(classic, '/queue/treatment-call`').includes('branch_room_id'),
    );
});

test('a dialog never closes while its request is running and never outlives a branch change', () => {
    const dialogs = read(
        'resources/js/components/queue-v2/QueueRowDialogs.vue',
    );
    const callNext = read('resources/js/components/queue-v2/CallNextV2.vue');

    assert.match(dialogs, /!value && !busy && emit\('close'\)/);
    assert.match(dialogs, /\(\) => props\.branchId,[\s\S]*?emit\('close'\)/);
    assert.match(
        callNext,
        /onBeforeUnmount\(\(\) => \{[\s\S]*?emit\('dialog', false\)/,
    );
});

test('a cancellation reason over 500 characters is refused with a message, never cut silently', () => {
    const dialogs = read(
        'resources/js/components/queue-v2/QueueRowDialogs.vue',
    );

    assert.match(dialogs, /cancellationReason\.length > 500/);
    assert.match(dialogs, /Keep the reason to 500 characters or fewer\./);
    assert.doesNotMatch(dialogs, /cancellationReason\.slice\(0, 500\)/);
});

test('the attention list never uses a multi-column row (it lives only in the narrow rail and the phone sheet)', () => {
    const list = readFileSync(
        'resources/js/components/queue-v2/QueueAttentionList.vue',
        'utf8',
    );

    assert.doesNotMatch(list, /grid-cols-\[minmax\(0,1fr\)_auto_auto\]/);
    assert.doesNotMatch(list, /sm:grid-cols/);
    assert.match(list, /grid-cols-\[minmax\(0,1fr\)_auto\]/);
    assert.match(list, /Show in list/);
    assert.match(list, /in the list`/);
    assert.doesNotMatch(list, />\s*View\s*</);
});

test('a long flag reason is plain text beside a short chip, never inside a wrapping pill', () => {
    const lib = readFileSync('resources/js/lib/queue-v2.ts', 'utf8');
    const list = readFileSync(
        'resources/js/components/queue-v2/QueueAttentionList.vue',
        'utf8',
    );

    assert.match(lib, /label: 'Not ready',\s*detail: row\.callHold\.reason/);
    assert.doesNotMatch(lib, /Not ready —/);
    assert.match(list, /chip\.detail/);
});
