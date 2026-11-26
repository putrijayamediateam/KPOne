import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import { nextTick, reactive, watch } from 'vue';

const component = readFileSync('resources/js/pages/Clinical/Show.vue', 'utf8');
const script = component.match(
    /<script setup lang="ts">([\s\S]*?)<\/script>/,
)[1];
const ast = ts.createSourceFile(
    'Clinical.ts',
    script,
    ts.ScriptTarget.Latest,
    true,
);
const statements = ast.statements.filter(
    (node) =>
        (ts.isVariableStatement(node) &&
            node.declarationList.declarations.some((decl) =>
                [
                    'emergencyHoldContinuation',
                    'changeHoldState',
                    'updateUnsavedWorkOpen',
                ].includes(decl.name.getText(ast)),
            )) ||
        (ts.isExpressionStatement(node) &&
            ts.isCallExpression(node.expression) &&
            (node.expression.expression.getText(ast) ===
                'useEmergencyHoldBridge' ||
                (node.expression.expression.getText(ast) === 'watch' &&
                    node
                        .getText(ast)
                        .includes('emergencyHoldContinuation = null')))),
);
assert.equal(statements.length, 5);
const extracted = statements.map((node) => node.getText(ast)).join('\n');
const compiled = ts.transpileModule(
    `${extracted}\nglobalThis.change = changeHoldState; globalThis.changeOpen = updateUnsavedWorkOpen;`,
    { compilerOptions: { target: ts.ScriptTarget.ES2022 } },
).outputText;
function harness() {
    const posts = [];
    let bridge;
    const state = {
        props: {
            clinical: {
                visit: { visitNumber: 'V-ORIGINAL', lockVersion: 1 },
                branch: { id: 1 },
                queue: { lockVersion: 2 },
                encounter: { lockVersion: 3 },
                hold: {
                    canHold: true,
                    isHeld: false,
                    holdIdempotencyKey: 'synthetic-hold-key',
                    resumeIdempotencyKey: 'synthetic-resume-key',
                },
            },
        },
        nextTick,
        watch,
        unsavedWorkOpen: { value: false },
        unsavedWorkProcessing: { value: false },
        holdProcessing: { value: false },
        holdError: { value: '' },
        callNextProcessing: { value: false },
        router: {
            post: (url, payload, options) =>
                posts.push({ url, payload, options }),
        },
        requestHold: () => state.change('hold'),
        useEmergencyHoldBridge: (value) => {
            bridge = value;
        },
    };
    state.props = reactive(state.props);
    vm.runInNewContext(compiled, state);

    return { state, bridge, posts };
}

test('emergency Hold respects the same Call next processing guard as the existing buttons', async () => {
    const h = harness();
    h.state.callNextProcessing.value = true;
    let failure = '';
    assert.equal(h.bridge.canHold(), false);
    h.bridge.request(
        () => assert.fail('must not take'),
        (message) => {
            failure = message;
        },
    );
    assert.equal(h.posts.length, 0);
    assert.match(failure, /cannot be held/);
});

test('only confirmed held props trigger the separate emergency Take continuation', async () => {
    const h = harness();
    let takes = 0;
    h.bridge.request(
        () => takes++,
        () => assert.fail('unexpected failure'),
    );
    assert.equal(h.posts[0].payload.idempotency_key, 'synthetic-hold-key');
    h.state.props.clinical.hold.isHeld = true;
    await h.posts[0].options.onSuccess();
    await h.posts[0].options.onSuccess();
    assert.equal(takes, 1);
});

for (const callback of ['onCancel', 'onNetworkError', 'onHttpException']) {
    test(`uncertain Hold ${callback} clears continuation and never takes the emergency`, async () => {
        const h = harness();
        let failure = '';
        h.bridge.request(
            () => assert.fail('must not take'),
            (message) => {
                failure = message;
            },
        );
        h.posts[0].options[callback]();
        h.posts[0].options.onFinish();
        assert.match(failure, /not confirmed/);
        assert.equal(h.state.holdProcessing.value, false);
        h.state.props.clinical.hold.isHeld = true;
        await h.posts[0].options.onSuccess();
    });
}

test('navigation cancellation invalidates the old Hold continuation', async () => {
    const h = harness();
    h.bridge.request(
        () => assert.fail('must not take'),
        () => assert.fail('cancelled bridge must stay silent'),
    );
    h.bridge.cancel();
    h.state.props.clinical.hold.isHeld = true;
    await h.posts[0].options.onSuccess();
});

test('Hold continuation waits for Vue to apply the successful Inertia props', async () => {
    const h = harness();
    let takes = 0;
    h.bridge.request(
        () => takes++,
        () => assert.fail('unexpected cancellation'),
    );
    const completed = h.posts[0].options.onSuccess();
    assert.equal(takes, 0);
    h.state.props.clinical.hold.isHeld = true;
    await completed;
    assert.equal(takes, 1);
});

test('an explicit unsaved-dialog cancellation prevents the queued emergency continuation', async () => {
    const h = harness();
    let cancelled = '';
    h.state.requestHold = () => {
        h.state.unsavedWorkOpen.value = true;
    };
    h.bridge.request(
        () => assert.fail('must not take'),
        (message) => {
            cancelled = message;
        },
    );
    h.state.changeOpen(false);
    assert.match(cancelled, /Hold was cancelled/);
    h.state.change('hold');
    h.state.props.clinical.hold.isHeld = true;
    await h.posts[0].options.onSuccess();
});

test('saving fresh props for the same visit preserves the pending Hold then Take', async () => {
    const h = harness();
    let takes = 0;
    h.state.requestHold = () => {
        h.state.unsavedWorkOpen.value = true;
    };
    h.bridge.request(
        () => takes++,
        () => assert.fail('unexpected cancellation'),
    );
    h.state.props.clinical = {
        ...h.state.props.clinical,
        encounter: { lockVersion: 4 },
    };
    await nextTick();
    h.state.unsavedWorkOpen.value = false;
    h.state.change('hold');
    h.state.props.clinical.hold.isHeld = true;
    await h.posts[0].options.onSuccess();
    assert.equal(takes, 1);
});

test('a real reactive visit identity change invalidates the pending continuation', async () => {
    const h = harness();
    h.state.requestHold = () => {
        h.state.unsavedWorkOpen.value = true;
    };
    h.bridge.request(
        () => assert.fail('must not take'),
        () => assert.fail('navigation is silent'),
    );
    h.state.props.clinical = {
        ...h.state.props.clinical,
        visit: { visitNumber: 'V-OTHER', lockVersion: 1 },
    };
    await nextTick();
    h.state.change('hold');
    h.state.props.clinical.hold.isHeld = true;
    await h.posts[0].options.onSuccess();
});
