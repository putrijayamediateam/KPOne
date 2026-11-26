import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as vue from 'vue';

const { descriptor } = parse(
    readFileSync(
        'resources/js/components/queue/RedZoneAlertBanner.vue',
        'utf8',
    ),
);
const compiled = compileScript(descriptor, {
    id: 'tri2-runtime',
    inlineTemplate: true,
});
const source = ts.transpileModule(compiled.content, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const module = { exports: {} };
const Button = {
    setup:
        (_props, { slots }) =>
        () =>
            vue.h('button', slots.default?.()),
};
vm.runInNewContext(source, {
    exports: module.exports,
    require: (name) =>
        name === 'vue'
            ? vue
            : name === '@lucide/vue'
              ? { Siren: { render: () => vue.h('svg') } }
              : name.endsWith('/button')
                ? { Button }
                : { formatDateTime: () => '26 Nov 2026, 9:00 am' },
});
const defaults = {
    alerts: [],
    paused: false,
    busy: false,
    uncertain: false,
    error: '',
    canRespond: true,
    soundReady: false,
    muted: false,
    canHold: false,
    held: false,
    presence: null,
};
const render = (props) =>
    renderToString(
        vue.createSSRApp(module.exports.default, { ...defaults, ...props }),
    );

test('unknown Take outcome keeps retry and reload visible even after the last alert disappears', async () => {
    const html = await render({
        uncertain: true,
        error: 'The result is not confirmed.',
        held: true,
    });
    assert.match(html, /data-testid="red-zone-alerts"/);
    assert.match(html, /Retry unchanged/);
    assert.match(html, /Reload and check/);
    assert.match(html, /Your current patient remains on hold/);
});

test('Hold failure remains visible without any emergency rows', async () => {
    const html = await render({
        error: 'The Hold result is not confirmed. Reload this consultation.',
    });
    assert.match(html, /The Hold result is not confirmed/);
    assert.match(html, /role="status"/);
});

test('empty idle state hides the banner while an in-flight action keeps it mounted', async () => {
    assert.doesNotMatch(await render({}), /data-testid="red-zone-alerts"/);
    assert.match(await render({ busy: true }), /data-testid="red-zone-alerts"/);
});

test('taken or absent alerts do not announce that an untaken patient is waiting', async () => {
    const taken = {
        visitNumber: 'V-TEST',
        queueNumber: 'Q001',
        waitingMinutes: 1,
        takenBy: 'Synthetic Doctor',
        mine: false,
        canTake: false,
    };
    assert.doesNotMatch(
        await render({ alerts: [taken] }),
        /waiting for a doctor/,
    );
    assert.doesNotMatch(
        await render({ uncertain: true }),
        /waiting for a doctor/,
    );
});
