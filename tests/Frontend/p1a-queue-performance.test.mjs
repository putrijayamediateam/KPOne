import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const source = readFileSync(
    new URL('../../resources/js/lib/queue-polling.ts', import.meta.url),
    'utf8',
);
const javascript = ts.transpileModule(source, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const queuePollingModule = { exports: {} };
Function(
    'module',
    'exports',
    javascript,
)(queuePollingModule, queuePollingModule.exports);
const {
    applyQueueFilters,
    clearQueueFilters,
    createQueuePollingLifecycle,
    emptyQueueFilters,
    mergeQueuePollSnapshot,
    queuePollPayload,
    queueVisibleError,
} = queuePollingModule.exports;

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, resolve, reject, aborted: false };
};

class FakeTimers {
    now = 0;
    nextId = 1;
    entries = new Map();

    set = (callback, delay) => {
        const id = this.nextId++;
        this.entries.set(id, { at: this.now + delay, callback });

        return id;
    };

    clear = (id) => this.entries.delete(id);

    advance = (milliseconds) => {
        const target = this.now + milliseconds;

        while (true) {
            const due = [...this.entries.entries()]
                .filter(([, entry]) => entry.at <= target)
                .sort((left, right) => left[1].at - right[1].at)[0];

            if (!due) {
                break;
            }

            const [id, entry] = due;
            this.entries.delete(id);
            this.now = entry.at;
            entry.callback();
        }

        this.now = target;
    };
}

const flushPromises = () => new Promise((resolve) => setImmediate(resolve));

const createPollingHarness = () => {
    const lifecycle = createQueuePollingLifecycle();
    const timers = new FakeTimers();
    const requests = [];
    const merged = [];
    let timer;
    let active;
    let visible = true;

    lifecycle.mount();

    const schedule = () => {
        if (timer) {
            timers.clear(timer);
        }

        if (!lifecycle.canRun() || !visible) {
            timer = undefined;

            return;
        }

        timer = timers.set(() => {
            timer = undefined;

            if (lifecycle.canRun()) {
                void refresh();
            }
        }, 3_000);
    };

    const refresh = (reschedule = true) => {
        if (!lifecycle.canRun() || !visible) {
            return Promise.resolve();
        }

        if (active) {
            return active;
        }

        const generation = lifecycle.currentGeneration();
        const request = deferred();
        requests.push(request);
        const operation = (async () => {
            try {
                const result = await request.promise;

                if (lifecycle.accepts(generation)) {
                    merged.push(result);
                }
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    throw error;
                }
            } finally {
                if (reschedule && lifecycle.accepts(generation)) {
                    schedule();
                }
            }
        })();
        active = operation;
        void operation.finally(() => {
            if (active === operation) {
                active = undefined;
            }
        });

        return operation;
    };

    const replace = async (prepare) => {
        const generation = lifecycle.invalidate();
        const obsolete = active;

        if (requests.length > 0) {
            requests.at(-1).aborted = true;
        }

        prepare();

        if (obsolete) {
            await obsolete;
        }

        if (!lifecycle.accepts(generation)) {
            return;
        }

        await refresh(false);

        if (lifecycle.accepts(generation)) {
            schedule();
        }
    };

    const hide = () => {
        lifecycle.invalidate();
        visible = false;

        if (timer) {
            timers.clear(timer);
            timer = undefined;
        }

        if (requests.length > 0) {
            requests.at(-1).aborted = true;
        }
    };

    const show = () => {
        if (!lifecycle.canRun()) {
            return Promise.resolve();
        }

        visible = true;

        return replace(() => undefined);
    };

    const dispose = () => {
        lifecycle.dispose();

        if (timer) {
            timers.clear(timer);
            timer = undefined;
        }

        if (requests.length > 0) {
            requests.at(-1).aborted = true;
        }
    };

    return {
        lifecycle,
        timers,
        requests,
        merged,
        schedule,
        refresh,
        replace,
        hide,
        show,
        dispose,
    };
};

test('draft typing cannot alter the recurring polling payload until Apply', () => {
    const draft = emptyQueueFilters();
    const applied = emptyQueueFilters();

    draft.query = 'Partially typed Patient';

    assert.equal(queuePollPayload(applied, 2, 3).query, '');
    assert.equal(applyQueueFilters(draft, applied), '');
    assert.equal(
        queuePollPayload(applied, 2, 3).query,
        'Partially typed Patient',
    );
    assert.equal(queuePollPayload(applied, 2, 3).page, 2);
    assert.equal(queuePollPayload(applied, 2, 3).carry_page, 3);
});

test('invalid draft input does not replace the last valid applied filters', () => {
    const draft = { ...emptyQueueFilters(), query: 'Existing Patient' };
    const applied = emptyQueueFilters();
    assert.equal(applyQueueFilters(draft, applied), '');

    draft.query = 'ab';

    assert.equal(
        applyQueueFilters(draft, applied),
        'Enter at least 3 characters to search Patients.',
    );
    assert.equal(queuePollPayload(applied, 1, 1).query, 'Existing Patient');
});

test('Clear resets both draft and applied filters while retaining the selected tab', () => {
    const draft = {
        query: 'Patient',
        doctor_id: '4',
        priority: 'urgent',
        status: 'waiting',
    };
    const applied = { ...draft };

    clearQueueFilters(draft, applied, 'serving');

    assert.deepEqual(draft, emptyQueueFilters('serving'));
    assert.deepEqual(applied, emptyQueueFilters('serving'));
});

test('dynamic poll updates live rows while preserving stable branch and doctor metadata', () => {
    const current = {
        branch: {
            id: 1,
            code: 'SYN',
            name: 'Synthetic',
            timezone: 'Asia/Kuala_Lumpur',
        },
        doctors: [{ id: 2, name: 'Dr Synthetic' }],
        scope: 'branch',
        serverNow: '2026-09-08T00:00:00Z',
        operationalDate: '2026-09-08',
        waiting: {
            data: [{ queueNumber: '001' }],
            total: 1,
            currentPage: 1,
            lastPage: 1,
        },
        carryOver: { data: [], total: 0, currentPage: 1, lastPage: 1 },
        serving: [],
        removed: [],
    };
    const poll = {
        scope: 'branch',
        serverNow: '2026-09-08T00:00:03Z',
        operationalDate: '2026-09-08',
        waiting: { data: [], total: 0, currentPage: 1, lastPage: 1 },
        carryOver: { data: [], total: 0, currentPage: 1, lastPage: 1 },
        serving: [{ queueNumber: '001' }],
        removed: [],
    };

    const merged = mergeQueuePollSnapshot(current, poll);

    assert.equal(merged.branch, current.branch);
    assert.equal(merged.doctors, current.doctors);
    assert.equal(merged.waiting.total, 0);
    assert.equal(merged.serving[0].queueNumber, '001');
});

test('pending fetch cannot merge or restart polling after unmount', async () => {
    const harness = createPollingHarness();
    harness.schedule();
    harness.timers.advance(3_000);

    assert.equal(harness.requests.length, 1);
    harness.dispose();
    harness.requests[0].resolve('stale after unmount');
    await flushPromises();
    harness.timers.advance(12_000);

    assert.equal(harness.requests[0].aborted, true);
    assert.equal(harness.requests.length, 1);
    assert.deepEqual(harness.merged, []);
});

test('Reset invalidates an active Apply and only merges the cleared response', async () => {
    const harness = createPollingHarness();
    const filters = { draft: 'A', applied: 'A' };
    const applying = harness.replace(() => {
        filters.draft = 'B';
        filters.applied = 'B';
    });

    assert.equal(harness.requests.length, 1);
    const resetting = harness.replace(() => {
        filters.draft = '';
        filters.applied = '';
    });

    assert.equal(harness.requests[0].aborted, true);
    harness.requests[0].resolve('response B');
    await flushPromises();
    assert.equal(harness.requests.length, 2);
    harness.requests[1].resolve('response C');
    await Promise.all([applying, resetting]);

    assert.deepEqual(filters, { draft: '', applied: '' });
    assert.deepEqual(harness.merged, ['response C']);
});

test('draft validation error survives a successful poll and clears on valid Apply', () => {
    let draftValidationError =
        'Enter at least 3 characters to search Patients.';
    let liveRefreshError = 'Temporary refresh error.';

    liveRefreshError = '';
    assert.equal(
        queueVisibleError(draftValidationError, liveRefreshError),
        'Enter at least 3 characters to search Patients.',
    );

    draftValidationError = '';
    assert.equal(queueVisibleError(draftValidationError, ''), '');
});

test('completion-based polling stays single-flight at the three-second cadence', async () => {
    const harness = createPollingHarness();
    harness.schedule();
    harness.timers.advance(3_000);
    harness.timers.advance(9_000);
    assert.equal(harness.requests.length, 1);

    harness.requests[0].resolve('first');
    await flushPromises();
    harness.timers.advance(2_999);
    assert.equal(harness.requests.length, 1);
    harness.timers.advance(1);
    assert.equal(harness.requests.length, 2);

    harness.requests[1].resolve('second');
    await flushPromises();
    assert.deepEqual(harness.merged, ['first', 'second']);
});

test('visibility resumes one stream only while the lifecycle remains mounted', async () => {
    const harness = createPollingHarness();
    harness.schedule();
    harness.timers.advance(3_000);
    harness.hide();
    harness.requests[0].resolve('hidden response');
    await flushPromises();
    harness.timers.advance(9_000);
    assert.equal(harness.requests.length, 1);
    assert.deepEqual(harness.merged, []);

    const resumed = harness.show();
    await flushPromises();
    assert.equal(harness.requests.length, 2);
    harness.requests[1].resolve('visible response');
    await resumed;
    assert.deepEqual(harness.merged, ['visible response']);

    harness.hide();
    harness.dispose();
    await harness.show();
    harness.timers.advance(9_000);
    assert.equal(harness.requests.length, 2);
});
