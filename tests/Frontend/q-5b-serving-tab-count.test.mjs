import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const javascript = ts.transpileModule(
    read('resources/js/lib/completed-tab.ts'),
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const module = { exports: {} };
new Function('module', 'exports', javascript)(module, module.exports);
const { servingShownLine, completedShownLine } = module.exports;
const page = read('resources/js/pages/Queue/Index.vue');

test('the Serving Now line shows only when the count is bigger than the rows listed', () => {
    assert.equal(
        servingShownLine('serving', 25, 31),
        'Showing the latest 25 of 31',
    );
    assert.equal(servingShownLine('serving', 25, 25), null);
    assert.equal(servingShownLine('serving', 2, 2), null);
    assert.equal(servingShownLine('serving', 25, undefined), null);
});

test('no Serving Now line on other tabs or while its rows are still loading', () => {
    for (const tab of ['all', 'waiting', 'removed']) {
        assert.equal(servingShownLine(tab, 25, 31), null);
    }

    assert.equal(servingShownLine('serving', 0, 31), null);
    // The two tabs never show each other's line.
    assert.equal(completedShownLine('serving', 25, 31), null);
});

test('Seeing now uses the independent not-held count and paginated workload rows', () => {
    assert.match(
        page,
        /live\.value\.scope === 'own' \? 'Seeing now' : 'Serving now'/,
    );
    assert.match(page, /count: counts\?\.seeing/);
    assert.match(
        page,
        /workload\.page\.value\?\.section === workloadSection\.value/,
    );
    assert.match(page, /workload\.page\.value\.data/);
    assert.doesNotMatch(page, /servingShownLine\(/);
});
