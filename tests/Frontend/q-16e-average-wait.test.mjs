import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp } from 'vue';

const require = createRequire(import.meta.url);
const compile = (source, imports = require) => {
    const module = { exports: {} };
    const js = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    runInNewContext(js, {
        exports: module.exports,
        require: imports,
        AbortController,
    });

    return module.exports;
};
const harness = () => {
    const pending = [];
    const module = compile(
        readFileSync('resources/js/composables/useQueueAverageWait.ts', 'utf8'),
        (name) =>
            name === '@/lib/json-client'
                ? {
                      requestJson: (url, init) =>
                          new Promise((resolve, reject) =>
                              pending.push({ url, init, resolve, reject }),
                          ),
                  }
                : require(name),
    );

    return { model: module.useQueueAverageWait(), pending };
};

test('average wait discards stale branch results and passes expected branch plus abort signal', async () => {
    const { model, pending } = harness();
    const signal = new AbortController().signal;
    const old = model.poll(1, signal);
    const current = model.poll(2, signal);
    assert.equal(pending[1].url, '/queue/average-wait?expected_branch_id=2');
    assert.equal(pending[0].init.signal.aborted, true);
    assert.equal(pending[1].init.signal.aborted, false);
    pending[1].resolve({
        averageWaitMinutes: 12,
        samples: 3,
        operationalDate: '2026-11-26',
    });
    await current;
    pending[0].resolve({
        averageWaitMinutes: 99,
        samples: 20,
        operationalDate: '2026-11-25',
    });
    await old;
    assert.equal(model.stats.value.averageWaitMinutes, 12);
});

test('failed refresh removes old figures; aborted and reset requests cannot restore them', async () => {
    const { model, pending } = harness();
    const controller = new AbortController();
    const first = model.poll(1, controller.signal);
    pending[0].resolve({
        averageWaitMinutes: 0,
        samples: 4,
        operationalDate: '2026-11-26',
    });
    await first;
    const failed = model.poll(1, controller.signal);
    pending[1].reject(new Error('network'));
    await failed;
    assert.equal(model.stats.value, null);
    assert.equal(model.unavailable.value, true);
    const aborted = model.poll(1, controller.signal);
    controller.abort();
    pending[2].resolve({ averageWaitMinutes: 90 });
    await aborted;
    assert.equal(model.stats.value, null);
    const reset = model.poll(2, new AbortController().signal);
    model.reset();
    pending[3].resolve({ averageWaitMinutes: 90 });
    await reset;
    assert.equal(model.stats.value, null);
});

const componentSource = readFileSync(
    'resources/js/components/queue/QueueAverageWaitSummary.vue',
    'utf8',
);
const component = compile(
    compileScript(parse(componentSource).descriptor, {
        id: 'average-wait',
        inlineTemplate: true,
    }).content,
).default;
const render = (stats, scope = 'branch', unavailable = false) =>
    renderToString(createSSRApp(component, { stats, scope, unavailable }));
test('summary distinguishes no sample figure, zero minutes, own scope and unavailable state', async () => {
    const low = await render({
        averageWaitMinutes: null,
        samples: 2,
        operationalDate: '2026-11-26',
    });
    assert.match(low, /Not enough calls yet/);
    assert.doesNotMatch(low, /0 min/);
    assert.match(low, /Whole branch/);
    const zero = await render(
        { averageWaitMinutes: 0, samples: 3, operationalDate: '2026-11-26' },
        'own',
    );
    assert.match(zero, /0 min/);
    assert.match(zero, /Your patients/);
    assert.match(await render(null, 'own', true), /Temporarily unavailable/);
});

test('a hung summary request is replaced, and rejected lifecycle generations cannot publish', async () => {
    const { model, pending } = harness();
    void model.poll(1, new AbortController().signal);
    const second = model.poll(1, new AbortController().signal, () => false);
    assert.equal(pending[0].init.signal.aborted, true);
    pending[1].resolve({ averageWaitMinutes: 77 });
    await second;
    assert.equal(model.stats.value, null);
    model.reset();
    const board = readFileSync('resources/js/pages/Queue/Index.vue', 'utf8');
    assert.match(board, /void averageWait.poll\(/);
    assert.doesNotMatch(board, /await averageWait.poll/);
});
