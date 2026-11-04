import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as Vue from 'vue';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const compile = (source) => ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const pageTabs = (page, counts) => {
    const source = parse(read(`resources/js/pages/${page}/Index.vue`)).descriptor.scriptSetup.content;
    const ast = ts.createSourceFile('page.ts', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
    const declaration = ast.statements.find((node) => ts.isVariableStatement(node) && node.declarationList.declarations.some((entry) => entry.name.getText(ast) === 'tabs'));
    assert.ok(declaration, 'page must expose its actual tab declarations');
    const ref = Vue.ref(counts);
    const tabs = Function('computed', 'tabCounts', 'live', `${compile(declaration.getText(ast))}\nreturn tabs;`)(Vue.computed, ref, Vue.computed(() => ({ tabCounts: ref.value })));

    return { tabs, counts: ref };
};

test('TAB-COUNTS-1: every Registration badge uses its aggregate including zero, without inventing restricted counts', () => {
    const declaration = parse(read('resources/js/pages/Registration/Index.vue')).descriptor.scriptSetup.content;
    const ast = ts.createSourceFile('page.ts', declaration, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
    const statement = ast.statements.find((node) => ts.isVariableStatement(node) && node.declarationList.declarations.some((entry) => entry.name.getText(ast) === 'tabs'));
    const counts = Vue.ref({ all: 20, waiting: 3, serving: 2, dispensary: 4, completed: 6, cancelled: 0 });
    const props = { qrIntakes: { pendingCount: 7 } };
    const tabs = Function('computed', 'tabCounts', 'props', `${compile(statement.getText(ast))}\nreturn tabs;`)(Vue.computed, counts, props);
    const byTab = () => Object.fromEntries(tabs.value.map((tab) => [tab.value, tab.count]));
    assert.deepEqual(byTab(), { ...counts.value, billing: undefined, 'qr-intake': 7 });
    counts.value = { ...counts.value, waiting: 5, billing: 1 };
    assert.equal(byTab().waiting, 5);
    assert.equal(byTab().billing, 1);
    assert.equal(byTab()['qr-intake'], 7, 'QR Intake retains its independent prop');
});

test('TAB-COUNTS-1: Consultation badges use full aggregate totals rather than capped section lengths', () => {
    const { tabs, counts } = pageTabs('Queue', { all: 71, waiting: 40, serving: 31, removed: 29 });
    assert.deepEqual(tabs.value.map((tab) => [tab.value, tab.count]), [['all', 71], ['waiting', 40], ['serving', 31], ['removed', 29]]);
    counts.value = { all: 70, waiting: 39, serving: 31, removed: 30 };
    assert.deepEqual(tabs.value.map((tab) => tab.count), [70, 39, 31, 30]);
});

test('TAB-COUNTS-1: the actual shared badge renders zero and omits an unavailable count', async () => {
    const path = 'resources/js/components/ui/tabs/OperationalTabs.vue';
    const descriptor = parse(read(path), { filename: path }).descriptor;
    const module = { exports: {} };
    Function('require', 'module', 'exports', compile(compileScript(descriptor, { id: 'tab-counts', inlineTemplate: true }).content))((name) => {
        assert.equal(name, 'vue');

        return Vue;
    }, module, module.exports);
    const html = await renderToString(Vue.createSSRApp(module.exports.default, { modelValue: 'waiting', tabs: [{ value: 'waiting', label: 'Waiting', count: 0 }, { value: 'billing', label: 'Awaiting Billing' }] }));
    assert.match(html, /Waiting\s*<span[^>]*>0<\/span>/);
    assert.doesNotMatch(html.split('Awaiting Billing')[1], /<span/);
});

test('TAB-COUNTS-1: Registration accepts counts with the guarded response and clears them only with manual results', () => {
    const source = read('resources/js/pages/Registration/Index.vue');
    const accept = source.indexOf('tabCounts.value = payload.tabCounts');
    const rows = source.indexOf('rows.value = payload.data');
    assert.ok(accept > source.indexOf('if (generation !== searchGeneration)'));
    assert.ok(accept > source.indexOf('if (automatic && pollProtected())'));
    assert.ok(accept < rows);
    assert.match(source, /const clearBoard = \(\) => \{\s*tabCounts.value = undefined;/);
    const queue = read('resources/js/lib/queue-polling.ts');
    const module = { exports: {} };
    Function('module', 'exports', compile(queue))(module, module.exports);
    const merged = module.exports.mergeQueuePollSnapshot({ branch: { id: 1 }, tabCounts: { waiting: 5 } }, { tabCounts: { waiting: 4 } });
    assert.deepEqual(merged.tabCounts, { waiting: 4 });
    assert.deepEqual(merged.branch, { id: 1 });
});
