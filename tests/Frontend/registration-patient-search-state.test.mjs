import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { useForm } from '@inertiajs/vue3';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const read = (file) => readFileSync(file, 'utf8');
const formSource = read(
    'resources/js/components/registration/RegistrationCreateForm.vue',
);
const pageSource = read('resources/js/pages/Registration/Create.vue');
const renderer = createRenderer({
    createElement: (tag) => ({ tag, children: [] }),
    createText: (text) => ({ text }),
    createComment: (text) => ({ text }),
    insert: (node, parent) => {
        node.parent = parent;
        parent.children ??= [];
        parent.children.push(node);
    },
    remove: (node) => {
        if (node.parent) {
            node.parent.children = node.parent.children.filter(
                (child) => child !== node,
            );
        }
    },
    setText: (node, text) => {
        node.text = text;
    },
    setElementText: (node, text) => {
        node.text = text;
    },
    parentNode: (node) => node.parent,
    nextSibling: (node) =>
        node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    patchProp: () => {},
});
function harness() {
    const requests = [];
    const timers = new Map();
    const instances = [];
    let timerId = 0;
    let focusCalls = 0;
    const props = reactive({
        options: {
            idempotencyKey: 'synthetic-command',
            branch: { id: 9, name: 'Synthetic branch' },
            doctors: [{ id: 1, name: 'Synthetic doctor', room: true }],
            panels: [],
            autoAssign: { available: true },
        },
        recentPatients: [],
        requirements: {},
        guardianToday: '2026-11-26',
    });
    const stubs = new Proxy({}, { get: () => () => null });
    const baseRequire = (name) => {
        if (name === 'vue') {
            return require(name);
        }

        if (name === '@inertiajs/vue3') {
            return { useForm, Head: () => null, Link: () => null };
        }

        if (name === '@/lib/patient-registration') {
            return {
                phoneError: () => '',
                identityError: () => '',
                focusInvalidField: () => {
                    focusCalls++;
                },
            };
        }

        if (name === '@/lib/guardian-contact') {
            return { knownMinor: () => false };
        }

        return stubs;
    };
    const load = (source, inline, customRequire = baseRequire) => {
        const module = { exports: {} };
        runInNewContext(
            transpile(
                compileScript(parse(source).descriptor, {
                    id: 'extraction-proof',
                    inlineTemplate: inline,
                }).content,
            ),
            {
                exports: module.exports,
                require: customRequire,
                crypto: webcrypto,
                AbortController,
                DOMException,
                document: {
                    querySelector: () => ({
                        content: 'synthetic-csrf',
                        focus: () => {
                            focusCalls++;
                        },
                    }),
                },
                setTimeout: (callback) => {
                    timers.set(++timerId, callback);

                    return timerId;
                },
                clearTimeout: (id) => timers.delete(id),
                fetch: (url, options) => {
                    const request = { url, options };
                    requests.push(request);

                    return url.startsWith('/registration/recommended-doctor')
                        ? Promise.resolve({
                              ok: true,
                              json: async () => ({ doctor: null }),
                          })
                        : new Promise((resolve, reject) => {
                              request.resolve = resolve;
                              request.reject = reject;
                          });
                },
            },
        );

        return module.exports.default;
    };
    const form = load(formSource, false);
    const setup = form.setup;
    form.setup = (currentProps, context) => {
        const state = setup(currentProps, context);
        instances.push(state);

        return () => null;
    };
    const page = load(pageSource, true, (name) =>
        name === '@/components/registration/RegistrationCreateForm.vue'
            ? { default: form }
            : baseRequire(name),
    );
    const app = renderer.createApp({ render: () => h(page, props) });
    app.mount({ children: [] });

    return {
        app,
        props,
        instances,
        requests,
        timers,
        focusCalls: () => focusCalls,
    };
}

// Deliberately resolve/reject controlled transport promises after abort: these
// verify defensive guards, not an asserted real-browser network incident.
const patientRequests = (p) =>
    p.requests.filter((request) => request.url === '/patients/search');
const result = (number) => ({
    ok: true,
    json: async () => ({
        data: number
            ? [{ patientNumber: number, fullName: 'Synthetic patient' }]
            : [],
    }),
});
const start = async (p, query) => {
    p.instances[0].patientQuery.value = query;
    await nextTick();
    const completion = p.instances[0].searchPatients();

    return { completion, request: patientRequests(p).at(-1) };
};

test('explicit search cancels its queued debounce and repeated Enter does not overlap the same pending query', async () => {
    const p = harness();
    const state = p.instances[0];
    state.patientQuery.value = 'Synthetic';
    await nextTick();
    assert.equal(p.timers.size, 1);
    assert.equal(state.emptyPatientSearchMessage.value, 'Search pending…');
    const running = state.searchPatients();
    assert.equal(p.timers.size, 0);
    await state.searchPatients();
    assert.equal(patientRequests(p).length, 1);
    patientRequests(p)[0].resolve(result(null));
    await running;
    assert.equal(
        state.emptyPatientSearchMessage.value,
        'No matching Patient found.',
    );
    p.app.unmount();
});

test('clear restores recent rows and late aborted success or HTTP error cannot repopulate or report a stale error', async () => {
    for (const response of [
        result('KP-OLD'),
        { ok: false, json: async () => ({ message: 'Old failure' }) },
    ]) {
        const p = harness();
        const state = p.instances[0];
        p.props.recentPatients = [
            { patientNumber: 'KP-RECENT', fullName: 'Synthetic recent' },
        ];
        await nextTick();
        const old = await start(p, 'Old query');
        state.patientQuery.value = '';
        await nextTick();
        assert.equal(old.request.options.signal.aborted, true);
        old.request.resolve(response);
        await old.completion;
        assert.deepEqual(
            Array.from(state.patientResults.value, (row) => row.patientNumber),
            ['KP-RECENT'],
        );
        assert.equal(state.patientSearchError.value, '');
        assert.equal(state.searching.value, false);
        p.app.unmount();
    }
});

test('A to B to A rejects the first A even though the current query text matches again', async () => {
    const p = harness();
    const state = p.instances[0];
    const first = await start(p, 'Query A');
    const second = await start(p, 'Query B');
    const latest = await start(p, 'Query A');
    first.request.resolve(result('KP-STALE-A'));
    await first.completion;
    second.request.reject(new Error('Old B failure'));
    await second.completion;
    assert.equal(state.searching.value, true);
    assert.equal(state.patientResults.value.length, 0);
    assert.equal(state.patientSearchError.value, '');
    latest.request.resolve(result('KP-CURRENT-A'));
    await latest.completion;
    assert.deepEqual(
        Array.from(state.patientResults.value, (row) => row.patientNumber),
        ['KP-CURRENT-A'],
    );
    assert.equal(state.searching.value, false);
    p.app.unmount();
});

test('a stale JSON rejection and its finally cannot replace the current error or release the newer request', async () => {
    const p = harness();
    const state = p.instances[0];
    const old = await start(p, 'Old JSON query');
    let rejectJson;
    const decoding = new Promise((_, reject) => {
        rejectJson = reject;
    });
    old.request.resolve({ ok: true, json: () => decoding });
    await Promise.resolve();
    await Promise.resolve();
    const latest = await start(p, 'Current query');
    rejectJson(new Error('Old JSON failed'));
    await old.completion;
    assert.equal(state.searching.value, true);
    assert.equal(state.patientSearchError.value, '');
    latest.request.resolve({
        ok: false,
        json: async () => ({ message: 'Current search unavailable' }),
    });
    await latest.completion;
    assert.equal(state.patientSearchError.value, 'Current search unavailable');
    assert.equal(
        state.emptyPatientSearchMessage.value,
        'Search unavailable. No results have been confirmed.',
    );
    p.app.unmount();
});

test('an accepted empty response alone shows no-match and a fresh failed retry removes that claim', async () => {
    const p = harness();
    const state = p.instances[0];
    const first = await start(p, 'Synthetic missing');
    first.request.resolve(result(null));
    await first.completion;
    assert.equal(
        state.emptyPatientSearchMessage.value,
        'No matching Patient found.',
    );
    const retry = state.searchPatients();
    assert.equal(state.emptyPatientSearchMessage.value, 'Search pending…');
    patientRequests(p).at(-1).reject(new Error('Network unavailable'));
    await retry;
    assert.match(state.emptyPatientSearchMessage.value, /Search unavailable/);
    assert.doesNotMatch(state.emptyPatientSearchMessage.value, /No matching/);
    state.patientQuery.value = 'x';
    await nextTick();
    assert.equal(
        state.emptyPatientSearchMessage.value,
        'Enter at least 3 characters.',
    );
    assert.equal(state.searching.value, false);
    p.app.unmount();
});

test('unmount invalidates pending work before an abort-ignoring completion', async () => {
    const p = harness();
    const state = p.instances[0];
    const pending = await start(p, 'Pending query');
    p.app.unmount();
    assert.equal(pending.request.options.signal.aborted, true);
    pending.request.resolve(result('KP-AFTER-UNMOUNT'));
    await pending.completion;
    assert.equal(state.patientResults.value.length, 0);
    assert.equal(state.patientSearchError.value, '');
    assert.equal(p.timers.size, 0);
});
