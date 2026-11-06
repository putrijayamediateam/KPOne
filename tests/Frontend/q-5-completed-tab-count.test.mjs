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
const { completedShownLine } = module.exports;
const page = read('resources/js/pages/Queue/Index.vue');

test('the Completed tab line shows only when the count is bigger than the rows listed', () => {
    assert.equal(
        completedShownLine('removed', 25, 40),
        'Showing the latest 25 of 40',
    );
    assert.equal(completedShownLine('removed', 25, 25), null);
    assert.equal(completedShownLine('removed', 3, 3), null);
    assert.equal(completedShownLine('removed', 25, undefined), null);
});

test('no line on the other tabs, or while the Completed rows are still loading or failed to load', () => {
    for (const tab of ['all', 'waiting', 'serving']) {
        assert.equal(completedShownLine(tab, 25, 40), null);
    }

    // On the other tabs the snapshot carries no completed rows, so before the refresh lands the tab has 0 rows.
    assert.equal(completedShownLine('removed', 0, 40), null);
});

test('the board uses the helper with the live rows and the server count', () => {
    assert.match(
        page,
        /completedShownLine\(\s*activeTab\.value,\s*live\.value\.removed\.length,\s*live\.value\.tabCounts\?\.removed,?\s*\)/,
    );
    const line = page.match(/<p\s+v-if="completedLine"([\s\S]*?)<\/p>/);
    assert.ok(line);
    assert.match(line[1], /data-testid="completed-shown-count"/);
    assert.match(line[1], /text-xs text-muted-foreground/);
    // The tab count itself stays the server count of every completed entry.
    assert.match(
        page,
        /label: 'Consultation Completed',\s*count: live\.value\.tabCounts\?\.removed,/,
    );
});
