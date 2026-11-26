import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const source = readFileSync(
    'resources/js/pages/QueueDisplay/Settings.vue',
    'utf8',
);
function setup() {
    const requests = [];
    const watchers = [];
    let dispose;
    const forms = Object.fromEntries(
        ['roomForm', 'editForm', 'settingsForm', 'posterForm'].map((key) => [
            key,
            { processing: false, draft: 'Unsaved synthetic draft' },
        ]),
    );
    const script = source.slice(
        source.indexOf('const callsBusy ='),
        source.indexOf('const selectBranch ='),
    );
    const context = {
        ...forms,
        props: { branch: { id: 7 } },
        ref: (value) => ({ value }),
        watch: (getter, callback) => watchers.push(callback),
        onBeforeUnmount: (callback) => {
            dispose = callback;
        },
        router: { get: (...args) => requests.push(args) },
    };
    runInNewContext(
        ts.transpileModule(
            `${script}\nglobalThis.api = { loadCallsPage, callsBusy, callsError };`,
            {
                compilerOptions: {
                    target: ts.ScriptTarget.ES2022,
                    module: ts.ModuleKind.None,
                },
            },
        ).outputText,
        context,
    );

    return {
        ...context.api,
        requests,
        forms,
        branchChanged: () => watchers[0](),
        dispose: () => dispose(),
    };
}

test('actual settings paging requests only stats and preserves every unsaved form', () => {
    const state = setup();
    state.loadCallsPage(2);
    const [url, payload, options] = state.requests[0];
    assert.equal(url, '/queue-display-settings');
    assert.deepEqual(JSON.parse(JSON.stringify(payload)), {
        branch: 7,
        rooms_page: 2,
    });
    assert.deepEqual(Array.from(options.only), ['callsToday']);
    assert.equal(options.preserveState, true);
    assert.equal(options.preserveScroll, true);
    assert.equal(options.replace, true);

    for (const form of Object.values(state.forms)) {
        assert.equal(form.draft, 'Unsaved synthetic draft');
    }

    state.loadCallsPage(1);
    assert.equal(state.requests.length, 1);
    options.onNetworkError();
    options.onFinish();
    assert.match(state.callsError.value, /last loaded counts remain visible/);
    assert.equal(state.callsBusy.value, false);
    state.loadCallsPage(2);
    assert.equal(state.requests.length, 2);
    assert.equal(state.callsError.value, '');
});

test('actual settings paging cancels on context change and ignores stale or disposed callbacks', () => {
    const state = setup();
    let cancelled = 0;
    state.loadCallsPage(2);
    const old = state.requests[0][2];
    old.onCancelToken({ cancel: () => cancelled++ });
    state.branchChanged();
    assert.equal(cancelled, 1);
    state.loadCallsPage(1);
    old.onError();
    old.onFinish();
    assert.equal(state.callsBusy.value, true);
    assert.equal(state.callsError.value, '');
    old.onCancelToken({ cancel: () => cancelled++ });
    assert.equal(cancelled, 2);
    state.dispose();
    state.requests[1][2].onCancelToken({ cancel: () => cancelled++ });
    state.requests[1][2].onHttpException();
    state.loadCallsPage(2);
    assert.equal(cancelled, 3);
    assert.equal(state.requests.length, 2);
    assert.equal(state.callsError.value, '');
});

test('actual paging cannot interrupt a settings, room or poster mutation', () => {
    for (const key of ['roomForm', 'editForm', 'settingsForm', 'posterForm']) {
        const state = setup();
        state.forms[key].processing = true;
        state.loadCallsPage(2);
        assert.equal(state.requests.length, 0, key);
    }
});

test('actual summary handler focuses the persistent result before paging and never focuses on setup', () => {
    const component = readFileSync(
        'resources/js/components/queue/QueueCallsToday.vue',
        'utf8',
    );
    const body = component.slice(
        component.indexOf('const selectPage ='),
        component.indexOf('const serviceLabels'),
    );
    const events = [];
    const context = {
        props: { busy: false, stats: { roomPagination: { lastPage: 2 } } },
        results: { value: { focus: () => events.push('focus') } },
        emit: (name, page) => events.push(`${name}:${page}`),
    };
    runInNewContext(
        ts.transpileModule(`${body}\nglobalThis.selectPage = selectPage;`, {
            compilerOptions: { target: ts.ScriptTarget.ES2022 },
        }).outputText,
        context,
    );
    assert.deepEqual(events, []);
    context.selectPage(2);
    assert.deepEqual(events, ['focus', 'page:2']);
    context.props.busy = true;
    context.selectPage(1);
    context.props.busy = false;
    context.selectPage(0);
    context.selectPage(3);
    assert.equal(events.length, 2);
});

test('displayed paging busy state includes every guarded form mutation', () => {
    const binding = source.match(/:busy="([\s\S]*?)"/)[1];

    for (const name of ['roomForm', 'editForm', 'settingsForm', 'posterForm']) {
        assert.ok(binding.includes(`${name}.processing`));
    }
});
