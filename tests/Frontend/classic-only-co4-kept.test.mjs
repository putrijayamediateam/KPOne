import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Classic only, CO-4: the Classic halves of the Front desk, Patient, Queue and Clinical V2 parity tests, carried over
// so the Classic pages stay pinned once the V2 pages and their tests are removed. Every piece list below is copied
// from the V2 test it came from (named above each test); only the V2 half is dropped.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const squash = (text) => text.replace(/[\s();,]+/g, '');
const loose = (text) => text.replace(/\s+/g, '');

// From ui-v2-queue-parity.test.mjs.
test('Classic queue board keeps its polling, lifecycle, listeners and endpoints', () => {
    const classic = squash(read('resources/js/pages/Queue/Index.vue'));

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
        assert.ok(classic.includes(squash(piece)), `Classic: ${piece}`);
    }

    const raw = read('resources/js/pages/Queue/Index.vue');

    for (const endpoint of ['/queue/room', '/queue/recall', '/encounter']) {
        assert.ok(raw.includes(endpoint), `Classic: ${endpoint}`);
    }
});

// From ui-v2-queue-parity.test.mjs.
test('Classic Call next keeps its endpoint, payload keys and refusal handling', () => {
    const control = read('resources/js/components/queue/CallNextControl.vue');

    assert.ok(control.includes("'/queue/call-next'"));

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
        assert.ok(squash(control).includes(squash(piece)), `Classic: ${piece}`);
    }
});

// From ui-v2-queue-parity.test.mjs.
test('Classic queue dialogs keep their payload keys', () => {
    const keysOf = (source, marker) => {
        const at = source.indexOf(marker);
        const body = source.slice(at, at + 900);

        return [...body.matchAll(/\b(\w+):/g)].map(([, key]) => key);
    };
    const expectKeys = (source, marker, keys) => {
        for (const key of keys) {
            assert.ok(
                keysOf(source, marker).includes(key),
                `Classic ${marker}: ${key}`,
            );
        }
    };

    expectKeys(
        read('resources/js/components/queue/QueueCallDialog.vue'),
        '/queue/call`',
        [
            'expected_branch_id',
            'visit_lock_version',
            'queue_lock_version',
            'override_reason',
            'call_hold_id',
            'call_hold_lock_version',
            'idempotency_key',
        ],
    );
    expectKeys(
        read('resources/js/components/queue/CallReadinessActions.vue'),
        '/queue/readiness`',
        [
            'expected_branch_id',
            'queue_lock_version',
            'lock_version',
            'call_hold_id',
            'reason_code',
            'idempotency_key',
            'from',
        ],
    );
    expectKeys(
        read(
            'resources/js/components/patient-board/VisitCancellationDialog.vue',
        ),
        '/cancel`',
        [
            'expected_branch_id',
            'lock_version',
            'queue_lock_version',
            'cancellation_reason',
        ],
    );
    assert.ok(
        keysOf(
            read('resources/js/pages/Queue/Index.vue'),
            '/queue/treatment-call`',
        ).includes('branch_room_id'),
    );
});

// From ui-v2-clinical-parity.test.mjs.
test('Classic consultation page keeps its save, hold and resume payloads and its unsaved-work dialog', () => {
    const flat = read('resources/js/pages/Clinical/Show.vue').replace(
        /\s+/g,
        ' ',
    );

    assert.match(
        flat,
        /form\.patch\( `\/visits\/\$\{encodeURIComponent\(props\.clinical\.visit\.visitNumber\)\}\/encounter`/,
    );
    assert.match(
        flat,
        /router\.post\( `\/visits\/\$\{encodeURIComponent\(props\.clinical\.visit\.visitNumber\)\}\/encounter\/\$\{action\}`/,
    );
    assert.match(
        flat,
        /expected_branch_id: props\.clinical\.branch\.id, visit_lock_version: props\.clinical\.visit\.lockVersion, queue_lock_version: props\.clinical\.queue\.lockVersion, encounter_lock_version: props\.clinical\.encounter\.lockVersion, idempotency_key: action === 'hold' \? props\.clinical\.hold\.holdIdempotencyKey : props\.clinical\.hold\.resumeIdempotencyKey/,
    );
    assert.match(
        flat,
        /expected_branch_id: props\.clinical\.branch\.id, lock_version: props\.clinical\.encounter\.lockVersion, clinical_note: props\.clinical\.encounter\.clinicalNote \?\? '', vitals: vitalsFromProps\(\), diagnoses: diagnosesFromProps\(\)/,
    );
    // Classic's own page passes no before-start hook to Call next.
    assert.doesNotMatch(flat, /before-start/);

    const dialog = read(
        'resources/js/components/clinical/UnsavedClinicalWorkDialog.vue',
    );

    for (const fragment of [
        "'save-and-hold': []",
        "'hold-without-saving': []",
        "processingAction: 'confirm' | 'secondary'",
        'unsavedItems: string[]',
    ]) {
        assert.ok(dialog.includes(fragment), fragment);
    }
});

// From v2-dispensary-attention-panel.test.mjs.
test('Classic consultation page keeps its Dispensary attention panel and request', () => {
    const page = read('resources/js/pages/Clinical/Show.vue');
    const panel = read(
        'resources/js/pages/Clinical/Partials/DispensaryAttentionPanel.vue',
    );

    assert.match(
        page,
        /import DispensaryAttentionPanel from '@\/pages\/Clinical\/Partials\/DispensaryAttentionPanel\.vue';/,
    );
    assert.ok(!/\/V2\//.test(panel));

    for (const fragment of [
        'defineProps<{ items: DoctorDispensaryAttention[] }>()',
        'item.acknowledgeUrl,',
        'case_lock_version: item.caseLockVersion,',
        'item_lock_version: item.itemLockVersion,',
        'preserveScroll: true,',
        'Object.values(errors)[0] ??',
        "'This Dispensary proposal changed. Refresh and review it again.'",
        'onFinish: () => (processing.value = null),',
        'Dispensary attention required',
        "item.status === 'awaiting_acknowledgement'",
        'Acknowledge partial fulfilment',
        'processing.value = item.exceptionPublicId;',
        ':disabled="processing === item.exceptionPublicId"',
    ]) {
        assert.ok(panel.includes(fragment), `Classic ${fragment}`);
    }

    assert.ok(panel.indexOf('router.post(') >= 0);
});

// From v2-patient-create.test.mjs.
test('Classic Patient create keeps its request', () => {
    const page = loose(read('resources/js/pages/Patient/Create.vue'));

    for (const piece of [
        'adult_confirmed: false',
        'adult_confirmation_key: crypto.randomUUID()',
        'expected_branch_id',
        "phone_country: 'MY'",
        "country_code: 'MY'",
        'duplicate_override: false',
        "identifier_type: 'nric', issuing_country_code: 'MY', value: ''",
        "form.post('/patients', { preserveState: true, onError: focusInvalidField })",
        'phoneError(',
        '!knownMinor(form.date_of_birth, props.guardianToday)',
        'identityError(',
        'identifierIssuer(',
        "form.setError('full_name', 'Please enter the Patient name.')",
        'const { adult_confirmation_key, adult_confirmed, ...rest } = data;',
        '!data.date_of_birth',
    ]) {
        assert.ok(page.includes(loose(piece)), `Classic lacks ${piece}`);
    }
});

// From v2-patient-details.test.mjs.
test('Classic Patient details keeps its props, flags, identifier routes, merge requests and WhatsApp label', () => {
    const page = read('resources/js/pages/Patient/Show.vue');

    for (const piece of [
        'patient: PatientDetail',
        'merge?: PatientMergePanelData',
        'history?: PatientChangeHistoryData | null',
        'mergedGuardian?:',
        'fromPatientNumber: string',
        'patient.can.update',
        'patient.can.manageIdentifiers',
        "'/identifiers'",
        "'/identifiers/' +",
        "'/retire'",
        'identifier_type',
        'issuing_country_code',
        'identifierForm.patch(url, options)',
        'identifierForm.post(url, options)',
    ]) {
        assert.ok(page.includes(piece), `Classic lacks ${piece}`);
    }

    const merge = read('resources/js/components/patient/PatientMergePanel.vue');

    for (const piece of [
        '/merge-compare?other_patient_number=',
        "'/merge-requests'",
        '`/patient-merges/${request.publicId}/${action}`',
        "from: 'patient'",
        'patient_number: props.patientNumber',
        'kept_lock_version',
        'other_lock_version',
        'idempotency_key',
        'expected_branch_id',
        'lock_version: request.lockVersion',
    ]) {
        assert.ok(merge.includes(piece), `Classic lacks ${piece}`);
    }

    assert.ok(
        read('resources/js/components/patient/WhatsAppLink.vue').includes(
            'aria-label="Open WhatsApp chat"',
        ),
    );
});

// From v2-patient-directory.test.mjs.
test('Classic Patient directory keeps its search request and search types', () => {
    const page = read('resources/js/pages/Patient/Index.vue');

    for (const piece of [
        "fetch('/patients/search'",
        "method: 'POST'",
        "credentials: 'same-origin'",
        "'X-CSRF-TOKEN': csrf()",
        'JSON.stringify({ ...form, page })',
        "search_type: 'name'",
        'issuing_country_code',
        "'Search could not be completed.'",
        'meta[name="csrf-token"]',
        '<fieldset',
        '<legend',
        'name="patient-search-type"',
        'type="radio"',
        'v-model="form.search_type"',
    ]) {
        assert.ok(page.includes(piece), `Classic lacks ${piece}`);
    }

    for (const value of [
        'name',
        'patient_number',
        'nric',
        'passport',
        'phone',
    ]) {
        assert.ok(page.includes(`value: '${value}'`), value);
    }
});

// From v2-patient-edit.test.mjs.
test('Classic Patient edit keeps its request, form keys and guardian rules', () => {
    const page = read('resources/js/pages/Patient/Edit.vue');

    for (const piece of [
        'adult_confirmed: false',
        'adult_confirmation_key: crypto.randomUUID()',
        'retry_key: crypto.randomUUID()',
        'expected_branch_id',
        'lock_version: props.patient.administrative.lockVersion',
        "phone_country: 'MY'",
        'form.patch(',
        "'/patients/' + encodeURIComponent(props.patient.patientNumber)",
        'preserveState: true',
        'onError: focusInvalidField',
        'phoneError(',
        '!knownMinor(form.date_of_birth, props.guardianToday)',
        'const { adult_confirmation_key, adult_confirmed, ...rest } = data;',
        '!data.date_of_birth && !!props.patient.identity.dateOfBirth',
    ]) {
        assert.ok(page.includes(piece), `Classic lacks ${piece}`);
    }

    const fields = read(
        'resources/js/components/patient/PatientFormFields.vue',
    );
    const guardian = read('resources/js/components/patient/GuardianFields.vue');

    for (const key of [
        'full_name',
        'date_of_birth',
        'sex',
        'nationality_code',
        'mobile_phone',
        'phone_country',
        'email',
        'address_line_1',
        'address_line_2',
        'postcode',
        'city',
        'state',
        'country_code',
    ]) {
        assert.ok(fields.includes(`fields.${key}`), `Classic lacks ${key}`);
    }

    for (const piece of [
        'knownMinor(',
        'blankGuardian()',
        'lock_version === 0',
        'crypto.randomUUID()',
        'requireDateOfBirth',
        'requireNationality',
        'requireEmail',
        'requireAddress',
    ]) {
        assert.ok(
            fields.includes(piece) || guardian.includes(piece),
            `Classic lacks ${piece}`,
        );
    }
});

// From v2-patient-merges.test.mjs.
test('Classic merge requests page keeps its prop, flags and request', () => {
    const page = loose(read('resources/js/pages/Patient/MergeRequests.vue'));

    for (const piece of [
        'requests: { open: MergeRequestRow[]; recent: MergeRequestRow[] };',
        'canDecide: boolean;',
        'canWithdraw: boolean;',
        'lockVersion: number;',
        '`/patient-merges/${request.publicId}/${action}`',
        'expected_branch_id: branchId',
        'lock_version: request.lockVersion',
        'preserveScroll: true',
        "proposed: 'Waiting'",
        "approved: 'Approved'",
        "rejected: 'Rejected'",
        "withdrawn: 'Withdrawn'",
    ]) {
        assert.ok(page.includes(loose(piece)), `Classic lacks ${piece}`);
    }
});

// From v2-registration-board.test.mjs and v2-registration-rail.test.mjs.
test('Classic Registration board and Edit visit keep their routes, payload keys and props', () => {
    const board = read('resources/js/pages/Registration/Index.vue');

    for (const [route, keys] of [
        [
            '/queue`',
            [
                'expected_branch_id',
                'visit_lock_version',
                "from: 'registration'",
            ],
        ],
        [
            '/queue/call`',
            [
                'expected_branch_id',
                'visit_lock_version',
                'queue_lock_version',
                "from: 'registration'",
            ],
        ],
        [
            '/encounter`',
            ['expected_branch_id', 'visit_lock_version', 'queue_lock_version'],
        ],
        ['/dispense`', ['expected_branch_id']],
        ['/otc-queue/call`', ['expected_branch_id']],
    ]) {
        assert.ok(board.includes(route), `Classic route ${route}`);

        for (const key of keys) {
            assert.ok(board.includes(key), `Classic key ${key}`);
        }
    }

    for (const prop of ['visits', 'options', 'canCreate', 'qrIntakes']) {
        assert.ok(board.includes(`${prop}:`), `Classic prop ${prop}`);
    }

    const edit = read('resources/js/pages/Registration/Edit.vue');

    for (const piece of ['visit_type', 'coverage_type', 'lock_version']) {
        assert.ok(edit.includes(piece), `Classic lacks ${piece}`);
    }
});

// From ui-v2-clinical-parity.test.mjs (the exact-copy test pinned these on Classic through the shared body).
test('Classic consultation page keeps its endpoints, save-before-hold, drift rule and treatment-room call', () => {
    const page = read('resources/js/pages/Clinical/Show.vue');
    const flat = page.replace(/\s+/g, ' ');

    for (const piece of [
        '/queue/recall',
        '/queue/treatment-call',
        '/encounter/${action}',
        'queue_lock_version',
        'encounter_lock_version',
        'visit_lock_version',
        'expected_branch_id',
        'idempotency_key',
    ]) {
        assert.ok(page.includes(piece), `Classic ${piece}`);
    }

    assert.match(flat, /const saveAndHold = \(\) => \{/);
    assert.match(flat, /panel\.saveForHold\(proceedToHold/);
    assert.match(
        flat,
        /if \(form\.isDirty\) \{ recordDrifted\.value = true; return; \}/,
    );
    assert.match(
        flat,
        /const requestHold = \(\) => \{ if \(!hasUnsavedWork\.value\)/,
    );
    assert.match(
        flat,
        /treatment-call`, \{ expected_branch_id: props\.clinical\.branch\.id, queue_lock_version: props\.clinical\.queue\.lockVersion, branch_room_id: treatmentRoomId\.value, from: 'consultation', \}, \{ preserveScroll: true, preserveState: true,/,
    );
});

// From v2-patient-details.test.mjs, v2-patient-directory.test.mjs and v2-patient-edit.test.mjs.
test('Classic Patient pages keep the merge gates, search result keys and guardian relationships', () => {
    const merge = read('resources/js/components/patient/PatientMergePanel.vue');

    for (const gate of [
        'merge.canPropose',
        'request.canDecide',
        'request.canWithdraw',
        'comparison.blockers.length',
    ]) {
        assert.ok(merge.includes(gate), gate);
    }

    const directory = read('resources/js/pages/Patient/Index.vue');

    for (const key of [
        'canWhatsapp',
        'patientNumber',
        'fullName',
        'dateOfBirth',
        'identifier',
        'maskedPhone',
        'mergedFrom',
    ]) {
        assert.ok(directory.includes(key), key);
    }

    const guardian = read('resources/js/components/patient/GuardianFields.vue');

    for (const relationship of [
        'mother',
        'father',
        'legal_guardian',
        'other_family_member',
    ]) {
        assert.ok(guardian.includes(relationship), relationship);
    }
});

// From v2-registration-board.test.mjs.
test('Classic Registration board keeps its search route, poll backoff, pause selectors, failure notice and filters', () => {
    const board = read('resources/js/pages/Registration/Index.vue');

    assert.match(board, /fetch\('\/registration\/search'/);
    assert.match(board, /10_000 \* 2 \*\* pollFailures/);

    for (const text of [
        'Live refresh paused. Showing last successful results.',
        ' Press Search to try again.',
        'Registration search could not be completed.',
    ]) {
        assert.ok(board.includes(text), `Classic has: ${text}`);
    }

    const overlay = board.match(
        /'(\[role="menu"\]\[data-state="open"\][^']+)'/,
    )?.[1];
    const typing = board.match(/'(input, textarea, select[^']+)'/)?.[1];
    assert.ok(overlay && typing, 'Classic selectors found');
    assert.match(board, /registration-board-search/);
    assert.match(board, /JSON\.stringify\(\{ \.\.\.appliedFilters, page \}\)/);

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
        assert.ok(board.includes(`${key}:`), `Classic form has ${key}`);
    }

    assert.ok(board.includes("'/registration?tab=qr-intake'"));
});

// From v2-registration-board.test.mjs (reasons were read from the deleted lib/registration-v2.ts; written out here).
test('Classic Not ready and Ready actions keep their reasons and request; the QR intake table keeps its records and links', () => {
    const actions = read(
        'resources/js/components/queue/CallReadinessActions.vue',
    );

    for (const [code, label] of [
        ['awaiting_panel_confirmation', 'Awaiting Panel confirmation'],
        ['awaiting_patient_details', 'Awaiting patient details'],
        ['awaiting_payment_or_deposit', 'Awaiting payment or deposit'],
        ['other_administrative', 'Other administrative'],
    ]) {
        assert.ok(actions.includes(`'${code}'`), code);
        assert.ok(actions.includes(`'${label}'`), label);
    }

    for (const key of [
        '/queue/readiness`',
        'expected_branch_id',
        'queue_lock_version',
        'lock_version',
        'call_hold_id',
        'reason_code',
        'idempotency_key',
        'from: props.from',
    ]) {
        assert.ok(actions.includes(key), `Classic ${key}`);
    }

    const table = read(
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
        assert.ok(table.includes(shared), `Classic ${shared}`);
    }
});

// From v2-registration-board.test.mjs.
test('Classic Register visit keeps its payload, search, rules, shared fields, phone checks and reason picker', () => {
    const create = read('resources/js/pages/Registration/Create.vue');

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
        'GuardianFields',
        'AdultConfirmation',
        'RegistrationContactFields',
        'WhatsAppLink',
        'VisitReasonPicker',
    ]) {
        assert.ok(create.includes(shared), `Classic ${shared}`);
    }

    const phone = read('resources/js/components/patient/PhoneInput.vue');

    for (const shared of [
        "from 'libphonenumber-js/max'",
        'getCountryCallingCode(code)',
        'phoneInputError(',
        "'update:country'",
        'unchangedValue',
    ]) {
        assert.ok(phone.includes(shared), `Classic phone ${shared}`);
    }

    const picker = read(
        'resources/js/components/visit/VisitReasonPicker.vue',
    ).replace(/\s+/g, ' ');

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
        assert.ok(picker.includes(shared), `Classic picker ${shared}`);
    }
});

// From v2-registration-board.test.mjs.
test('Classic Visit page and Edit visit keep their routes, payload keys, permissions and rules', () => {
    const norm = (text) => text.replace(/\s+/g, ' ');
    const show = norm(read('resources/js/pages/Registration/Show.vue'));
    const edit = norm(read('resources/js/pages/Registration/Edit.vue'));

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
        assert.ok(show.includes(shared), `Classic Show ${shared}`);
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
        assert.ok(edit.includes(shared), `Classic Edit ${shared}`);
    }
});

// From v2-registration-board.test.mjs: Classic Registration files never import V2 code.
test('Classic Registration files import no V2 code', () => {
    const board = read('resources/js/pages/Registration/Index.vue');
    const create = read('resources/js/pages/Registration/Create.vue');

    assert.ok(
        !board.includes('registration-v2') && !board.includes('components/v2'),
    );
    assert.ok(
        !read(
            'resources/js/components/registration/QrIntakeTable.vue',
        ).includes('components/v2'),
    );
    assert.ok(!create.includes('composables/registration/'));
    assert.ok(!create.includes('components/v2'));
    assert.ok(
        !read('resources/js/pages/Registration/Show.vue').includes(
            'components/v2',
        ),
    );
    assert.ok(
        !read('resources/js/pages/Registration/Edit.vue').includes(
            'components/v2',
        ),
    );
});
