import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as Vue from 'vue';
const passthrough = Vue.defineComponent({
    setup:
        (_, { slots }) =>
        () =>
            Vue.h('span', slots.default?.()),
});
const mountedControls = [];
const control = (kind, props, emit, render) => {
    const entry = { kind, props, emit, active: true };
    mountedControls.push(entry);
    Vue.onBeforeUnmount(() => {
        entry.active = false;
    });

    return render;
};
function load(file) {
    const source = readFileSync(file, 'utf8');
    const script = file.endsWith('.vue')
        ? compileScript(parse(source).descriptor, {
              id: file,
              inlineTemplate: true,
          }).content
        : source;
    const module = { exports: {} };
    runInNewContext(
        ts.transpileModule(script, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        {
            exports: module.exports,
            setInterval,
            clearInterval,
            require: (name) => {
                if (name === 'vue') {
                    return Vue;
                }

                if (name === './PatientBoardStatus.vue') {
                    return {
                        default: load(
                            'resources/js/components/patient-board/PatientBoardStatus.vue',
                        ).default,
                    };
                }

                if (name === './PatientBoardTreatmentResults.vue') {
                    return {
                        default: load(
                            'resources/js/components/patient-board/PatientBoardTreatmentResults.vue',
                        ).default,
                    };
                }

                if (name === '@/lib/waiting-time') {
                    return load('resources/js/lib/waiting-time.ts');
                }

                if (name === '@/components/queue/TreatmentProgressBadge.vue') {
                    return {
                        default: load(
                            'resources/js/components/queue/TreatmentProgressBadge.vue',
                        ).default,
                    };
                }

                if (
                    name === '@/lib/presentation' ||
                    name === '@/lib/treatment-progress'
                ) {
                    return load(
                        'resources/js/lib/' + name.split('/').at(-1) + '.ts',
                    );
                }

                if (name === '@/components/ui/disclosure') {
                    return {
                        DisclosureText: Vue.defineComponent({
                            props: ['text'],
                            setup: (props) => () => Vue.h('span', props.text),
                        }),
                    };
                }

                if (name === '@/components/queue/CallReadinessActions.vue') {
                    return {
                        default: Vue.defineComponent({
                            props: ['row'],
                            setup: (props, { emit }) =>
                                control('readiness', props, emit, () =>
                                    Vue.h(
                                        'button',
                                        {
                                            'data-readiness-permitted':
                                                props.row.callHold?.canClear,
                                        },
                                        'Existing readiness actions',
                                    ),
                                ),
                        }),
                    };
                }

                if (
                    name === '@/components/queue/TreatmentProgressActions.vue'
                ) {
                    return {
                        default: Vue.defineComponent({
                            props: ['open'],
                            setup: (props, { emit }) =>
                                control('treatment', props, emit, () =>
                                    Vue.h(
                                        'span',
                                        { 'data-dialog-open': props.open },
                                        'Existing treatment controls',
                                    ),
                                ),
                        }),
                    };
                }

                return new Proxy(
                    {},
                    { get: (_, key) => (key === 'router' ? {} : passthrough) },
                );
            },
        },
    );

    return module.exports;
}
const Board = load(
    'resources/js/components/patient-board/PatientBoard.vue',
).default;
const row = {
    key: 'synthetic',
    visitNumber: 'KPV-1',
    patientName: 'Synthetic Patient',
    patientNumber: 'KP-1',
    queueNumber: 'A-001',
    coverageLabel: 'Self-pay',
    doctorName: 'Synthetic Doctor',
    durationLabel: '5 min',
    statusLabel: 'Waiting',
    statusTone: 'waiting',
    visitNotes: 'Synthetic reason',
    can: {},
    actions: {},
    triage: null,
};
const render = (props = {}, extra = {}) =>
    renderToString(
        Vue.createSSRApp(Board, {
            rows: [{ ...row, ...extra }],
            branchId: 3,
            ...props,
        }),
    );
const headers = (html) =>
    [...html.matchAll(/<th(?:\s[^>]*)?>([\s\S]*?)<\/th>/g)].map((match) =>
        match[1].trim(),
    );
test('own Waiting and Seeing use six actual task headers and matching mobile labels', async () => {
    for (const [task, label] of [
        ['waiting', 'Waiting'],
        ['seeing', 'Since'],
    ]) {
        const html = await render({ queueLayout: 'own', queueTask: task });
        assert.deepEqual(headers(html), [
            'No.',
            'Patient',
            'Zone',
            'Reason',
            label,
            'Actions',
        ]);
        assert.match(html, new RegExp(`data-label="${label}"`));
        assert.match(html, /data-label="Reason"/);
        assert.doesNotMatch(html, /data-label="Status"/);
    }
});
test('withheld triage is never presented as untriaged; explicit states retain their meaning', async () => {
    const props = { queueLayout: 'own', queueTask: 'waiting' };
    assert.doesNotMatch(await render(props), /No triage yet|triage-zone-chip/);
    assert.match(
        await render(props, { triage: { triaged: false } }),
        /No triage yet/,
    );

    for (const zone of ['red', 'yellow', 'green', null]) {
        const html = await render(props, { triage: { triaged: true, zone } });
        assert.equal(
            (html.match(/data-testid="triage-zone-chip"/g) ?? []).length,
            1,
        );
        assert.match(
            html,
            new RegExp(
                zone
                    ? `${zone[0].toUpperCase() + zone.slice(1)} zone`
                    : 'Triaged',
            ),
        );
    }
});
test('own patient context retains safety metadata, authority and one treatment instance', async () => {
    const html = await render(
        { queueLayout: 'own', queueTask: 'seeing' },
        {
            priority: 'urgent',
            returnedFromDispensary: true,
            dispensaryCheckWaiting: true,
            doctorWarning: { code: 'no_room', label: 'No room assigned' },
            callHold: {
                active: true,
                reason: 'Patient requested',
                canClear: true,
            },
            treatmentProgress: {
                state: 'done',
                label: 'Nebuliser',
                startedAt: null,
                doneAt: null,
                outcome: {
                    procedureLabel: 'Nebuliser',
                    outcomeLabel: 'Not successful',
                    reasonLabel: 'Patient declined',
                },
            },
        },
    );

    for (const label of [
        'Urgent',
        'Returned',
        'Medicine ready for your check',
        'Not ready to call',
        'Patient requested',
        'No room assigned',
        'Reassign',
        'Not successful',
    ]) {
        assert.match(html, new RegExp(label));
    }

    assert.equal((html.match(/data-dialog-open=/g) ?? []).length, 1);
    assert.equal(
        (html.match(/data-readiness-permitted="true"/g) ?? []).length,
        1,
    );
    assert.equal(
        (html.match(/data-testid="treatment-progress-badge"/g) ?? []).length,
        1,
    );
});
test('Registration, branch and ordinary emergency/default layouts retain their columns and triage', async () => {
    for (const queueLayout of [undefined, 'branch', 'own']) {
        const html = await render(
            { queueLayout },
            {
                triage: { triaged: true, zone: 'red' },
                emergency: {
                    alertLabel: 'Recorded time',
                    takenLabel: 'Not taken',
                },
            },
        );
        assert.equal(headers(html).length, queueLayout ? 6 : 9);
        assert.match(html, /data-label="Status"/);
        assert.equal(
            (html.match(/data-testid="triage-zone-chip"/g) ?? []).length,
            1,
        );
        assert.match(html, /Recorded time/);
        assert.match(html, /Not taken/);
    }

    const branch = await render({
        queueLayout: 'branch',
        queueTask: 'waiting',
    });
    assert.deepEqual(headers(branch), [
        'No.',
        'Patient',
        'Doctor',
        'Status',
        'Waiting',
        'Actions',
    ]);
});
test('empty own task spans exactly the six rendered columns', async () => {
    for (const queueTask of ['waiting', 'seeing']) {
        const html = await render({ queueLayout: 'own', queueTask, rows: [] });
        assert.equal(headers(html).length, 6);
        assert.match(html, /colspan="6"/);
    }
});

test('actual renderer forwards readiness once and retains only one actionable treatment instance across layout changes', async () => {
    mountedControls.length = 0;
    const node = () => ({ children: [], parent: null });
    const renderer = Vue.createRenderer({
        createElement: node,
        createText: node,
        createComment: node,
        setText() {},
        setElementText() {},
        patchProp() {},
        insert(child, parent, anchor) {
            if (child.parent) {
                const i = child.parent.children.indexOf(child);

                if (i >= 0) {
                    child.parent.children.splice(i, 1);
                }
            }

            child.parent = parent;
            const i = anchor ? parent.children.indexOf(anchor) : -1;

            if (i < 0) {
                parent.children.push(child);
            } else {
                parent.children.splice(i, 0, child);
            }
        },
        remove(child) {
            if (child.parent) {
                const i = child.parent.children.indexOf(child);

                if (i >= 0) {
                    child.parent.children.splice(i, 1);
                }
            }

            child.parent = null;
        },
        parentNode: (child) => child.parent,
        nextSibling: (child) =>
            child.parent?.children[child.parent.children.indexOf(child) + 1] ??
            null,
    });
    const props = Vue.reactive({ queueLayout: 'own', queueTask: 'waiting' });
    let readiness = 0;
    const app = renderer.createApp({
        setup: () => () =>
            Vue.h(Board, {
                ...props,
                branchId: 3,
                rows: [
                    {
                        ...row,
                        treatmentProgress: {
                            state: 'started',
                            canManage: true,
                        },
                    },
                ],
                onReadinessChanged: () => readiness++,
            }),
    });
    app.mount(node());
    const active = (kind) =>
        mountedControls.filter((entry) => entry.active && entry.kind === kind);
    assert.equal(active('treatment').length, 1);
    assert.equal(active('readiness').length, 1);
    active('readiness')[0].emit('changed');
    await Vue.nextTick();
    assert.equal(readiness, 1);
    active('treatment')[0].emit('update:open', true);
    await Vue.nextTick();
    assert.equal(active('treatment')[0].props.open, true);
    props.queueTask = undefined;
    await Vue.nextTick();
    assert.equal(active('treatment').length, 1);
    assert.equal(active('readiness').length, 1);
    assert.equal(active('treatment')[0].props.open, true);
    active('treatment')[0].emit('update:open', false);
    await Vue.nextTick();
    assert.equal(active('treatment')[0].props.open, false);
    props.queueTask = 'seeing';
    await Vue.nextTick();
    assert.equal(active('treatment').length, 1);
    assert.equal(active('treatment')[0].props.open, false);
    props.queueTask = 'held';
    await Vue.nextTick();
    assert.equal(active('treatment').length, 1);
    active('treatment')[0].emit('update:open', true);
    await Vue.nextTick();
    assert.equal(active('treatment')[0].props.open, true);
    active('readiness')[0].emit('changed');
    await Vue.nextTick();
    assert.equal(readiness, 2);
    props.queueTask = 'treatment';
    await Vue.nextTick();
    assert.equal(active('treatment').length, 1);
    assert.equal(active('treatment')[0].props.open, true);
    app.unmount();
    assert.equal(active('treatment').length, 0);
    assert.equal(active('readiness').length, 0);
});

test('own Held uses the existing held duration and dedicated treatment step without guessing missing progress', async () => {
    const html = await render(
        { queueLayout: 'own', queueTask: 'held' },
        {
            statusLabel: 'On Hold',
            statusTone: 'held',
            durationLabel: 'Held 17 min',
            waitingMinutes: null,
        },
    );
    assert.deepEqual(headers(html), [
        'No.',
        'Patient',
        'Zone',
        'Treatment step',
        'Held',
        'Actions',
    ]);
    assert.match(html, /data-label="Held"/);
    assert.match(html, /Held 17 min/);
    assert.match(html, /data-label="Treatment step"/);
    assert.match(html, /Not available/);
    assert.doesNotMatch(html, /data-testid="treatment-progress-badge"/);
    assert.doesNotMatch(html, /Synthetic reason|Resume|data-label="Status"/);
    const empty = await render({
        queueLayout: 'own',
        queueTask: 'held',
        rows: [],
    });
    assert.equal(headers(empty).length, 6);
    assert.match(empty, /colspan="6"/);
});

test('Held renders the actual treatment badge once with authorized unsuccessful outcomes and retained controls', async () => {
    const props = { queueLayout: 'own', queueTask: 'held' };
    const progress = {
        state: 'done',
        label: 'Nebuliser',
        startedAt: null,
        doneAt: null,
        canManage: true,
        outcome: {
            procedureLabel: 'Nebuliser',
            outcomeLabel: 'Not successful',
            reasonLabel: 'Patient declined',
        },
    };
    const html = await render(props, {
        statusLabel: 'On Hold',
        statusTone: 'held',
        durationLabel: 'Held 3 min',
        treatmentProgress: progress,
        callHold: {
            active: true,
            reason: 'Synthetic readiness',
            canClear: true,
        },
    });
    assert.equal(
        (html.match(/data-testid="treatment-progress-badge"/g) ?? []).length,
        1,
    );
    assert.equal((html.match(/data-dialog-open=/g) ?? []).length, 1);
    assert.match(html, /Nebuliser/);
    assert.match(html, /Not successful/);
    assert.match(html, /Patient declined/);
    assert.match(html, /Not ready to call/);
    assert.match(html, /Synthetic readiness/);
    const patient = html.slice(
        html.indexOf('data-label="Patient"'),
        html.indexOf('data-label="Zone"'),
    );
    assert.doesNotMatch(patient, /data-testid="treatment-progress-badge"/);
    assert.match(patient, /Existing treatment controls/);
    const redacted = await render(props, {
        treatmentProgress: { ...progress, outcome: undefined },
    });
    assert.doesNotMatch(
        redacted,
        /Not successful|Patient declined|treatment-record-outcome/,
    );
    const running = await render(props, {
        treatmentProgress: {
            ...progress,
            state: 'started',
            outcome: undefined,
        },
        treatmentMinutes: 4,
    });
    assert.match(running, /data-treatment-state="started"/);
    assert.doesNotMatch(running, /Not successful|Patient declined/);
    const noCurrentStep = await render(props, {
        treatmentProgress: { state: null, label: null, canManage: true },
    });
    assert.match(noCurrentStep, /Not available/);
    assert.equal((noCurrentStep.match(/data-dialog-open=/g) ?? []).length, 1);
});

test('branch Held uses dedicated step and held columns while retaining one actual badge', async () => {
    const html = await render(
        { queueLayout: 'branch', queueTask: 'held' },
        {
            treatmentProgress: {
                state: 'done',
                label: 'Nebuliser',
                startedAt: null,
                doneAt: null,
            },
        },
    );
    assert.deepEqual(headers(html), [
        'No.',
        'Patient',
        'Doctor',
        'Treatment step',
        'Held',
        'Actions',
    ]);
    assert.doesNotMatch(html, /data-label="Status"/);
    const patient = html.slice(
        html.indexOf('data-label="Patient"'),
        html.indexOf('data-label="Doctor"'),
    );
    assert.match(patient, /Existing readiness actions/);
    assert.equal(
        (html.match(/data-testid="treatment-progress-badge"/g) ?? []).length,
        1,
    );
});

test('Treatment columns pair each planned step with its exact recorded procedure and outcome without duplicate outcome text', async () => {
    const progress = {
        state: 'done',
        label: 'Nebuliser',
        startedAt: null,
        doneAt: null,
        outcome: {
            procedureLabel: 'Wound dressing',
            outcomeLabel: 'Not successful',
            reasonLabel: 'Patient declined',
        },
        waiting: [
            {
                label: 'Observation',
                doneAt: null,
                outcome: {
                    procedureLabel: 'Observation',
                    outcomeLabel: 'Done',
                    reasonLabel: null,
                },
            },
        ],
    };
    const html = await render(
        { queueLayout: 'own', queueTask: 'treatment' },
        { treatmentProgress: progress },
    );
    assert.deepEqual(headers(html), [
        'No.',
        'Patient',
        'Zone',
        'Treatment',
        'Result',
        'Actions',
    ]);
    assert.match(html, /data-label="Treatment"/);
    assert.match(html, /data-label="Result"/);
    assert.doesNotMatch(html, /data-label="Duration"|data-label="Since"/);
    assert.equal(
        (html.match(/data-testid="treatment-progress-badge"/g) ?? []).length,
        1,
    );
    assert.equal((html.match(/Not successful/g) ?? []).length, 1);
    assert.equal((html.match(/Patient declined/g) ?? []).length, 1);
    const result = html.slice(
        html.indexOf('data-label="Result"'),
        html.indexOf('data-label="Actions"'),
    );
    assert.ok(
        result.indexOf('Step: Nebuliser') <
            result.indexOf('Recorded procedure: Wound dressing'),
    );
    assert.ok(
        result.indexOf('Recorded procedure: Wound dressing') <
            result.indexOf('Step: Observation'),
    );
    assert.match(result, /aria-label="Recorded treatment outcomes"/);
    assert.match(result, /Recorded procedure: Observation/);
    assert.doesNotMatch(result, /Resume|acknowledge/);
});

test('running current and completed waiting steps keep unavailable and recorded outcomes separately ordered', async () => {
    const html = await render(
        { queueLayout: 'own', queueTask: 'treatment' },
        {
            treatmentProgress: {
                state: 'started',
                label: 'IV drip',
                startedAt: null,
                doneAt: null,
                waiting: [
                    {
                        label: 'Nebuliser',
                        doneAt: null,
                        outcome: {
                            procedureLabel: 'Nebuliser',
                            outcomeLabel: 'Not successful',
                            reasonLabel: 'Patient declined',
                        },
                    },
                    { label: 'Observation', doneAt: null },
                ],
            },
        },
    );
    const result = html.slice(
        html.indexOf('data-label="Result"'),
        html.indexOf('data-label="Actions"'),
    );
    assert.equal((result.match(/Outcome not available/g) ?? []).length, 2);
    assert.ok(
        result.indexOf('Step: IV drip') < result.indexOf('Step: Nebuliser'),
    );
    assert.ok(
        result.indexOf('Step: Nebuliser') < result.indexOf('Step: Observation'),
    );
    assert.equal((result.match(/Not successful/g) ?? []).length, 1);
    const empty = await render({ queueLayout: 'own', queueTask: 'treatment' });
    assert.match(empty, /Outcome not available/);
    assert.doesNotMatch(empty, /Recorded procedure:|Not successful|>Done</);
    const escaped = await render(
        { queueLayout: 'own', queueTask: 'treatment' },
        {
            treatmentProgress: {
                state: 'done',
                label: '<img src=x>',
                startedAt: null,
                doneAt: null,
            },
        },
    );
    assert.match(escaped, /Step: &lt;img src=x&gt;/);
    assert.doesNotMatch(escaped, /<img src=x>/);
});

test('badge outcome opt-out preserves default visible and accessible outcome evidence plus unsuccessful warning tone', async () => {
    const Badge = load(
        'resources/js/components/queue/TreatmentProgressBadge.vue',
    ).default;
    const progress = {
        state: 'done',
        label: 'Nebuliser',
        startedAt: null,
        doneAt: null,
        outcome: {
            procedureLabel: 'Nebuliser',
            outcomeLabel: 'Not successful',
            reasonLabel: 'Patient declined',
        },
    };
    const normal = await renderToString(Vue.createSSRApp(Badge, { progress }));
    const hidden = await renderToString(
        Vue.createSSRApp(Badge, { progress, showOutcomes: false }),
    );
    assert.match(normal, /data-testid="treatment-record-outcome"/);
    assert.match(
        normal,
        /aria-label="[^"]*Not successful[^"]*Patient declined/,
    );
    assert.doesNotMatch(
        hidden,
        /Not successful|Patient declined|data-testid="treatment-record-outcome"/,
    );
    assert.match(hidden, /aria-label="[^"]*Nebuliser/);
    assert.match(hidden, /data-treatment-state="done"/);

    for (const html of [normal, hidden]) {
        assert.match(html, /text-warning-strong/);
    }
});

test('own Done shows consultation checkout time without changing default or branch columns', async () => {
    const own = await render(
        { queueLayout: 'own', queueTask: 'completed' },
        { completionLabel: '26 Nov 2026, 12:01 AM' },
    );
    assert.deepEqual(headers(own), [
        'No.',
        'Patient',
        'Zone',
        'Seen for',
        'Completed (consultation)',
        'Actions',
    ]);
    assert.match(own, /data-label="Completed \(consultation\)"/);
    assert.match(own, /26 Nov 2026, 12:01 AM/);
    const absent = await render({ queueLayout: 'own', queueTask: 'completed' });
    const timeCell = absent.slice(
        absent.indexOf('data-label="Completed (consultation)"'),
        absent.indexOf('data-label="Actions"'),
    );
    assert.match(timeCell, /Not available/);

    for (const props of [
        { queueLayout: 'branch', queueTask: 'completed' },
        {},
    ]) {
        const html = await render(props, {
            completionLabel: 'CHECKOUT_ONLY_MARKER',
        });
        assert.doesNotMatch(
            html,
            /CHECKOUT_ONLY_MARKER|Completed \(consultation\)/,
        );
        assert.match(html, /data-label="Status"/);
    }
});

test('actual Queue mapper formats checkout in current branch timezone and never substitutes another timestamp', () => {
    const source = readFileSync('resources/js/pages/Queue/Index.vue', 'utf8');
    const mapper = source.slice(
        source.indexOf('const toBoardRow ='),
        source.indexOf('const boardRows ='),
    );
    const presentation = load('resources/js/lib/presentation.ts');
    const live = {
        value: {
            branch: { timezone: 'Asia/Kuala_Lumpur' },
            operationalDate: '2026-11-26',
        },
    };
    const toBoardRow = runInNewContext(
        ts.transpileModule(mapper + '\ntoBoardRow;', {
            compilerOptions: { target: ts.ScriptTarget.ES2022 },
        }).outputText,
        {
            ...presentation,
            live,
            props: {},
            treatmentMinutes: () => null,
            effectiveNowMs: () => 0,
            compactTime: (value) => value,
            queuePresentationLabel: () => 'Done',
        },
    );
    const input = {
        operationalDate: '2026-11-26',
        queueNumber: 1,
        status: 'removed',
        can: {},
        queuedTime: '09:00',
        removedAt: '2026-11-25T16:01:00+00:00',
    };
    const local = toBoardRow(input).completionLabel;
    assert.equal(
        local,
        presentation.formatDateTime(input.removedAt, 'Asia/Kuala_Lumpur'),
    );
    assert.match(local, /26 Nov 2026/);
    live.value.branch.timezone = 'UTC';
    assert.notEqual(toBoardRow(input).completionLabel, local);
    assert.equal(
        toBoardRow({ ...input, removedAt: null }).completionLabel,
        null,
    );
    const withoutTimestamp = { ...input };
    delete withoutTimestamp.removedAt;
    assert.equal(toBoardRow(withoutTimestamp).completionLabel, null);
});

test('own Medicine checks show only supplied review-line count and current sender with unavailable and escaping semantics', async () => {
    const props = { queueLayout: 'own', queueTask: 'checks' };
    const html = await render(props, {
        medicineCheck: { itemLineCount: 3, sentBy: 'Synthetic Sender' },
    });
    assert.deepEqual(headers(html), [
        'No.',
        'Patient',
        'Zone',
        'Item lines',
        'Sent by',
        'Actions',
    ]);
    assert.match(html, /data-label="Item lines"[^>]*>[\s\S]*?>3<\/span>/);
    assert.match(html, /data-label="Sent by"[^>]*>[\s\S]*?Synthetic Sender/);
    const missing = await render(props, { medicineCheck: null });
    assert.equal((missing.match(/Not available/g) ?? []).length, 2);
    const zero = await render(props, {
        medicineCheck: { itemLineCount: 0, sentBy: '<img src=x>' },
    });
    assert.match(zero, />0<\/span>/);
    assert.match(zero, /&lt;img src=x&gt;/);
    assert.doesNotMatch(zero, /<img src=x>/);

    for (const normal of [{ queueLayout: 'branch' }, {}]) {
        const unchanged = await render(normal, {
            medicineCheck: {
                itemLineCount: 99,
                sentBy: 'PRIVATE_SENDER_MARKER',
            },
        });
        assert.doesNotMatch(
            unchanged,
            /PRIVATE_SENDER_MARKER|data-label="Item lines"|data-label="Sent by"/,
        );
    }

    const page = readFileSync('resources/js/pages/Queue/Index.vue', 'utf8');
    assert.match(
        page.replace(/\s+/g, ' '),
        /including intentionally not-dispensed lines/,
    );
    assert.match(page.replace(/\s+/g, ' '), /not a supplied medicine quantity/);
});

test('branch timeline status relocation preserves exactly one open dialog and readiness event across default transitions', async () => {
    mountedControls.length = 0;
    const node = () => ({ children: [], parent: null });
    const renderer = Vue.createRenderer({
        createElement: node,
        createText: node,
        createComment: node,
        setText() {},
        setElementText() {},
        patchProp() {},
        insert(child, parent, anchor) {
            if (child.parent) {
                const i = child.parent.children.indexOf(child);

                if (i >= 0) {
                    child.parent.children.splice(i, 1);
                }
            }

            child.parent = parent;
            const i = anchor ? parent.children.indexOf(anchor) : -1;

            if (i < 0) {
                parent.children.push(child);
            } else {
                parent.children.splice(i, 0, child);
            }
        },
        remove(child) {
            if (child.parent) {
                const i = child.parent.children.indexOf(child);

                if (i >= 0) {
                    child.parent.children.splice(i, 1);
                }
            }

            child.parent = null;
        },
        parentNode: (child) => child.parent,
        nextSibling: (child) =>
            child.parent?.children[child.parent.children.indexOf(child) + 1] ??
            null,
    });
    const props = Vue.reactive({ queueLayout: 'branch', queueTask: 'waiting' });
    let readiness = 0;
    const app = renderer.createApp({
        setup: () => () =>
            Vue.h(Board, {
                ...props,
                branchId: 3,
                rows: [
                    {
                        ...row,
                        treatmentProgress: {
                            state: 'started',
                            canManage: true,
                        },
                    },
                ],
                onReadinessChanged: () => readiness++,
            }),
    });
    app.mount(node());
    const active = (kind) =>
        mountedControls.filter((entry) => entry.active && entry.kind === kind);
    assert.equal(active('treatment').length, 1);
    active('treatment')[0].emit('update:open', true);
    await Vue.nextTick();

    for (const task of [undefined, 'seeing', 'held', 'waiting']) {
        props.queueTask = task;
        await Vue.nextTick();
        assert.equal(active('treatment').length, 1);
        assert.equal(active('readiness').length, 1);
        assert.equal(active('treatment')[0].props.open, true);
        active('readiness')[0].emit('changed');
        await Vue.nextTick();
    }

    assert.equal(readiness, 4);
    active('treatment')[0].emit('update:open', false);
    await Vue.nextTick();
    assert.equal(active('treatment')[0].props.open, false);
    app.unmount();
    assert.equal(active('treatment').length, 0);
    assert.equal(active('readiness').length, 0);
});

test('branch Waiting and Serving put existing safety status before truthful timing without changing default tasks', async () => {
    for (const [task, timing] of [
        ['waiting', 'Waiting'],
        ['seeing', 'Since'],
    ]) {
        const html = await render(
            { queueLayout: 'branch', queueTask: task },
            {
                triage: { triaged: true, zone: 'red' },
                priority: 'urgent',
                returnedFromDispensary: true,
            },
        );
        assert.deepEqual(headers(html), [
            'No.',
            'Patient',
            'Doctor',
            'Status',
            timing,
            'Actions',
        ]);
        assert.ok(
            html.indexOf('data-label="Status"') <
                html.indexOf('data-label="' + timing + '"'),
        );
        assert.equal((html.match(/data-label="Status"/g) ?? []).length, 1);
        assert.equal(
            (html.match(/data-testid="triage-zone-chip"/g) ?? []).length,
            1,
        );
        assert.match(html, /Red zone/);
        assert.match(html, /Returned/);
    }

    for (const task of [undefined, 'completed']) {
        const html = await render({ queueLayout: 'branch', queueTask: task });
        assert.deepEqual(headers(html), [
            'No.',
            'Patient',
            'Doctor',
            'Waiting / since',
            'Status',
            'Actions',
        ]);
        assert.ok(
            html.indexOf('data-label="Duration"') <
                html.indexOf('data-label="Status"'),
        );
    }
});

test('branch Held preserves triage and safety controls under Patient with one actual step badge and truthful evidence', async () => {
    const props = { queueLayout: 'branch', queueTask: 'held' };
    const progress = {
        state: 'done',
        label: 'Nebuliser',
        startedAt: null,
        doneAt: null,
        canManage: true,
        outcome: {
            procedureLabel: 'Nebuliser',
            outcomeLabel: 'Not successful',
            reasonLabel: 'Patient declined',
        },
    };
    const html = await render(props, {
        statusLabel: 'On Hold',
        statusTone: 'held',
        durationLabel: 'Held 17 min',
        triage: { triaged: true, zone: 'yellow' },
        treatmentProgress: progress,
        doctorWarning: { code: 'off_roster', label: 'Off duty now' },
    });
    assert.deepEqual(headers(html), [
        'No.',
        'Patient',
        'Doctor',
        'Treatment step',
        'Held',
        'Actions',
    ]);
    const patient = html.slice(
        html.indexOf('data-label="Patient"'),
        html.indexOf('data-label="Doctor"'),
    );
    const step = html.slice(
        html.indexOf('data-label="Treatment step"'),
        html.indexOf('data-label="Held"'),
    );
    assert.match(patient, /On Hold/);
    assert.match(patient, /Yellow zone/);
    assert.match(patient, /Existing readiness actions/);
    assert.match(patient, /Existing treatment controls/);
    assert.doesNotMatch(patient, /data-testid="treatment-progress-badge"/);
    assert.match(step, /data-testid="treatment-progress-badge"/);
    assert.match(step, /Not successful/);
    assert.match(html, /Held 17 min/);
    assert.match(html, /Off duty now/);
    assert.equal(
        (html.match(/data-testid="treatment-progress-badge"/g) ?? []).length,
        1,
    );
    assert.equal(
        (html.match(/data-testid="triage-zone-chip"/g) ?? []).length,
        1,
    );
    assert.equal((html.match(/data-dialog-open=/g) ?? []).length, 1);
    assert.doesNotMatch(html, /data-label="Status"|Resume/);
    const unavailable = await render(props, {
        treatmentProgress: { state: null, label: null, canManage: true },
        triage: null,
    });
    assert.match(unavailable, /Not available/);
    assert.doesNotMatch(unavailable, /No triage yet|Not successful|>Done</);
    const withheld = await render(props, {
        treatmentProgress: { ...progress, outcome: undefined },
    });
    assert.doesNotMatch(withheld, /Not successful|Patient declined/);
});
