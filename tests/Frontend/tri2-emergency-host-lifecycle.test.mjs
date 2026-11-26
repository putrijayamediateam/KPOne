import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import * as vue from 'vue';

const script = readFileSync(
    'resources/js/components/queue/EmergencyAlertHost.vue',
    'utf8',
).match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
const ast = ts.createSourceFile(
    'Host.ts',
    script,
    ts.ScriptTarget.Latest,
    true,
);
const body = ast.statements
    .filter((node) => !ts.isImportDeclaration(node))
    .map((node) => node.getText(ast))
    .join('\n');
const source = ts.transpileModule(
    `export function setup() { ${body}\nreturn { holdTarget, holding, confirmHold }; }`,
    { compilerOptions: { module: ts.ModuleKind.CommonJS } },
).outputText;

test('host teardown cancels its bridge and ignores a retained successful Hold callback', () => {
    let callback;
    let cancellations = 0;
    const commands = [];
    const bridge = vue.ref({
        branchId: 3,
        visitNumber: 'V-CURRENT',
        request: (success) => {
            callback = success;
        },
        cancel: () => {
            cancellations++;
        },
    });
    const module = { exports: {} };
    vm.runInNewContext(source, {
        exports: module.exports,
        computed: vue.computed,
        ref: vue.ref,
        watch: vue.watch,
        onBeforeUnmount: vue.onBeforeUnmount,
        usePage: () => ({
            props: {
                auth: {
                    user: { id: 7 },
                    permissions: ['queue.emergency.respond.branch'],
                },
                branchContext: { active: { id: 3 } },
            },
        }),
        emergencyHoldBridge: bridge,
        useEmergencyAlerts: () => ({
            command: (...args) => commands.push(args),
        }),
    });
    const renderer = vue.createRenderer({
        createElement: () => ({}),
        createText: () => ({}),
        createComment: () => ({}),
        insert() {},
        remove() {},
        setText() {},
        setElementText() {},
        patchProp() {},
        parentNode: () => null,
        nextSibling: () => null,
    });
    let state;
    const app = renderer.createApp({
        setup() {
            state = module.exports.setup();

            return () => vue.h('div');
        },
    });
    app.mount({});
    state.holdTarget.value = { episodeId: 12, visitNumber: 'V-RED' };
    state.confirmHold();
    assert.equal(state.holding.value, true);
    assert.equal(typeof callback, 'function');
    app.unmount();
    assert.equal(cancellations, 1);
    callback();
    assert.deepEqual(commands, []);
    assert.equal(state.holding.value, false);
    assert.equal(state.holdTarget.value, null);
});
