import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const pagination = { currentPage: 1, lastPage: 2, perPage: 25, total: 26 };
const fixture = () => ({
    room: { branch: { timezone: 'Asia/Kuala_Lumpur' } },
    treatment: {
        visitNumber: 'SYNTH-1',
        branchId: 1,
        canRecord: true,
        hasStartedStep: true,
        visitLockVersion: 2,
        queueLockVersion: 3,
        stepsPagination: { ...pagination },
        steps: [
            {
                id: 27,
                state: 'done',
                code: 'urine_sample',
                canRecordOutcome: true,
                lockVersion: 2,
            },
        ],
    },
    records: {
        current: [],
        history: [],
        currentPagination: { ...pagination },
        historyPagination: { ...pagination },
    },
});
const harness = () => {
    const requests = [];
    const props = Vue.reactive(fixture());
    const source = compileScript(
        parse(readFileSync('resources/js/pages/TreatmentRoom/Show.vue', 'utf8'))
            .descriptor,
        { id: 'pagination-test' },
    ).content;
    const compiled = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const module = { exports: {} };
    Function(
        'require',
        'exports',
        compiled,
    )((name) => {
        if (name === 'vue') {
            return Vue;
        }

        if (name === '@inertiajs/vue3') {
            return {
                router: {
                    get: (...args) => requests.push({ method: 'GET', args }),
                    post: (...args) => requests.push({ method: 'POST', args }),
                },
            };
        }

        return {};
    }, module.exports);
    const scope = Vue.effectScope();
    const state = scope.run(() =>
        module.exports.default.setup(props, { expose() {} }),
    );

    return { props, state, requests, close: () => scope.stop() };
};

test('off-page active step uses server full-set flag rather than visible step states', () => {
    const h = harness();
    assert.equal(h.state.hasStartedStep.value, true);
    assert.ok(
        h.props.treatment.steps.every((step) => step.state !== 'started'),
    );
    h.close();
});

test('page change with draft requires confirmation, cancel preserves choices and sends nothing', () => {
    const h = harness();
    h.state.outcome.value = 'not_successful';
    h.state.reason.value = 'patient_declined';
    h.state.requestPage('steps_page', 2);
    assert.deepEqual(h.state.pendingPage.value, { key: 'steps_page', page: 2 });
    assert.equal(h.requests.length, 0);
    h.state.pendingPage.value = null;
    assert.equal(h.state.outcome.value, 'not_successful');
    assert.equal(h.state.reason.value, 'patient_declined');
    assert.equal(h.requests.length, 0);
    h.close();
});

test('confirmed page transition clears draft and preserves independent page cursors without posting', async () => {
    const h = harness();
    h.state.outcome.value = 'not_successful';
    h.state.reason.value = 'patient_declined';
    h.state.requestPage('steps_page', 2);
    h.state.discardForPage();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].method, 'GET');
    assert.match(
        h.requests[0].args[0],
        /steps_page=2&current_page=1&history_page=1/,
    );
    assert.equal(h.state.reason.value, '');
    assert.equal(h.state.outcome.value, 'done');
    h.props.treatment.steps = [
        { id: 1, state: 'done', canRecordOutcome: true },
    ];
    await Vue.nextTick();
    assert.equal(h.state.stepId.value, '1');
    assert.equal(h.state.reason.value, '');
    h.close();
});

test('uncertain mutation prevents paging and exact retry retains payload and idempotency key', () => {
    const h = harness();
    h.state.record();
    const first = h.requests[0];
    first.args[2].onNetworkError();
    first.args[2].onFinish();
    h.state.requestPage('history_page', 2);
    assert.equal(h.requests.length, 1);
    assert.equal(h.state.pendingPage.value, null);
    h.state.retry();
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[1].method, 'POST');
    assert.deepEqual(h.requests[1].args[1], first.args[1]);
    assert.equal(h.requests[1].args[0], first.args[0]);
    h.close();
});

test('changing only the selected step requires discard confirmation and a context change clears it', async () => {
    const h = harness();
    h.props.treatment.steps.push({
        id: 26,
        state: 'done',
        code: 'swab_sample',
        canRecordOutcome: true,
        lockVersion: 1,
    });
    await Vue.nextTick();
    h.state.stepId.value = '26';
    h.state.requestPage('steps_page', 2);
    assert.deepEqual(h.state.pendingPage.value, { key: 'steps_page', page: 2 });
    assert.equal(h.requests.length, 0);
    h.props.treatment.visitNumber = 'SYNTH-2';
    await Vue.nextTick();
    assert.equal(h.state.pendingPage.value, null);
    assert.equal(h.requests.length, 0);
    h.close();
});

test('paging moves focus to persistent labelled navigation before its button can disable, never on initial setup', () => {
    const source = compileScript(
        parse(
            readFileSync(
                'resources/js/components/treatment-room/TreatmentRoomPagination.vue',
                'utf8',
            ),
        ).descriptor,
        { id: 'focus-test' },
    ).content;
    const compiled = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const module = { exports: {} };
    Function(
        'require',
        'exports',
        compiled,
    )((name) => (name === 'vue' ? Vue : {}), module.exports);
    const events = [];
    const state = module.exports.default.setup(
        { pagination, label: 'Treatment steps' },
        { expose() {}, emit: (...args) => events.push(args) },
    );
    state.navigation.value = {
        focus: (options) => events.push(['focus', options]),
    };
    assert.deepEqual(events, []);
    state.changePage(2);
    assert.deepEqual(events, [
        ['focus', { preventScroll: true }],
        ['page', 2],
    ]);
});

test('record-only paging reselects unchanged step props without manufacturing an unsaved step choice', () => {
    const h = harness();
    h.state.requestPage('current_page', 2);
    h.requests[0].args[2].onFinish();
    assert.equal(h.state.stepId.value, '27');
    h.state.requestPage('current_page', 1);
    assert.equal(h.state.pendingPage.value, null);
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[1].method, 'GET');
    h.close();
});
