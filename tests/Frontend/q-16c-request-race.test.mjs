import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { effectScope, nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    'resources/js/components/queue/QueueRowDetailsDialog.vue',
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'q16c-race',
}).content;
const js = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;

const harness = () => {
    const pending = [];
    const module = { exports: {} };
    const scope = effectScope();
    const props = reactive({
        visitNumber: null,
        patientLabel: 'Synthetic patient',
    });
    runInNewContext(js, {
        exports: module.exports,
        require(name) {
            if (name === '@/lib/json-client') {
                return {
                    JsonRequestError: class extends Error {},
                    requestJson: (url) =>
                        new Promise((resolve, reject) =>
                            pending.push({ url, resolve, reject }),
                        ),
                };
            }

            if (name === 'vue') {
                return require('vue');
            }

            if (name.startsWith('@/') || name === '@lucide/vue') {
                return {};
            }

            return require(name);
        },
    });
    const api = scope.run(() =>
        module.exports.default.setup(props, { expose() {} }),
    );

    return { props, pending, api, stop: () => scope.stop() };
};
const settle = async () => {
    await Promise.resolve();
    await nextTick();
};

test('an earlier refused details request cannot hide the newly selected patient or end its loading state', async () => {
    const h = harness();

    try {
        h.props.visitNumber = 'KPV-OLD';
        await nextTick();
        h.props.visitNumber = 'KPV-NEW';
        await nextTick();
        h.pending[0].reject(new Error('old failure'));
        await settle();
        assert.equal(h.api.error.value, '');
        assert.equal(h.api.loading.value, true);
        h.pending[1].resolve({ details: { visitNumber: 'KPV-NEW' } });
        await settle();
        assert.equal(h.api.details.value.visitNumber, 'KPV-NEW');
        assert.equal(h.api.loading.value, false);
    } finally {
        h.stop();
    }
});

test('closing and reopening the same visit ignores the earlier response', async () => {
    const h = harness();

    try {
        h.props.visitNumber = 'KPV-SAME';
        await nextTick();
        h.props.visitNumber = null;
        await nextTick();
        assert.equal(h.api.loading.value, false);
        h.props.visitNumber = 'KPV-SAME';
        await nextTick();
        h.pending[1].resolve({
            details: { visitNumber: 'KPV-SAME', reason: 'Current' },
        });
        await settle();
        h.pending[0].resolve({
            details: { visitNumber: 'KPV-SAME', reason: 'Stale' },
        });
        await settle();
        assert.equal(h.api.details.value.reason, 'Current');
        assert.equal(h.api.error.value, '');
    } finally {
        h.stop();
    }
});
