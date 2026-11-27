import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

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
