import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { nextTick, reactive } from 'vue';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const source = read('resources/js/components/billing/InvoiceNotes.vue');
const descriptor = parse(source).descriptor;
const js = ts.transpileModule(
    compileScript(descriptor, { id: 'notes' }).content,
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const require = createRequire(import.meta.url);
const flush = async () => {
    await Promise.resolve();
    await nextTick();
    await Promise.resolve();
};
const harness = () => {
    const props = reactive({
        context: {
            url: '/visits/SYN/billing/SYN/notes',
            branchId: 12,
            invoiceNumber: 'SYN-1',
            canAdd: true,
        },
    });
    const page = reactive({ props: { branchContext: { active: { id: 12 } } } });
    const requests = [],
        callbacks = {},
        cleanups = [],
        emitted = [];
    const module = { exports: {} };
    runInNewContext(js, {
        exports: module.exports,
        crypto: { randomUUID },
        AbortController,
        URLSearchParams,
        document: { querySelector: () => ({ content: 'SYN-CSRF' }) },
        require: (name) => {
            if (name === 'vue') {
                return {
                    ...require('vue'),
                    onBeforeUnmount: (cb) => cleanups.push(cb),
                };
            }

            if (name === '@inertiajs/vue3') {
                return {
                    usePage: () => page,
                    router: {
                        on: (event, callback) => {
                            callbacks[event] = callback;

                            return () => delete callbacks[event];
                        },
                    },
                };
            }

            if (name === '@/lib/json-client') {
                return {
                    requestJson: (url, init) =>
                        new Promise((resolve, reject) =>
                            requests.push({ url, init, resolve, reject }),
                        ),
                };
            }

            if (name.startsWith('@/')) {
                return {};
            }

            return require(name);
        },
    });
    const api = module.exports.default.setup(props, {
        expose() {},
        emit: (name) => emitted.push(name),
    });

    return { props, page, requests, callbacks, api, cleanups, emitted };
};
const response = (extra = {}) => ({
    data: [],
    version: 0,
    canAdd: true,
    nextCursor: null,
    ...extra,
});

test('shared invoice note dialog compiles and keeps bodies escaped, internal and outside print/claim controls', () => {
    assert.deepEqual(
        compileTemplate({
            source: descriptor.template.content,
            filename: 'InvoiceNotes.vue',
            id: 'notes',
        }).errors,
        [],
    );
    assert.match(source, /maxlength="500"/);
    assert.match(source, /\{\{ note.body \}\}/);
    assert.match(source, /Internal\s+billing\s+administration only/);
    assert.match(source, /View retained original/);
    assert.doesNotMatch(
        source,
        /v-html|localStorage|sessionStorage|anchor|entryId|claimId/,
    );

    for (const path of [
        'resources/js/pages/Billing/Invoice.vue',
        'resources/js/components/billing/BillingWorkspace.vue',
    ]) {
        assert.match(read(path), /InvoiceNotes/);
    }

    assert.doesNotMatch(
        read('resources/js/pages/Billing/Print.vue'),
        /InvoiceNotes|note.body/,
    );
});

test('note save keeps retry key and versions, single-flights, and clears draft only after confirmed success', async () => {
    const h = harness();
    h.api.show('added');
    assert.equal(h.requests.length, 1);
    h.requests[0].resolve(response());
    await flush();
    h.api.body.value = 'Synthetic private note';
    const first = h.api.save();
    h.api.save();
    assert.equal(h.requests.length, 2);
    const payload = JSON.parse(h.requests[1].init.body);
    assert.equal(payload.lock_version, 0);
    assert.equal(payload.operation, 'added');
    assert.equal(payload.body, 'Synthetic private note');
    assert.equal(payload.anchor, undefined);
    h.requests[1].reject({
        status: 422,
        payload: { errors: { body: ['Synthetic refusal'] } },
    });
    await first;
    assert.equal(h.api.body.value, 'Synthetic private note');
    const retry = h.api.save();
    assert.equal(
        JSON.parse(h.requests[2].init.body).idempotency_key,
        payload.idempotency_key,
    );
    h.requests[2].resolve({ version: 1 });
    await flush();
    assert.equal(h.api.body.value, '');
    assert.deepEqual(h.emitted, ['saved']);
    h.requests[3].resolve(response({ version: 1 }));
    await retry;
    assert.equal(h.api.version.value, 1);
    assert.equal(h.api.mode.value, 'history');
});

test('stale saves require refresh; denied saves clear private drafts and close', async () => {
    const h = harness();
    h.api.show('added');
    h.requests[0].resolve(response());
    await flush();
    h.api.body.value = 'Synthetic draft';
    const save = h.api.save();
    h.requests[1].reject({
        status: 422,
        payload: { errors: { lock_version: ['Changed'] } },
    });
    await save;
    assert.equal(h.api.stale.value, true);
    await h.api.save();
    assert.equal(h.requests.length, 2);
    const load = h.api.load();
    h.requests[2].resolve(response({ version: 3 }));
    await load;
    assert.equal(h.api.stale.value, false);
    assert.equal(h.api.version.value, 3);
    const denied = h.api.save();
    h.requests[3].reject({ status: 403 });
    await denied;
    assert.equal(h.api.open.value, false);
    assert.equal(h.api.body.value, '');
    assert.equal(h.api.entries.value.length, 0);
});

test('close and navigation abort pending note loads; late data cannot restore another branch contents', async () => {
    const h = harness();
    h.api.show('history');
    h.api.open.value = false;
    await flush();
    assert.equal(h.requests[0].init.signal.aborted, true);
    h.requests[0].resolve(response({ data: [{ body: 'Late private note' }] }));
    await flush();
    assert.equal(h.api.entries.value.length, 0);
    h.api.show('added');
    h.api.body.value = 'Unsaved private note';
    h.callbacks.start();
    assert.equal(h.requests[1].init.signal.aborted, true);
    assert.equal(h.api.body.value, '');
    h.requests[1].resolve(response({ data: [{ body: 'Late other note' }] }));
    await flush();
    assert.equal(h.api.entries.value.length, 0);
    assert.equal(h.api.open.value, false);
    h.page.props.branchContext.active.id = 13;
    await flush();
    assert.equal(h.api.blocked.value, true);
    h.cleanups.forEach((cb) => cb());
    assert.equal(h.callbacks.start, undefined);
});
