import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Registration/Index.vue');
const board = read(
    'resources/js/composables/registration/useRegistrationBoard.ts',
);
const actions = read(
    'resources/js/composables/registration/useRegistrationRowActions.ts',
);
const lib = read('resources/js/lib/registration-v2.ts');
const page = read('resources/js/pages/Registration/V2/Index.vue');

// Compile the pure helper file and run it with its imports stubbed.
const load = () => {
    const js = ts.transpileModule(lib, {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const names = [...js.matchAll(/export const (\w+)/g)].map((m) => m[1]);
    const wrapped = js
        .replace(/^import .*$/gm, '')
        .replace(/export const /g, 'const ');
    const exports = {};
    runInNewContext(
        `const queuePresentationLabel = (x) => 'Removed: ' + x.removalReason;
         ${wrapped}
         Object.assign(exports, { ${names.join(', ')} });`,
        { exports, encodeURIComponent },
    );

    return exports;
};

const visit = (over = {}) => ({
    callHold: null,
    canWhatsapp: false,
    visitNumber: 'KPV-00000001',
    patientNumber: 'KP-00000001',
    patientName: 'Synthetic Patient',
    visitType: 'consultation',
    registeredAt: '10:00',
    registeredAtDate: '2026-11-12',
    visitReasonExcerpt: 'Cough',
    doctorName: 'Dr A',
    coverageLabel: 'Self-pay',
    priority: 'normal',
    status: 'registered',
    queueNumber: 'A-001',
    queueStatus: 'waiting',
    isHeld: false,
    holdStartedAt: null,
    durationMinutes: 10,
    visitLockVersion: 1,
    queueLockVersion: 2,
    can: {
        viewPatient: false,
        update: false,
        cancel: false,
        sendToWaiting: false,
        call: false,
        openConsultation: false,
    },
    ...over,
});
const plain = (value) => JSON.parse(JSON.stringify(value));
const ids = (menu) => menu.groups.flatMap((g) => g.items.map((i) => i.id));

test('V2 board: same search route, poll constants, pause rules and failure notice as Classic', () => {
    assert.match(classic, /fetch\('\/registration\/search'/);
    assert.match(board, /SEARCH_URL = '\/registration\/search'/);
    assert.match(board, /POLL_INTERVAL_MS = 10_000/);
    assert.match(board, /POLL_MAX_MS = 60_000/);
    assert.match(board, /POLL_FAILURE_LIMIT = 3/);
    assert.match(classic, /10_000 \* 2 \*\* pollFailures/);
    assert.match(board, /POLL_INTERVAL_MS \* 2 \*\* pollFailures/);

    for (const text of [
        'Live refresh paused. Showing last successful results.',
        ' Press Search to try again.',
        'Registration search could not be completed.',
    ]) {
        assert.ok(classic.includes(text), `Classic has: ${text}`);
        assert.ok(board.includes(text), `V2 has: ${text}`);
    }

    // The exact overlay and typing selectors that pause the refresh.
    const overlay = classic.match(
        /'(\[role="menu"\]\[data-state="open"\][^']+)'/,
    )?.[1];
    const typing = classic.match(/'(input, textarea, select[^']+)'/)?.[1];
    assert.ok(overlay && typing, 'Classic selectors found');
    assert.ok(board.includes(overlay), 'same open-overlay selector');
    assert.ok(board.includes(typing), 'same typing-target selector');
    assert.match(classic, /registration-board-search/);
    assert.match(board, /SEARCH_INPUT_ID = 'registration-board-search'/);

    // Same request body: the applied filters plus the page, with the CSRF header.
    assert.match(
        classic,
        /JSON\.stringify\(\{ \.\.\.appliedFilters, page \}\)/,
    );
    assert.match(board, /JSON\.stringify\(\{ \.\.\.appliedFilters, page \}\)/);
    assert.match(board, /'X-CSRF-TOKEN'/);

    // Same filter field names, and the same QR URL.
    for (const key of [
        'patient_query',
        'doctor_id',
        'visit_type',
        'priority',
        'coverage_type',
        'board_status',
        'date_from',
        'date_to',
    ]) {
        assert.ok(classic.includes(`${key}:`), `Classic form has ${key}`);
        assert.ok(board.includes(`${key}:`), `V2 form has ${key}`);
    }

    assert.ok(board.includes("'/registration?tab=qr-intake'"));
    assert.ok(classic.includes("'/registration?tab=qr-intake'"));
});

test('V2 board: every mutation keeps the Classic route, payload keys and from=registration', () => {
    const cases = [
        [
            '/queue`',
            [
                'expected_branch_id',
                'visit_lock_version',
                "from: 'registration'",
            ],
            'post',
        ],
        [
            '/queue/call`',
            [
                'expected_branch_id',
                'visit_lock_version',
                'queue_lock_version',
                "from: 'registration'",
            ],
            'patch',
        ],
        [
            '/encounter`',
            ['expected_branch_id', 'visit_lock_version', 'queue_lock_version'],
            'post',
        ],
        ['/dispense`', ['expected_branch_id'], 'post'],
        ['/otc-queue/call`', ['expected_branch_id'], 'post'],
    ];

    for (const [route, keys, method] of cases) {
        assert.ok(classic.includes(route), `Classic route ${route}`);
        assert.ok(actions.includes(route), `V2 route ${route}`);

        for (const key of keys) {
            assert.ok(classic.includes(key), `Classic key ${key}`);
            assert.ok(actions.includes(key), `V2 key ${key}`);
        }

        assert.ok(actions.includes(`method: '${method}'`));
    }

    assert.match(actions, /preserveScroll: true/);
    assert.match(actions, /preserveState: true/);
    assert.match(actions, /busyKey\.value = null/);
    assert.match(actions, /if \(succeeded\)/);
});

test('V2 board menu: items come only from the server flags; none of the Queue-only items appear', () => {
    const { rowMenu } = load();

    // A view-only role sees only the Open group, with View Visit.
    const viewOnly = rowMenu(visit());
    assert.deepEqual(plain(viewOnly.groups.map((g) => g.label)), ['Open']);
    assert.deepEqual(plain(ids(viewOnly)), ['view-visit']);

    // Every flag on: items in the groups Open, Move, Call, Money, Danger, Cancel last.
    const all = rowMenu(
        visit({
            historyUrl: '/visits/KPV-00000001/invoice',
            billingUrl: '/billing/x',
            dispensaryUrl: '/dispensary/x',
            can: {
                viewPatient: true,
                update: true,
                cancel: true,
                sendToWaiting: true,
                call: true,
                openConsultation: true,
                openDispensary: true,
                openBilling: true,
                dispense: true,
                callDispensary: true,
            },
        }),
    );
    assert.deepEqual(plain(all.groups.map((g) => g.label)), [
        'Open',
        'Move',
        'Call',
        'Money',
        'Danger',
    ]);
    assert.equal(ids(all).at(-1), 'cancel-visit');
    assert.equal(all.groups.at(-1).items[0].label, 'Cancel visit…');
    assert.equal(all.groups.at(-1).items[0].tone, 'danger');

    // Queue-board items are never offered on Registration, even with unknown flags set.
    const odd = rowMenu(
        visit({
            can: {
                viewPatient: false,
                update: false,
                cancel: false,
                sendToWaiting: false,
                call: false,
                openConsultation: false,
                recall: true,
                callTreatment: true,
            },
            treatmentProgress: { canManage: true },
        }),
    );

    for (const forbidden of [
        'recall',
        'call-again',
        'call-treatment',
        'treatment-progress',
    ]) {
        assert.ok(!ids(odd).includes(forbidden));
        assert.ok(!ids(all).includes(forbidden));
    }

    assert.ok(
        !/label: '(Call again|Call to treatment room|Treatment progress)/.test(
            lib,
        ),
    );
    assert.ok(
        !/Call again|Call to treatment room|Treatment progress/.test(page),
    );
});

test('V2 board menu: Call In is shown disabled with the reason while not ready; Override and call only for the doctor who may', () => {
    const { rowMenu } = load();
    const flags = {
        viewPatient: false,
        update: false,
        cancel: false,
        sendToWaiting: false,
        call: true,
        openConsultation: false,
    };
    const blocked = rowMenu(
        visit({
            callHold: {
                id: 1,
                lockVersion: 1,
                active: true,
                overridden: false,
                reason: 'Awaiting payment or deposit',
                canSet: false,
                canClear: true,
                canOverride: false,
            },
            can: flags,
        }),
    );
    const callIn = blocked.groups.find((g) => g.label === 'Call').items[0];
    assert.equal(callIn.allowed, false);
    assert.match(
        callIn.reason,
        /Not ready to call: Awaiting payment or deposit/,
    );
    assert.ok(!ids(blocked).includes('override-call'));

    const doctor = rowMenu(
        visit({
            callHold: {
                id: 1,
                lockVersion: 1,
                active: true,
                overridden: false,
                reason: 'Other administrative',
                canSet: false,
                canClear: true,
                canOverride: true,
            },
            can: flags,
        }),
    );
    assert.ok(ids(doctor).includes('override-call'));
    assert.equal(doctor.targets['override-call'].action, 'call');
    assert.equal(doctor.targets['call-in'].action, 'call');

    const free = rowMenu(visit({ can: flags }));
    assert.equal(free.groups[0].items[0].allowed, true);
    assert.ok(!ids(free).includes('override-call'));
});

test('V2 board: a cancel goes through the dialog (required reason), never a one-click cancel', () => {
    const { rowMenu } = load();
    const menu = rowMenu(
        visit({
            can: {
                viewPatient: false,
                update: false,
                cancel: true,
                sendToWaiting: false,
                call: false,
                openConsultation: false,
            },
        }),
    );
    assert.equal(menu.targets['cancel-visit'].action, 'cancel');
    assert.match(actions, /if \(action === 'cancel'\)/);
    assert.match(actions, /deps\.openCancel\(visit\)/);
    // The V2 dialog sends the same endpoint and payload keys as the Classic one, with a required reason (V2Dialog's
    // reasonLabel) cut to the same 500 characters, and only this one has the filled destructive Confirm.
    const v2 = read('resources/js/components/registration-v2/RowDialogs.vue');
    const classicDialog = read(
        'resources/js/components/patient-board/VisitCancellationDialog.vue',
    );

    for (const key of [
        'expected_branch_id',
        'lock_version',
        'queue_lock_version',
        'cancellation_reason',
        '/cancel`',
    ]) {
        assert.ok(v2.includes(key), `V2 cancel ${key}`);
        assert.ok(classicDialog.includes(key), `Classic cancel ${key}`);
    }

    assert.match(v2, /slice\(0, 500\)/);
    assert.match(v2, /reason-label="kind === 'cancel'/);
    assert.match(v2, /:tone="kind === 'cancel' \? 'danger' : 'default'"/);
    assert.match(page, /RowDialogs/);
    assert.ok(!page.includes('VisitCancellationDialog'));
    assert.ok(!page.includes('CallReadinessActions'));
});

test('V2 board: status, wait ring and needs-attention follow the Classic rules', () => {
    const { rowStatus, waitingMinutes, needsAttention, waitStats } = load();

    assert.equal(rowStatus(visit()).label, 'Waiting');
    assert.equal(rowStatus(visit()).tone, 'neutral');
    assert.equal(
        rowStatus(visit({ queueStatus: 'serving' })).label,
        'Serving now',
    );
    assert.equal(rowStatus(visit({ isHeld: true })).label, 'On hold');
    assert.equal(rowStatus(visit({ status: 'completed' })).label, 'Completed');
    assert.equal(
        rowStatus(visit({ awaitingBilling: true })).label,
        'Awaiting billing',
    );
    assert.equal(
        rowStatus(visit({ dispensaryStatus: 'pending' })).label,
        'Dispensary: pending',
    );
    assert.equal(
        rowStatus(visit({ status: 'cancelled', queueStatus: null })).label,
        'Cancelled',
    );
    assert.equal(rowStatus(visit({ queueStatus: null })).label, 'Registered');

    assert.equal(waitingMinutes(visit({ durationMinutes: 64 })), 64);
    assert.equal(waitingMinutes(visit({ isHeld: true })), null);
    assert.equal(waitingMinutes(visit({ queueStatus: 'serving' })), null);

    const rows = [
        visit({ durationMinutes: 64 }),
        visit({
            visitNumber: 'KPV-2',
            queueNumber: 'A-002',
            durationMinutes: 12,
            callHold: { active: true, reason: 'Awaiting payment or deposit' },
        }),
    ];
    const items = needsAttention(rows);
    assert.equal(items.length, 2);
    assert.ok(
        items.some(
            (i) => i.minutes === 64 && i.waitText === '64 min · very long wait',
        ),
    );
    assert.ok(
        items.some((i) => i.notReadyReason === 'Awaiting payment or deposit'),
    );
    assert.deepEqual(plain(waitStats(rows)), {
        waitingNow: 2,
        longestWait: 64,
    });
    assert.deepEqual(plain(waitStats([])), {
        waitingNow: 0,
        longestWait: null,
    });
});

test('V2 board page: uses the V2 blocks and the Classic props, tabs and filters; Classic does not import V2', () => {
    for (const block of [
        'V2WorkLayout',
        'V2Tabs',
        'V2Toolbar',
        'V2Table',
        'V2Chip',
        'WaitRing',
        'V2RowMenu',
        'V2Pager',
    ]) {
        assert.ok(page.includes(`components/v2/${block}.vue`), block);
    }

    for (const prop of ['visits', 'options', 'canCreate', 'qrIntakes']) {
        assert.ok(page.includes(`${prop}:`), `V2 prop ${prop}`);
        assert.ok(classic.includes(`${prop}:`), `Classic prop ${prop}`);
    }

    for (const label of [
        'All',
        'Waiting',
        'Serving now',
        'Dispensary',
        'Awaiting billing',
        'Completed',
        'Cancelled',
        'QR intake',
    ]) {
        assert.ok(page.includes(`'${label}'`), label);
    }

    // A tab with no count from the server is not shown for Dispensary and Awaiting billing.
    assert.match(page, /countedTabs\.has\(tab\.value\)/);

    // Reused unchanged: the V2 QR list and the WhatsApp link.
    for (const shared of ['QrIntakeList', 'WhatsAppLink']) {
        assert.ok(page.includes(shared), shared);
    }

    // The rows go in V2Table's named body slot.
    assert.match(page, /<template #body>/);
    // The search box gets the id the live refresh looks for, through V2Toolbar's inputId prop.
    assert.match(page, /:input-id="SEARCH_INPUT_ID"/);
    assert.match(page, /router\.visit\(visit\.historyUrl\)/);
    assert.match(page, /Needs attention/);
    assert.match(
        read('resources/js/components/registration-v2/AttentionList.vue'),
        /visits on this screen only/,
    );
    assert.ok(!/Doctors with a room|Recent activity/.test(page));
    assert.ok(
        !classic.includes('registration-v2') &&
            !classic.includes('components/v2'),
    );
});

test('V2 Mark not ready and Ready dialogs: same endpoint, payload keys and reasons as Classic, opened from the menu', () => {
    const { rowMenu, NOT_READY_REASONS } = load();
    const v2 = read('resources/js/components/registration-v2/RowDialogs.vue');
    const classicActions = read(
        'resources/js/components/queue/CallReadinessActions.vue',
    );

    for (const [code, label] of NOT_READY_REASONS) {
        assert.ok(classicActions.includes(`'${code}'`), code);
        assert.ok(classicActions.includes(`'${label}'`), label);
    }

    for (const key of [
        '/queue/readiness`',
        'expected_branch_id',
        'queue_lock_version',
        'lock_version',
        'call_hold_id',
        'reason_code',
        'idempotency_key',
        "from: 'registration'",
    ]) {
        assert.ok(v2.includes(key), `V2 ${key}`);
        assert.ok(
            classicActions.includes(
                key === "from: 'registration'" ? 'from: props.from' : key,
            ),
            `Classic ${key}`,
        );
    }

    const waiting = {
        queueStatus: 'waiting',
        callHold: { active: false, canSet: true, canClear: false },
    };
    assert.equal(
        rowMenu(visit(waiting)).targets['mark-not-ready'].action,
        'markNotReady',
    );
    assert.equal(
        rowMenu(
            visit({
                ...waiting,
                callHold: {
                    active: true,
                    canSet: false,
                    canClear: true,
                    reason: 'x',
                },
            }),
        ).targets['mark-ready'].action,
        'markReady',
    );
    assert.equal(
        rowMenu(
            visit({
                ...waiting,
                callHold: { active: false, canSet: false, canClear: false },
            }),
        ).targets['mark-not-ready'],
        undefined,
    );
});

test('V2 QR intake tab: same records, links, order and paging as the Classic table, which stays unchanged', () => {
    const v2 = read('resources/js/components/registration-v2/QrIntakeList.vue');
    const classicTable = read(
        'resources/js/components/registration/QrIntakeTable.vue',
    );

    for (const shared of [
        '/registration-review/${item.publicId}',
        'item.canVerify',
        'item.visitUrl',
        "'See a doctor / illness'",
        "'Medical check-up'",
        'Redacted after retention period',
        'No QR intake records are available for this branch.',
        'history-page-change',
        "item.displayStatus === 'pending'",
        "item.displayStatus !== 'pending'",
    ]) {
        assert.ok(v2.includes(shared), `V2 ${shared}`);
        assert.ok(classicTable.includes(shared), `Classic ${shared}`);
    }

    assert.match(page, /QrIntakeList/);
    assert.match(page, /qr_history_page/);
    assert.ok(!classicTable.includes('components/v2'));
});

test('V2 Register visit: same payload, search and rules as the Classic page, which stays unchanged', () => {
    const classicCreate = read('resources/js/pages/Registration/Create.vue');
    const composable = read(
        'resources/js/composables/registration/useRegistrationCreate.ts',
    );
    const v2 = read('resources/js/pages/Registration/V2/Create.vue');

    for (const shared of [
        "'/patients/search'",
        "form.post('/registration'",
        'idempotency_key: props.options.idempotencyKey',
        'expected_branch_id: props.options.branch.id',
        'quick_patient: null',
        'visit_reason_public_ids: []',
        'coverage_panel_note',
        'panel_note_panel_id',
        'confirm_repeat',
        "trimmed.toUpperCase().startsWith('KP-')",
        '/^\\d{12}$/',
        'query.length < 3',
        'phoneError(',
        'identityError(',
        'Please enter the Patient name.',
        'issuing_country_code:',
        "'#quick-patient-name'",
    ]) {
        assert.ok(composable.includes(shared), `composable ${shared}`);
        assert.ok(classicCreate.includes(shared), `Classic ${shared}`);
    }

    for (const shared of [
        'GuardianFields',
        'AdultConfirmation',
        'RegistrationContactFields',
        'WhatsAppLink',
    ]) {
        assert.ok(v2.includes(shared), `V2 ${shared}`);
        assert.ok(classicCreate.includes(shared), `Classic ${shared}`);
    }

    // The V2 phone field keeps the Classic country list and checks.
    const phone = read(
        'resources/js/components/registration-v2/V2PhoneField.vue',
    );
    const classicPhone = read('resources/js/components/patient/PhoneInput.vue');
    assert.match(v2, /V2PhoneField/);

    for (const shared of [
        "from 'libphonenumber-js/max'",
        'getCountryCallingCode(code)',
        'phoneInputError(',
        "'update:country'",
        'unchangedValue',
    ]) {
        assert.ok(phone.includes(shared), `V2 phone ${shared}`);
        assert.ok(classicPhone.includes(shared), `Classic phone ${shared}`);
    }

    // The V2 reason field keeps the Classic picker's endpoints, limit and messages.
    const field = read(
        'resources/js/components/registration-v2/VisitReasonField.vue',
    );
    const picker = read('resources/js/components/visit/VisitReasonPicker.vue');
    assert.match(v2, /VisitReasonField/);
    assert.match(classicCreate, /VisitReasonPicker/);

    for (const shared of [
        '/visit-reasons?',
        "'/visit-reasons'",
        "method: 'POST'",
        'JSON.stringify({ name: query.value })',
        'props.selected.length < 5',
        'isActive === false',
        'ask a Supervisor.',
        'X-CSRF-TOKEN',
    ]) {
        assert.ok(
            field.includes(shared) ||
                field.replace(/\s+/g, ' ').includes(shared),
            `V2 field ${shared}`,
        );
        assert.ok(
            picker.replace(/\s+/g, ' ').includes(shared),
            `Classic picker ${shared}`,
        );
    }

    assert.match(v2, /useRegistrationCreate/);
    assert.match(v2, /quick-patient-name/);
    assert.ok(!classicCreate.includes('composables/registration/'));
    assert.ok(!classicCreate.includes('components/v2'));
});

test('V2 Visit page and Edit visit: same routes, payload keys, permissions and rules as Classic, which stay unchanged', () => {
    const norm = (text) => text.replace(/\s+/g, ' ');
    const classicShow = norm(read('resources/js/pages/Registration/Show.vue'));
    const v2Show = norm(read('resources/js/pages/Registration/V2/Show.vue'));
    const classicEdit = norm(read('resources/js/pages/Registration/Edit.vue'));
    const v2Edit = norm(read('resources/js/pages/Registration/V2/Edit.vue'));

    for (const shared of [
        '/queue`',
        '/cancel`',
        "from: 'registration'",
        'visit_lock_version: props.visit.lockVersion',
        'expected_branch_id',
        'lock_version: props.visit.lockVersion',
        'queue_lock_version: props.visit.queue?.lockVersion ?? null',
        'cancellation_reason',
        'visits.create.branch',
        'medical_certificates.view.branch',
        'attendance_documents.view.branch',
        'attendance_documents.print.branch',
        'visit.hasConsultation',
        'No medical certificate: this visit was cancelled.',
        'Medical certificates are available after the doctor starts the',
        'visit.can.update',
        'visit.can.sendToWaiting',
        'visit.can.cancel',
        '/medical-certificates`',
        '/attendance/${copy.id}',
    ]) {
        assert.ok(v2Show.includes(shared), `V2 Show ${shared}`);
        assert.ok(classicShow.includes(shared), `Classic Show ${shared}`);
    }

    for (const shared of [
        'panel_note_idempotency_key: crypto.randomUUID()',
        'panel_note_panel_id',
        'queue_lock_version: props.visit.queue?.lockVersion ?? null',
        "Reflect.deleteProperty(legacy, 'visit_reason_public_ids')",
        '.patch(`/visits/${props.visit.visitNumber}`)',
        'visit.visitReasons.structured.length',
        'preserved unchanged.',
    ]) {
        assert.ok(v2Edit.includes(shared), `V2 Edit ${shared}`);
        assert.ok(classicEdit.includes(shared), `Classic Edit ${shared}`);
    }

    assert.match(v2Show, /reason-label="Cancellation reason"/);
    assert.match(v2Show, /tone="danger"/);
    assert.ok(!classicShow.includes('components/v2'));
    assert.ok(!classicEdit.includes('components/v2'));
});

test('a long Panel note cannot widen a phone card', () => {
    // Found at 390 and 320 px with a 490-character Panel note (overflow 11 and 81 px): the Payment cell was nowrap and
    // the note was never wrapped in card mode. In cards the cell wraps and the note shows one line.
    assert.ok(page.includes('text-sm xl:whitespace-nowrap'));
    assert.ok(!/class="text-sm whitespace-nowrap"/.test(page));
    assert.ok(page.includes('max-xl:line-clamp-1'));
    assert.ok(page.includes('max-xl:max-w-none'));
    assert.ok(page.includes('max-xl:break-words'));
});

const attention = read(
    'resources/js/components/registration-v2/AttentionList.vue',
);

test('the attention list always stacks, wraps names by word, and names its button for what it does', () => {
    // The list sits in a narrow rail, so the layout must not depend on the viewport width.
    assert.ok(!/sm:grid-cols/.test(attention));
    assert.ok(!/max-sm:/.test(attention));
    assert.ok(attention.includes('grid-cols-[minmax(0,1fr)_auto]'));
    assert.ok(attention.includes('break-words'));
    assert.ok(!attention.includes('break-all'));
    assert.ok(attention.includes('Show in list'));
    assert.ok(
        attention.includes(
            '`Show ${item.label} ${item.patientName} in the list`',
        ),
    );
    assert.ok(!/>\s*View\s*</.test(attention));
    // The chip is its own line under the name.
    assert.ok(attention.indexOf('<V2Chip') > attention.indexOf('Show in list'));
});

test('a long Panel name is cut at table width so it cannot squeeze the Status column', () => {
    assert.ok(page.includes('xl:max-w-36 xl:truncate'));
    assert.ok(page.includes(':title="item.visit.coverageLabel"'));
});

test('the attention list shows Not ready as a plain chip with the reason as text beside it', () => {
    assert.ok(attention.includes('label="Not ready"'));
    assert.ok(/\{\{\s*item\.notReadyReason\s*\}\}/.test(attention));
    assert.ok(!/·\s*\{\{\s*item\.notReadyReason/.test(attention));
    assert.ok(!attention.includes('`Not ready — ${item.notReadyReason}`'));
});

test('the table gives Patient room, hides Payment unless a Panel row is on screen, and never cuts Actions', () => {
    assert.ok(page.includes('const hasPanelRow = computed'));
    assert.ok(/<th v-if="hasPanelRow" scope="col">Payment<\/th>/.test(page));
    assert.ok(/v-if="hasPanelRow"\s+data-label="Payment"/.test(page));
    assert.ok(page.includes('xl:min-w-48'));
    assert.ok(page.includes('xl:max-w-32'));
    assert.ok(/class="w-px text-right whitespace-nowrap"/.test(page));
    assert.ok(page.includes('xl:w-px xl:whitespace-nowrap'));
});
