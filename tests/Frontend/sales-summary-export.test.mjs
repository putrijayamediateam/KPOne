import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse, compileTemplate } from '@vue/compiler-sfc';
import ts from 'typescript';
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Insights/Report.vue');

test('summary-only action compiles, is permission-filtered and wraps at mobile widths', () => {
    const { descriptor } = parse(page);
    assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'Report.vue', id: 'report' }).errors, []);
    assert.match(page, /section === 'sales' && summaryDownloadUrl/);
    assert.match(page, /Aggregate summary only/);
    assert.match(page.replace(/\s+/g, ' '), /Tables are not included/);
    assert.match(page, /:href="summaryDownloadUrl"/);
    assert.match(page, /w-full sm:w-auto/);
    assert.match(page, /v-else disabled/);
    assert.doesNotMatch(page, /localStorage|sessionStorage|v-html/);
});

test('download cannot silently use unapplied branch, date or doctor controls', () => {
    const script = parse(page).descriptor.scriptSetup.content;
    const ast = ts.createSourceFile('Report.ts', script, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
    let expression;
    const visit = node => {
        if (ts.isVariableDeclaration(node) && node.name.getText(ast) === 'exportDirty') {
            expression = node.initializer.arguments[0].body.getText(ast);
        }

        ts.forEachChild(node, visit);
    };
    visit(ast);
    assert.ok(expression);

    const refs = { selectedBranch: {value:'all'}, from: {value:'2026-10-01'}, to: {value:'2026-10-01'}, doctor: {value:''} };
    const props = {filters:{branch:'all',from:'2026-10-01',to:'2026-10-01',doctor:null}};
    const dirty = () => new Function('props', ...Object.keys(refs), `return (${expression});`)(props,...Object.values(refs));
    assert.equal(dirty(),false);

    for (const key of Object.keys(refs)) {
        const old=refs[key].value; refs[key].value='different'; assert.equal(dirty(),true); refs[key].value=old;
    }
});
