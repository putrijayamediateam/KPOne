import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse, compileTemplate } from '@vue/compiler-sfc';
import ts from 'typescript';
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Insights/Report.vue');

test('daily aggregate actions compile with permission URLs, bounded mobile layout and separate bases', () => {
    const { descriptor } = parse(page);
    assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'Report.vue', id: 'report' }).errors, []);
    assert.match(page, /v-if="dailyDownloadUrl"/);
    assert.match(page, /Download daily CSV/);
    assert.match(page, /Download receipts by payment method CSV/);
    assert.match(page, /:href="dailyDownloadUrl"/);
    assert.match(page, /v-if="!exportDirty"/);
    assert.match(page, /v-else disabled class="w-full sm:w-auto"/);
    assert.match(page, /overflow-x-auto/);
    assert.match(page, /Receipt date, not invoice date/);
    assert.match(page, /not refund-date cash flow/);
    assert.match(page, /report\.value\.dailyReceipts\.filter/);
    assert.match(page, /No receipts/);

    for (const field of ['receiptsSen','reversedSen','netSen']) {
assert.match(page,new RegExp(`money\\(row\\.${field}\\)`));
}

    assert.doesNotMatch(page, /v-html|localStorage|sessionStorage/);
    const controller=read('app/Http/Controllers/ClinicInsightsController.php');
    assert.match(controller,/DailyReportExportService::PAYMENTS_PERMISSION/);
    assert.match(controller,/'dailyDownloadUrl'.*\$actor->can/);
    assert.match(controller,/'from' => \$from, 'to' => \$to/);
});

test('both downloads use the real dirty expression for branch, dates and doctor filters', () => {
    const script = parse(page).descriptor.scriptSetup.content;
    const ast = ts.createSourceFile('Report.ts',script,ts.ScriptTarget.Latest,true,ts.ScriptKind.TS);
    let expression;
    const visit = node => {
        if(ts.isVariableDeclaration(node) && node.name.getText(ast)==='exportDirty') {
expression=node.initializer.arguments[0].body.getText(ast);
}

        ts.forEachChild(node,visit);
    };
    visit(ast);
    const refs={selectedBranch:{value:'all'},from:{value:'2026-12-31'},to:{value:'2027-01-02'},doctor:{value:''}};
    const props={filters:{branch:'all',from:'2026-12-31',to:'2027-01-02',doctor:null}};
    const dirty=()=>new Function('props',...Object.keys(refs),`return (${expression});`)(props,...Object.values(refs));
    assert.equal(dirty(),false);

    for(const key of Object.keys(refs)){
const old=refs[key].value;refs[key].value='different';assert.equal(dirty(),true);refs[key].value=old;
}

    assert.equal(dirty(),false);
});


test('receipt display hides only all-zero rows and the sales trend exposes daily distinct counts', () => {
    const script = parse(page).descriptor.scriptSetup.content;
    const ast = ts.createSourceFile('Report.ts', script, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
    const bodies = {};
    let nounBody;
    const visit = node => {
        if (ts.isVariableDeclaration(node) && node.name.getText(ast) === 'patientNoun') {
            nounBody = node.initializer.body.getText(ast);
        }

        if (ts.isVariableDeclaration(node) && ['visibleReceiptRows', 'chartPoints'].includes(node.name.getText(ast))) {
            bodies[node.name.getText(ast)] = node.initializer.arguments[0].body.getText(ast);
        }

        ts.forEachChild(node, visit);
    };
    visit(ast);
    const rows = [
        {receiptsSen: 0, reversedSen: 0, netSen: 0},
        {receiptsSen: 100, reversedSen: 0, netSen: 100},
        {receiptsSen: 100, reversedSen: 100, netSen: 0},
        {receiptsSen: 0, reversedSen: 0, netSen: 1},
    ];
    const original = structuredClone(rows);
    const visible = new Function('report', `return (${bodies.visibleReceiptRows});`)({value:{kind:'payments',dailyReceipts:rows}});
    assert.deepEqual(visible,rows.slice(1));
    assert.deepEqual(rows,original);
    const noun = new Function('count', `return (${nounBody});`);
    assert.equal(noun(0), 'patients');
    assert.equal(noun(1), 'patient');
    assert.equal(noun(2), 'patients');
    const points = new Function('report','money','patientNoun', bodies.chartPoints.slice(1,-1))({value:{kind:'sales',dailyTrend:[{date:'2026-12-31',salesSen:1234,patients:2}]}}, value=>String(value), noun);
    assert.equal(points[0].patients,2);
    assert.match(points[0].detail,/2 distinct patients/);
    const singular = new Function('report','money','patientNoun', bodies.chartPoints.slice(1,-1))({value:{kind:'sales',dailyTrend:[{date:'2026-12-31',salesSen:1234,patients:1}]}}, value=>String(value), noun);
    assert.match(singular[0].detail, /1 distinct patient$/);
    assert.match(page.replace(/\s+/g, ' '),/point.patients }} {{ patientNoun\(point.patients\) }}/);
    assert.match(page,/Daily counts need not sum/);
});
