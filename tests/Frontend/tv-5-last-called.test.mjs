import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

// Mount the real Registration page with fake time and controllable browser lifecycle.
const realSetTimeout = globalThis.setTimeout;
const { createRenderer, defineComponent, nextTick } = Vue;

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const compileComponent = (path, requireMap = {}) => {
    const descriptor = parse(read(path), { filename: path }).descriptor;
    const compiled = compileScript(descriptor, {
        id: `test-${path.replaceAll(/\W/g, '-')}`,
        inlineTemplate: true,
    }).content;
    const javascript = ts.transpileModule(compiled, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const componentModule = { exports: {} };
    const localRequire = (specifier) => {
        if (specifier === 'vue') {
            return Vue;
        }

        if (specifier in requireMap) {
            return requireMap[specifier];
        }

        throw new Error(`Unexpected component import: ${specifier}`);
    };

    Function(
        'require',
        'module',
        'exports',
        javascript,
    )(localRequire, componentModule, componentModule.exports);

    return componentModule.exports.default;
};

const createMountedRenderer = () => {
    if (!globalThis.Document) {
        globalThis.Document = class {};
    }

    const rootDocument = new globalThis.Document();
    rootDocument.activeElement = null;
    const createElement = (type) => {
        const element = {
            type,
            props: {},
            children: [],
            listeners: {},
            parent: null,
            addEventListener(name, listener) {
                this.listeners[name] = listener;
            },
            removeEventListener(name) {
                delete this.listeners[name];
            },
            getRootNode() {
                return rootDocument;
            },
            focus() {},
        };

        return element;
    };
    const renderer = createRenderer({
        patchProp(element, key, _previous, next) {
            element.props[key] = next;
        },
        insert(child, parent, anchor = null) {
            child.parent = parent;
            const position = anchor ? parent.children.indexOf(anchor) : -1;

            if (position >= 0) {
                parent.children.splice(position, 0, child);
            } else {
                parent.children.push(child);
            }
        },
        remove(child) {
            const position = child.parent?.children.indexOf(child) ?? -1;

            if (position >= 0) {
                child.parent.children.splice(position, 1);
            }
        },
        createElement,
        createText: (text) => ({ type: '#text', text, parent: null }),
        createComment: (text) => ({ type: '#comment', text, parent: null }),
        setText(node, text) {
            node.text = text;
        },
        setElementText(element, text) {
            element.text = text;
            element.children = [];
        },
        parentNode: (node) => node.parent,
        nextSibling(node) {
            const siblings = node.parent?.children ?? [];

            return siblings[siblings.indexOf(node) + 1] ?? null;
        },
        querySelector: () => null,
        setScopeId: () => {},
        cloneNode: (node) => ({ ...node, props: { ...node.props } }),
        insertStaticContent: () => [null, null],
    });

    return { renderer, root: createElement('root') };
};

const descendants = (node, type) => {
    if (Array.isArray(node)) {
        return node.flatMap((child) => descendants(child, type));
    }

    if (!node) {
        return [];
    }

    return [
        ...(node.type === type ? [node] : []),
        ...descendants(node.children ?? [], type),
    ];
};

const baseTime = Date.parse('2026-11-03T04:00:00.000Z');
const NativeDate = Date;
const NullStub = defineComponent({ setup: () => () => null });
const moduleFor = (path) => {
    const output = ts.transpileModule(read(path), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const result = { exports: {} };
    Function('module', 'exports', output)(result, result.exports);

    return result.exports;
};
const display = moduleFor('resources/js/lib/queue-display.ts');
let time,
    wallSkew,
    timers,
    listeners,
    sequence,
    responseFactory,
    requests,
    mounted;
let original;
const makeFeed = (age = 0) => ({
    branch: {
        id: 1,
        name: 'Synthetic TV Five Branch',
        timezone: 'Asia/Kuala_Lumpur',
    },
    serverTime: new NativeDate(baseTime).toISOString(),
    calls: [
        {
            id: 1,
            number: 'A-001',
            room: 'Room 1',
            service: 'consultation',
            calledAt: new NativeDate(baseTime - age).toISOString(),
            isRecall: false,
        },
    ],
    settings: {
        tickerText: null,
        youtubeVideoId: null,
        posterSeconds: 10,
        callDisplayMode: 'number',
        posters: [],
        lockVersion: 1,
    },
});
const flush = async () => {
    await new Promise((resolve) => realSetTimeout(resolve, 0));
    await nextTick();
};
const advance = async (milliseconds) => {
    const end = time + milliseconds;

    for (;;) {
        const next = [...timers]
            .filter(([, timer]) => timer.at <= end)
            .sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];

        if (!next) {
            break;
        }

        time = next[1].at;

        if (next[1].interval) {
            next[1].at += next[1].interval;
        } else {
            timers.delete(next[0]);
        }

        next[1].callback();
        await flush();
    }

    time = end;
    await flush();
};
const text = (node) =>
    [node.text ?? '', ...(node.children ?? []).map(text)].join('');
const labelled = (id) =>
    descendants(mounted.root, 'p').find(
        (node) => node.props['data-testid'] === id,
    );
const heading = () => text(labelled('current-call-heading'));
const mount = async (feed) => {
    responseFactory = () => ({
        ...feed,
        serverTime: new NativeDate(baseTime + time).toISOString(),
    });
    const Screen = compileComponent(
        'resources/js/pages/QueueDisplay/Screen.vue',
        {
            '@inertiajs/vue3': { Head: NullStub, router: { post() {} } },
            '@/lib/json-client': {
                JsonRequestError: class extends Error {},
                requestJson: async () => {
                    requests++;

                    return responseFactory();
                },
            },
            '@/lib/queue-display': display,
        },
    );
    mounted = createMountedRenderer();
    const app = mounted.renderer.createApp(Screen, {
        feed,
        feedUrl: '/queue-display/feed',
    });
    mounted.app = app;
    app.mount(mounted.root);
    await flush();
};

test.beforeEach(() => {
    original = {
        Date: globalThis.Date,
        window: globalThis.window,
        document: globalThis.document,
        AudioContext: globalThis.AudioContext,
        performance: Object.getOwnPropertyDescriptor(globalThis, 'performance'),
        navigator: Object.getOwnPropertyDescriptor(globalThis, 'navigator'),
    };
    time = 0;
    wallSkew = 0;
    sequence = 0;
    requests = 0;
    timers = new Map();
    listeners = new Map();
    mounted = null;
    globalThis.Date = class extends NativeDate {
        constructor(...args) {
            super(...(args.length ? args : [baseTime + time + wallSkew]));
        }
        static now() {
            return baseTime + time + wallSkew;
        }
    };
    Object.defineProperty(globalThis, 'performance', {
        configurable: true,
        value: { now: () => time },
    });
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: {},
    });
    globalThis.document = {
        fullscreenElement: null,
        visibilityState: 'visible',
        addEventListener: (name, callback) => listeners.set(name, callback),
        removeEventListener: (name) => listeners.delete(name),
    };
    const schedule = (callback, delay, interval = 0) => {
        const id = ++sequence;
        timers.set(id, { callback, at: time + delay, interval });

        return id;
    };
    globalThis.window = {
        setInterval: (callback, delay) => schedule(callback, delay, delay),
        clearInterval: (id) => timers.delete(id),
        setTimeout: (callback, delay) => schedule(callback, delay),
        clearTimeout: (id) => timers.delete(id),
        speechSynthesis: {
            getVoices: () => [],
            addEventListener() {},
            removeEventListener() {},
            cancel() {},
        },
    };
    globalThis.AudioContext = class {
        state = 'suspended';
        close() {
            return Promise.resolve();
        }
    };
});
test.afterEach(() => {
    mounted?.app.unmount();

    for (const key of ['Date', 'window', 'document', 'AudioContext']) {
        globalThis[key] = original[key];
    }

    for (const key of ['performance', 'navigator']) {
        if (original[key]) {
            Object.defineProperty(globalThis, key, original[key]);
        } else {
            delete globalThis[key];
        }
    }
});

for (const [age, label] of [
    [899999, 'Now calling'],
    [900000, 'Last called'],
    [3600000, 'Last called'],
]) {
    test(`TV-5 server age ${age} ms renders ${label} and retains number/room`, async () => {
        await mount(makeFeed(age));
        assert.equal(heading(), label);
        assert.equal(
            text(
                descendants(mounted.root, 'p').find(
                    (node) => node.props['data-testid'] === 'current-label',
                ),
            ),
            'A-001',
        );
        assert.equal(
            text(
                descendants(mounted.root, 'p').find(
                    (node) => node.props['data-testid'] === 'current-room',
                ),
            ),
            'Room 1',
        );
    });
}

test('TV-5 crosses 900 seconds between polls using monotonic elapsed time', async () => {
    await mount(makeFeed(899000));
    await advance(999);
    assert.equal(heading(), 'Now calling');
    await advance(1);
    assert.equal(heading(), 'Last called');
    assert.equal(requests, 0);
});

for (const skew of [-300000, 300000]) {
    test(`TV-5 ignores TV wall clock skew of ${skew} ms and runtime wall-clock jumps`, async () => {
        wallSkew = skew;
        await mount(makeFeed(899000));
        assert.equal(heading(), 'Now calling');
        wallSkew = -skew;
        await advance(1000);
        assert.equal(heading(), 'Last called');
        assert.equal(requests, 0);
    });
}

test('TV-5 recall gets its own fresh calledAt and retains a secondary Recall indicator', async () => {
    const feed = makeFeed(3600000);
    await mount(feed);
    assert.equal(heading(), 'Last called');
    assert.equal(labelled('recall-indicator'), undefined);
    const recall = {
        ...feed.calls[0],
        id: 2,
        isRecall: true,
        calledAt: new NativeDate(baseTime + 5000).toISOString(),
    };
    responseFactory = () => ({
        ...feed,
        serverTime: new NativeDate(baseTime + time).toISOString(),
        calls: [recall],
    });
    await advance(5000);
    assert.equal(heading(), 'Now calling');
    assert.equal(text(labelled('recall-indicator')), 'Recall');
    await advance(900000);
    assert.equal(heading(), 'Last called');
    assert.equal(text(labelled('recall-indicator')), 'Recall');
});
test('TV-5 a failed poll retains the last server anchor and age continues advancing', async () => {
    await mount(makeFeed(890000));
    responseFactory = () =>
        Promise.reject(new Error('Synthetic network failure'));
    await advance(5000);
    assert.equal(heading(), 'Now calling');
    assert.equal(requests, 1);
    await advance(5000);
    assert.equal(heading(), 'Last called');
    assert.equal(requests, 2);
});
test('TV-5 every successful poll resynchronises even when the call id stays unchanged', async () => {
    const feed = makeFeed(100000);
    await mount(feed);
    responseFactory = () => ({
        ...feed,
        serverTime: new NativeDate(baseTime + 1000000).toISOString(),
    });
    await advance(5000);
    assert.equal(heading(), 'Last called');
    responseFactory = () => ({
        ...feed,
        serverTime: new NativeDate(baseTime - 50000).toISOString(),
    });
    await advance(5000);
    assert.equal(heading(), 'Now calling');
});
test('TV-5 cleans up its age timer without changing the displayed-clock interval', async () => {
    await mount(makeFeed());
    assert.equal(timers.size, 4);
    mounted.app.unmount();
    mounted = null;
    assert.equal(timers.size, 0);
    assert.equal(listeners.size, 0);
    const source = read('resources/js/pages/QueueDisplay/Screen.vue');
    assert.ok(
        source.includes(
            'clockTimer = window.setInterval(() => (now.value = new Date()), 1_000);',
        ),
    );
    assert.ok(source.includes("'Recall'"));
});
