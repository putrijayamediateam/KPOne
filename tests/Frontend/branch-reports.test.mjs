import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse, compileTemplate } from '@vue/compiler-sfc';
import ts from 'typescript';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Reports/BranchReport.vue');
test('branch report shell reuses staged chips, guarded runs, safe scrolling and branch-local generation', () => {
    const descriptor = parse(page).descriptor;
    assert.deepEqual(
        compileTemplate({
            source: descriptor.template.content,
            filename: 'Report.vue',
            id: 'report',
        }).errors,
        [],
    );
    assert.match(page, /MultiSelectFilterChip/);
    assert.match(page, /v-if="canExport"/);
    assert.match(page, /busy \|\| !data \|\| dirty/);
    assert.match(page, /max-w-full overflow-x-auto/);
    assert.match(page, /formatDateTime\(data.generatedAt, branch.timezone\)/);
    assert.match(page, /ownGeneration !== generation/);
    assert.match(page, /request\?\.abort/);
    assert.match(page, /branchContext\?\.active\?\.id/);
    assert.doesNotMatch(page, /v-html|localStorage|sessionStorage/);
});
test('dirty-state expression disables stale dates, applied multi-values and pending chips', () => {
    const script = parse(page).descriptor.scriptSetup.content;
    const ast = ts.createSourceFile(
        'Report.ts',
        script,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TS,
    );
    let expression;
    const visit = (node) => {
        if (
            ts.isVariableDeclaration(node) &&
            node.name.getText(ast) === 'dirty'
        ) {
            expression = node.initializer.arguments[0].body.getText(ast);
        }

        ts.forEachChild(node, visit);
    };
    visit(ast);
    const initial = {
        from: '2026-12-31',
        to: '2027-01-01',
        page: 1,
        methods: [],
        visit_types: [],
    };
    const draft = { value: structuredClone(initial) },
        applied = { value: structuredClone(initial) },
        pending = { value: {} };
    const dirty = () =>
        new Function('draft', 'applied', 'pending', `return (${expression});`)(
            draft,
            applied,
            pending,
        );
    assert.equal(dirty(), false);
    draft.value.from = '2026-12-30';
    assert.equal(dirty(), true);
    draft.value = structuredClone(initial);
    draft.value.methods = ['1'];
    assert.equal(dirty(), true);
    draft.value = structuredClone(initial);
    pending.value = { methods: true };
    assert.equal(dirty(), true);
    pending.value = {};
    draft.value.page = 2;
    assert.equal(dirty(), false);
});
test('daily summary labels distinct cohorts and uses existing financial projections', () => {
    const service = read(
        'app/Domain/Shared/Services/DailySummaryReportService.php',
    );
    assert.match(service, /branchReceiptRows/);
    assert.match(service, /FinancialLedger::class/);
    assert.match(service, /Total is net receipts only/);
    assert.match(
        service,
        /Payment-method filters apply to receipt columns only/,
    );
    const boundary = read('app/Domain/Shared/Services/BranchReportService.php');
    assert.match(boundary, /REPEATABLE READ/);
    assert.match(boundary, /ReportCsv::MAX_ROWS/);
    assert.match(boundary, /getAllPermissions/);
    assert.match(boundary, /AuditRecorder::class/);
    const routes = read('routes/web.php');
    assert.match(
        routes,
        /reports\/daily-summary\/download[^\n]*throttle:reports-downloads/,
    );
});
