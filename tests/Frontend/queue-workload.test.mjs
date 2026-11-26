import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const harness = () => {
    const pending = [];
    const exports = {};
    const js = ts.transpileModule(
        readFileSync('resources/js/composables/useQueueWorkload.ts', 'utf8'),
        {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        },
    ).outputText;
    runInNewContext(js, {
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
    });

    return { model: exports.useQueueWorkload(), pending };
};
const response = (branchId = 1, section = 'held', currentPage = 1) => ({
    branchId,
    section,
    currentPage,
    data: [],
    total: 0,
    lastPage: 1,
    perPage: 25,
});

test('switching branch, section or page aborts the old list and discards late results', async () => {
    const { model, pending } = harness();
    const signal = new AbortController().signal;
    const old = model.poll(1, 'held', 1, signal);
    const fresh = model.poll(2, 'treatment', 2, signal);
    assert.equal(pending[0].init.signal.aborted, true);
    assert.equal(
        pending[1].url,
        '/queue/workload?expected_branch_id=2&section=treatment&page=2&query=&doctor_id=&priority=',
    );
    pending[1].resolve(response(2, 'treatment', 1));
    await fresh;
    pending[0].resolve(response());
    await old;
    assert.equal(model.page.value.branchId, 2);
    assert.equal(
        model.page.value.currentPage,
        1,
        'Server-clamped page is authoritative.',
    );
    const changedPage = model.poll(2, 'treatment', 3, signal);
    assert.equal(
        model.page.value,
        null,
        'Never show another page while it is loading.',
    );
    model.reset();
    pending[2].resolve(response(2, 'treatment', 3));
    await changedPage;
    assert.equal(model.page.value, null);
    assert.equal(model.loading.value, false);
});

test('a failed refresh removes stale actionable rows and rejects mismatched response scope', async () => {
    const { model, pending } = harness();
    const signal = new AbortController().signal;
    const first = model.poll(1, 'held', 1, signal);
    pending[0].resolve(response());
    await first;
    const failed = model.poll(1, 'held', 1, signal);
    pending[1].reject(new Error('network'));
    await failed;
    assert.equal(model.page.value, null);
    assert.equal(model.unavailable.value, true);
    const wrong = model.poll(1, 'held', 1, signal);
    pending[2].resolve(response(2));
    await wrong;
    assert.equal(model.page.value, null);
    assert.equal(model.unavailable.value, true);
});

test('parent abort and generation guard prevent publication without waiting for a hung optional request', async () => {
    const { model, pending } = harness();
    const parent = new AbortController();
    let accepted = true;
    const first = model.poll(1, 'held', 1, parent.signal, () => accepted);
    assert.equal(model.loading.value, true);
    accepted = false;
    pending[0].resolve(response());
    await first;
    assert.equal(model.page.value, null);
    const next = model.poll(1, 'checks', 1, parent.signal);
    parent.abort();
    assert.equal(pending[1].init.signal.aborted, true);
    pending[1].resolve(response(1, 'checks'));
    await next;
    assert.equal(model.page.value, null);
    assert.equal(model.unavailable.value, false);
});

test('actual Reset handler keeps valid Waiting transport for every workload tab', () => {
    const source = readFileSync('resources/js/pages/Queue/Index.vue', 'utf8');
    const declaration = source.match(
        /const clearFilters = \(\) => \{[\s\S]*?\n\};/,
    )[0];
    const pollingExports = {};
    runInNewContext(
        ts.transpileModule(
            readFileSync('resources/js/lib/queue-polling.ts', 'utf8'),
            {
                compilerOptions: {
                    module: ts.ModuleKind.CommonJS,
                    target: ts.ScriptTarget.ES2022,
                },
            },
        ).outputText,
        { exports: pollingExports },
    );

    for (const tab of [
        'waiting',
        'serving',
        'held',
        'treatment',
        'removed',
        'checks',
    ]) {
        const draftFilters = {
            query: 'Synthetic',
            doctor_id: '8',
            priority: 'urgent',
            status: tab,
        };
        const appliedFilters = { ...draftFilters };
        const draftValidationError = { value: 'old error' };
        runInNewContext(`${declaration}\nclearFilters();`, {
            draftFilters,
            appliedFilters,
            draftValidationError,
            activeTab: { value: tab },
            clearQueueFilters: pollingExports.clearQueueFilters,
            replaceAppliedRefresh: (prepare) => prepare(),
        });
        assert.equal(appliedFilters.status, 'waiting', tab);
        assert.equal(draftFilters.status, 'waiting', tab);
        assert.equal(appliedFilters.query, '');
        assert.equal(appliedFilters.doctor_id, '');
        assert.equal(appliedFilters.priority, '');
        assert.equal(draftValidationError.value, '');
        assert.equal(
            pollingExports.queuePollPayload(appliedFilters, 1, 1).status,
            'waiting',
        );
    }
});
