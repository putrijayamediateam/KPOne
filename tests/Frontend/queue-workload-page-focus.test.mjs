import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const source = readFileSync('resources/js/pages/Queue/Index.vue', 'utf8');
const handler = source.match(
    /const requestWorkloadPage = \(targetPage: number\) => \{[\s\S]*?\n\};/,
)[0];
test('explicit workload paging focuses its persistent region before refresh and refuses busy paging', () => {
    const events = [];
    const exports = {};
    const state = {
        isRefreshing: { value: false },
        workload: { loading: { value: false } },
        busyKey: { value: null },
        workloadFocusTarget: { value: { focus: () => events.push('focus') } },
        workloadPage: { value: 1 },
        refreshBoard: () => events.push('refresh'),
    };
    runInNewContext(
        ts.transpileModule(`${handler}\nexports.page = requestWorkloadPage;`, {
            compilerOptions: { target: ts.ScriptTarget.ES2022 },
        }).outputText,
        { ...state, exports },
    );
    exports.page(2);
    assert.deepEqual(events, ['focus', 'refresh']);
    assert.equal(state.workloadPage.value, 2);

    for (const blocked of [
        state.isRefreshing,
        state.workload.loading,
        state.busyKey,
    ]) {
        blocked.value = true;
        exports.page(3);
        assert.equal(state.workloadPage.value, 2);
        assert.deepEqual(events, ['focus', 'refresh']);
        blocked.value = blocked === state.busyKey ? null : false;
    }

    assert.match(
        source,
        /ref="workloadFocusTarget"\s+tabindex="-1"\s+role="region"\s+aria-label="Queue workload results"/,
    );
    assert.match(source, /id="queue-workload-error"/);
    assert.match(source, /ref="emergencyFocusTarget"/);
});
