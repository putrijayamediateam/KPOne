import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
import { nextTick, ref, watch } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync('resources/js/pages/Queue/Index.vue', 'utf8');
const transpile = (code) =>
    ts.transpileModule(code, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const setup = () => {
    const pending = [];
    const exports = {};
    runInNewContext(
        transpile(
            readFileSync(
                'resources/js/composables/useQueueWorkload.ts',
                'utf8',
            ),
        ),
        {
            exports,
            AbortController,
            URLSearchParams,
            require: (name) =>
                name === '@/lib/json-client'
                    ? {
                          requestJson: (url, init) =>
                              new Promise((resolve, reject) =>
                                  pending.push({ url, init, resolve, reject }),
                              ),
                      }
                    : require(name),
        },
    );
    const context = {
        workload: exports.useQueueWorkload(),
        live: ref({ branch: { id: 1 }, scope: 'own' }),
        workloadSection: ref('held'),
        workloadPage: ref(3),
        activeTab: ref('held'),
        workspaceSection: ref('queue'),
        request: new AbortController(),
        requestGeneration: 1,
        pollingLifecycle: { accepts: () => true },
        appliedFilters: {},
    };
    const launch = () =>
        runInNewContext(
            source.match(/void workload\s*\.poll\([\s\S]*?\n\s*\}\);/)[0],
            context,
        );

    return { context, pending, launch };
};
const response = (branchId, section, currentPage) => ({
    branchId,
    section,
    currentPage,
    data: [],
    total: 1,
    lastPage: 1,
    perPage: 25,
});
const settle = async () => {
    await Promise.resolve();
    await Promise.resolve();
    await nextTick();
};

test('late clamped completion cannot change the selected tab page after replacement', async () => {
    const { context, pending, launch } = setup();
    launch();
    context.workload.reset();
    context.workloadSection.value = 'treatment';
    context.workloadPage.value = 2;
    launch();
    pending[1].resolve(response(1, 'treatment', 2));
    await settle();
    pending[0].resolve(response(1, 'held', 1));
    await settle();
    assert.equal(context.workloadPage.value, 2);
    assert.equal(context.workload.page.value.section, 'treatment');
});

test('actual branch and scope watcher clears pending optional results before late completion', async () => {
    for (const change of ['branch', 'scope']) {
        const { context, pending, launch } = setup();
        const watcher = source.match(
            /watch\(\s*\[\(\) => live\.value\.branch\.id, \(\) => live\.value\.scope\],[\s\S]*?\n\}\);/,
        )[0];
        const stop = runInNewContext(watcher.replace(/;$/, ''), {
            ...context,
            watch,
        });
        launch();

        if (change === 'branch') {
            context.live.value.branch.id = 2;
        } else {
            context.live.value.scope = 'branch';
        }

        await nextTick();
        assert.equal(pending[0].init.signal.aborted, true);
        pending[0].resolve(response(1, 'held', 3));
        await settle();
        assert.equal(context.workload.page.value, null);
        assert.equal(context.workloadPage.value, 1);
        assert.equal(context.activeTab.value, 'waiting');
        stop();
    }
});
