import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read(
    'resources/js/components/billing/InvoiceActivityDrawer.vue',
);
const descriptor = parse(source).descriptor;
const javascript = ts.transpileModule(
    compileScript(descriptor, { id: 'activity' }).content,
    { compilerOptions: { module: ts.ModuleKind.CommonJS } },
).outputText;
const flush = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};
const row = (id) => ({
    id,
    message: 'Payment recorded',
    scope: 'Invoice',
    amountSen: 29,
    method: 'Synthetic Cash',
    actor: 'Staff member',
    occurredAt: '1 Jan 2026, 9:00 AM',
});
const harness = () => {
    const requests = [],
        callbacks = {},
        cleanups = [];
    const props = reactive({
        context: { url: '/visits/SYN/billing/SYN/activity', branchId: 12 },
    });
    const page = reactive({ props: { branchContext: { active: { id: 12 } } } });
    const module = { exports: {} };
    runInNewContext(javascript, {
        exports: module.exports,
        AbortController,
        URLSearchParams,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return {
                    usePage: () => page,
                    router: {
                        on: (event, cb) => {
                            callbacks[event] = cb;

                            return () => {
                                delete callbacks[event];
                            };
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

            if (name === 'vue') {
                return {
                    ...require('vue'),
                    onBeforeUnmount: (cb) => cleanups.push(cb),
                };
            }

            if (name.startsWith('@/') || name === '@lucide/vue') {
                return {};
            }

            return require(name);
        },
    });
    const api = module.exports.default.setup(props, { expose() {} });

    return { api, props, page, requests, callbacks, cleanups };
};

test('activity drawer is shared by both pages, compiles and contains no mutation or note controls', () => {
    assert.deepEqual(
        compileTemplate({
            source: descriptor.template.content,
            id: 'activity',
            filename: 'InvoiceActivityDrawer.vue',
        }).errors,
        [],
    );

    for (const path of [
        'resources/js/pages/Billing/Invoice.vue',
        'resources/js/components/billing/BillingWorkspace.vue',
    ]) {
        assert.match(read(path), /InvoiceActivityDrawer/);
    }

    assert.match(source, /w-full gap-0 sm:max-w-\[480px\]/);
    assert.match(source, /SheetTitle[^>]*>Logs/);
    assert.match(source, /aria-label="Invoice activity"/);
    assert.match(source, /:aria-busy="loading"/);
    assert.match(source, /Load more/);
    assert.match(source, /Refresh/);
    assert.doesNotMatch(
        source,
        /router\.(post|put|patch|delete)|useForm|Add note|v-html/,
    );
});

test('opening loads 20-entry pages lazily, load more appends once and refresh replaces from the start', async () => {
    const h = harness();
    assert.equal(h.requests.length, 0);
    h.api.open.value = true;
    await flush();
    assert.match(h.requests[0].url, /expected_branch_id=12$/);
    await h.api.load();
    assert.equal(h.requests.length, 1, 'single-flight');
    h.requests[0].resolve({
        data: Array.from({ length: 20 }, (_, i) => row(String(i))),
        nextCursor: 'SYN-CURSOR',
    });
    await flush();
    const more = h.api.load(true);
    assert.match(h.requests[1].url, /cursor=SYN-CURSOR/);
    h.requests[1].resolve({ data: [row('19'), row('20')], nextCursor: null });
    await more;
    assert.equal(h.api.entries.value.length, 21);
    assert.equal(h.api.nextCursor.value, null);
    const refresh = h.api.load();
    assert.ok(!h.requests[2].url.includes('cursor'));
    h.requests[2].resolve({ data: [row('new')], nextCursor: null });
    await refresh;
    assert.deepEqual(
        Array.from(h.api.entries.value).map((r) => r.id),
        ['new'],
    );
});

test('empty and refused loads do not retain old financial entries', async () => {
    const h = harness();
    h.api.open.value = true;
    await flush();
    h.requests[0].resolve({ data: [], nextCursor: null });
    await flush();
    assert.equal(h.api.loaded.value, true);
    assert.equal(h.api.entries.value.length, 0);
    const reload = h.api.load();
    h.requests[1].resolve({ data: [row('old')], nextCursor: 'SYN' });
    await reload;
    const refused = h.api.load(true);
    h.requests[2].reject(new Error('Synthetic refused access'));
    await refused;
    assert.equal(h.api.entries.value.length, 0);
    assert.equal(h.api.nextCursor.value, null);
    assert.ok(h.api.error.value);
});

test('close, branch switch and navigation abort requests and late results cannot restore private data', async () => {
    const h = harness();
    h.api.open.value = true;
    await flush();
    h.api.open.value = false;
    await flush();
    assert.equal(h.requests[0].init.signal.aborted, true);
    h.api.open.value = true;
    await flush();
    h.requests[0].resolve({ data: [row('late')], nextCursor: null });
    await flush();
    assert.equal(h.api.entries.value.length, 0);
    h.callbacks.start();
    assert.equal(h.requests[1].init.signal.aborted, true);
    h.requests[1].resolve({ data: [row('late-branch')], nextCursor: null });
    await flush();
    assert.equal(h.api.open.value, false);
    assert.equal(h.api.entries.value.length, 0);
    h.page.props.branchContext.active.id = 13;
    await flush();
    assert.equal(h.api.blocked.value, true);
    h.props.context = { url: '/visits/OTHER/invoice/activity', branchId: 13 };
    await flush();
    assert.equal(h.api.blocked.value, false);
    h.cleanups.forEach((cb) => cb());
    assert.equal(h.callbacks.start, undefined);
});
