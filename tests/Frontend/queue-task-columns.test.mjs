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
    assert.match(branch, /Waiting \/ since/);
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
    app.unmount();
    assert.equal(active('treatment').length, 0);
    assert.equal(active('readiness').length, 0);
});
