import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import * as vue from 'vue';

const source = ts.transpileModule(
    readFileSync('resources/js/composables/useEmergencyAlerts.ts', 'utf8'),
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const row = {
    visitNumber: 'V-TEST',
    queueNumber: 'Q001',
    waitingMinutes: 2,
    takenBy: null,
    mine: false,
    canTake: true,
    alertOrigin: 'clinical',
    episodeId: 1,
    lockVersion: 0,
    alertStartedAt: '2026-11-26T01:00:00Z',
    seenByMe: false,
    legacy: false,
};

function harness(request, { post, AudioContext, fromVisit } = {}) {
    const timers = new Map();
    const titleObservers = [];
    const storage = new Map();
    const document = {
        title: 'Queue',
        head: {},
        visibilityState: 'visible',
        cookie: '',
        addEventListener() {},
        removeEventListener() {},
    };
    const module = { exports: {} };
    class JsonRequestError extends Error {}
    vm.runInNewContext(source, {
        exports: module.exports,
        require: (name) =>
            name === 'vue'
                ? vue
                : name === '@inertiajs/vue3'
                  ? {
                        router: {
                            post:
                                post ??
                                (() => {
                                    throw new Error('Unexpected Take');
                                }),
                        },
                    }
                  : { requestJson: request, JsonRequestError },
        document,
        window: { addEventListener() {}, removeEventListener() {} },
        localStorage: {
            getItem: (key) => storage.get(key),
            setItem: (key, value) => storage.set(key, value),
        },
        MutationObserver: class {
            constructor(callback) {
                titleObservers.push(callback);
            }
            observe() {}
            disconnect() {
                titleObservers.splice(0);
            }
        },
        AbortController,
        AudioContext,
        crypto: { randomUUID: () => 'synthetic-idempotency-key' },
        Date,
        setInterval: (callback, delay) => {
            timers.set(delay, callback);

            return delay;
        },
        clearInterval: (key) => timers.delete(key),
    });
    const context = vue.ref({ branchId: 1, userId: 7, canRespond: true });
    let state;
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
    const app = renderer.createApp({
        setup() {
            state = module.exports.useEmergencyAlerts(
                context,
                fromVisit ?? (() => undefined),
            );

            return () => vue.h('div');
        },
    });
    app.mount({});

    return { state, context, app, timers, document, storage, titleObservers };
}
const settle = async () => {
    await Promise.resolve();
    await vue.nextTick();
    await Promise.resolve();
    await vue.nextTick();
};

test('mount polls immediately, updates emergency title, and unmount restores title and removes timers', async () => {
    let reads = 0;
    const h = harness(async () => {
        reads++;

        return { alerts: [row] };
    });
    await settle();
    assert.equal(reads, 1);
    assert.equal(h.state.alerts.value.length, 1);
    assert.equal(h.document.title, '(!) Emergency · KPOne');
    assert.deepEqual(
        [...h.timers.keys()].sort((a, b) => a - b),
        [1000, 5000, 10000],
    );
    h.app.unmount();
    assert.equal(h.document.title, 'Queue');
    assert.equal(h.timers.size, 0);
});

test('a delayed response from the previous branch cannot populate the new branch', async () => {
    const requests = [];
    const h = harness(
        (url, options) =>
            new Promise((resolve) => requests.push({ url, options, resolve })),
    );
    h.context.value = { branchId: 2, userId: 7, canRespond: true };
    await settle();
    assert.equal(requests[0].options.signal.aborted, true);
    requests[1].resolve({ alerts: [] });
    requests[0].resolve({ alerts: [row] });
    await settle();
    assert.equal(h.state.alerts.value.length, 0);
    assert.match(requests[1].url, /expected_branch_id=2/);
    h.app.unmount();
});

test('an uncertain Seen retry preserves its exact command and blocks another action', async () => {
    const commands = [];
    const h = harness(async (_url, options) => {
        if (options.method === 'POST') {
            commands.push(options.body);

            if (commands.length === 1) {
                throw new Error('Connection lost after commit');
            }

            return {};
        }

        return { alerts: [row] };
    });
    await settle();
    h.state.command('seen', row);
    await settle();
    assert.equal(h.state.uncertain.value, true);
    h.state.command('seen', { ...row, visitNumber: 'V-OTHER' });
    assert.equal(commands.length, 1);
    await h.state.retry();
    await settle();
    assert.equal(commands.length, 2);
    assert.equal(commands[0], commands[1]);
    assert.equal(h.state.uncertain.value, false);
    h.app.unmount();
});

test('device mute remains bounded and polling failure retains the visible emergency', async () => {
    let fail = false;
    const h = harness(async () => {
        if (fail) {
            throw new Error('Offline');
        }

        return { alerts: [row] };
    });
    await settle();
    const before = Date.now();
    h.state.mute();
    assert.equal(h.state.muted.value, true);
    const expiry = Number([...h.storage.values()][0]);
    assert.ok(expiry >= before + 120000 && expiry <= Date.now() + 120000);
    fail = true;
    await h.state.read();
    assert.equal(h.state.paused.value, true);
    assert.equal(h.state.alerts.value.length, 1);
    h.app.unmount();
});

test('sound needs a gesture, Seen leaves it running, and device mute stops the sound clock', async () => {
    let tones = 0;
    let opened = 0;
    class AudioContext {
        state = 'suspended';
        currentTime = 0;
        destination = {};
        constructor() {
            opened++;
        }
        async resume() {
            this.state = 'running';
        }
        async close() {
            this.state = 'closed';
        }
        createOscillator() {
            return {
                frequency: { setValueAtTime() {} },
                connect() {},
                start() {
                    tones++;
                },
                stop() {},
            };
        }
        createGain() {
            return {
                gain: {
                    setValueAtTime() {},
                    exponentialRampToValueAtTime() {},
                },
                connect() {},
            };
        }
    }
    const h = harness(async () => ({ alerts: [{ ...row, seenByMe: true }] }), {
        AudioContext,
    });
    await settle();
    h.timers.get(10000)();
    assert.equal(opened, 0);
    assert.equal(tones, 0);
    await h.state.enableSound();
    assert.equal(tones, 1);
    h.timers.get(10000)();
    assert.equal(tones, 2);
    h.state.mute();
    h.timers.get(10000)();
    assert.equal(tones, 2);
    h.app.unmount();
});

test('Take retries the exact version and return context after a lost response', async () => {
    const posts = [];
    const h = harness(async () => ({ alerts: [row] }), {
        post: (url, payload, options) => posts.push({ url, payload, options }),
        fromVisit: () => 'V-ORIGINAL',
    });
    await settle();
    h.state.command('take', row);
    assert.equal(posts.length, 1);
    posts[0].options.onNetworkError();
    posts[0].options.onFinish();
    await settle();
    assert.equal(h.state.uncertain.value, true);
    h.state.command('take', { ...row, visitNumber: 'V-OTHER' });
    assert.equal(posts.length, 1);
    await h.state.retry();
    assert.equal(posts.length, 2);
    assert.equal(posts[0].payload, posts[1].payload);
    assert.equal(posts[1].payload.from_visit, 'V-ORIGINAL');
    assert.equal(posts[1].payload.lock_version, 0);
    posts[1].options.onSuccess();
    posts[1].options.onFinish();
    assert.equal(h.state.uncertain.value, false);
    h.app.unmount();
});

test('branch change cancels a Take and ignores its late failure callbacks', async () => {
    let callbacks;
    let cancelled = false;
    const h = harness(async () => ({ alerts: [] }), {
        post: (_url, _payload, options) => {
            callbacks = options;
            options.onCancelToken({
                cancel: () => {
                    cancelled = true;
                },
            });
        },
    });
    await settle();
    h.state.command('take', row);
    h.context.value = { branchId: 2, userId: 7, canRespond: false };
    await settle();
    assert.equal(cancelled, true);
    callbacks.onNetworkError();
    callbacks.onFinish();
    assert.equal(h.state.uncertain.value, false);
    assert.equal(h.state.error.value, '');
    assert.equal(h.state.busy.value, false);
    h.app.unmount();
});

test('same-branch cancellation preserves an uncertain Take for unchanged retry', async () => {
    const posts = [];
    const h = harness(async () => ({ alerts: [row] }), {
        post: (url, payload, options) => posts.push({ url, payload, options }),
    });
    await settle();
    h.state.command('take', row);
    posts[0].options.onCancel();
    posts[0].options.onFinish();
    assert.equal(h.state.uncertain.value, true);
    h.state.command('take', { ...row, visitNumber: 'V-OTHER' });
    assert.equal(posts.length, 1);
    await h.state.retry();
    assert.equal(posts.length, 2);
    assert.equal(posts[0].payload, posts[1].payload);
    h.app.unmount();
});

test('an Inertia page title change keeps the emergency marker and restores the newest page title when clear', async () => {
    const h = harness(async () => ({ alerts: [row] }));
    await settle();
    h.document.title = 'Registration · KPOne';
    h.titleObservers[0]();
    assert.equal(h.document.title, '(!) Emergency · KPOne');
    h.state.alerts.value = [];
    await settle();
    assert.equal(h.document.title, 'Registration · KPOne');
    h.app.unmount();
    assert.equal(h.titleObservers.length, 0);
});

test('retained command and read callbacks cannot restart requests after unmount', async () => {
    let reads = 0;
    const posts = [];
    const h = harness(
        async () => {
            reads++;

            return { alerts: [row] };
        },
        { post: (...args) => posts.push(args) },
    );
    await settle();
    const retainedTake = () => h.state.command('take', row);
    h.app.unmount();
    retainedTake();
    h.state.command('seen', row);
    await h.state.retry();
    await h.state.read();
    await settle();
    assert.equal(posts.length, 0);
    assert.equal(reads, 1);
    assert.equal(h.state.busy.value, false);
});
