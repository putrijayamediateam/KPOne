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

test('the board wires the Serving Now line to the live serving rows and the server count', () => {
    assert.match(
        page,
        /servingShownLine\(\s*activeTab\.value,\s*live\.value\.serving\.length,\s*live\.value\.tabCounts\?\.serving,?\s*\)/,
    );
    const line = page.match(/<p\s+v-if="servingLine"([\s\S]*?)<\/p>/);
    assert.ok(line);
    assert.match(line[1], /data-testid="serving-shown-count"/);
    assert.match(line[1], /text-xs text-muted-foreground/);
    assert.match(
        page,
        /label: 'Serving Now',\s*count: live\.value\.tabCounts\?\.serving,/,
    );
});
