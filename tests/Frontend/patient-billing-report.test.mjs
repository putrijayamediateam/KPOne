import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { parse, compileTemplate } from '@vue/compiler-sfc';
import ts from 'typescript';
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Reports/BillingDetail.vue');
const chip = read('resources/js/components/reports/MultiSelectFilterChip.vue');

test('dedicated patient report compiles with explicit run, current generation, bounded mobile table and no clinical links', () => {
    for (const source of [page, chip]) {
        const { descriptor } = parse(source);
        assert.deepEqual(
            compileTemplate({
                source: descriptor.template.content,
                filename: 'Report.vue',
                id: 'patient-report',
            }).errors,
            [],
        );
        assert.doesNotMatch(source, /v-html|localStorage|sessionStorage/);
    }

    assert.match(page, /v-if="canExport"/);
    assert.match(page, /busy \|\| !data \|\| dirty/);
    assert.match(page, /Patient rows are not loaded\s+automatically/);
    assert.match(page, /data\.generatedAt/);
    assert.match(page, /later download can\s+differ/);
    assert.match(page, /max-w-full overflow-x-auto/);
    assert.match(page, /min-w-\[1200px\]/);
    assert.match(page, /flex flex-wrap/);
    assert.match(page, /watch\(\(\) => props\.branch\.id, clear\)/);
    assert.match(page, /request\?\.abort\(\)/);
    assert.match(page, /branchContext\?\.active\?\.id/);
    assert.match(page, /onBeforeUnmount/);
    assert.doesNotMatch(
        page,
        /href=.*patients|diagnosis|treatment|NRIC|passport|Cash \/ Non-cash/,
    );
    assert.match(chip, /PopoverContent/);
    assert.match(chip, /option\.label\s*\.toLocaleLowerCase/);
    assert.match(chip, /open\.value = false/);
    assert.match(chip, /emit\('update:modelValue'/);
});

test('dirty calculation covers date, time, multi-select and staged popover state', () => {
    const script = parse(page).descriptor.scriptSetup.content;
    const ast = ts.createSourceFile(
        'BillingDetail.ts',
        script,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TS,
    );
    let expr;
    const visit = (node) => {
        if (
            ts.isVariableDeclaration(node) &&
            node.name.getText(ast) === 'dirty'
        ) {
            expr = node.initializer.arguments[0].body.getText(ast);
        }

        ts.forEachChild(node, visit);
    };
    visit(ast);
    const original = {
        from: '2026-10-20',
        to: '2026-10-20',
        time_from: '00:00',
        time_to: '23:59',
        doctors: [],
        methods: [],
        visit_types: [],
        page: 1,
    };
    const refs = {
        draft: { value: structuredClone(original) },
        applied: { value: structuredClone(original) },
        pending: { value: {} },
        timeOpen: { value: false },
        times: { value: { from: '00:00', to: '23:59' } },
    };
    const dirty = () =>
        new Function(...Object.keys(refs), `return (${expr});`)(
            ...Object.values(refs),
        );
    assert.equal(dirty(), false);

    for (const [key, value] of Object.entries({
        from: '2026-10-19',
        to: '2026-10-21',
        time_from: '08:00',
        time_to: '18:00',
        doctors: ['1'],
        methods: ['2'],
        visit_types: ['otc'],
    })) {
        refs.draft.value = { ...structuredClone(original), [key]: value };
        assert.equal(dirty(), true, key);
    }

    refs.draft.value = structuredClone(original);
    refs.pending.value = { doctors: true };
    assert.equal(dirty(), true);
    refs.pending.value = {};
    refs.timeOpen.value = true;
    refs.times.value.to = '18:00';
    assert.equal(dirty(), true);
    refs.timeOpen.value = false;
    assert.equal(dirty(), false);
    refs.draft.value.page = 2;
    assert.equal(dirty(), false);
});

test('patient CSV routes use separate permission gates and the shared export limit', () => {
    const routes = read('routes/web.php');
    assert.match(
        routes,
        /reports\/billing-detail\/download[\s\S]*?permission:reports\.patient_billing\.view\.branch[\s\S]*?permission:reports\.patient_billing\.export\.branch[\s\S]*?throttle:reports-downloads/,
    );
    const controller = read(
        'app/Http/Controllers/PatientBillingReportController.php',
    );
    assert.match(controller, /no-store, private/);
    const service = read(
        'app/Domain/Visit/Billing/Services/PatientBillingReportService.php',
    );
    assert.match(service, /REPEATABLE READ/);
    assert.match(service, /getAllPermissions/);
    assert.match(service, /count > ReportCsv::MAX_ROWS/);
    assert.match(service, /ledger->state/);
    assert.doesNotMatch(service, /storage|putFile|dispatch\(|Mail::/);
});

test('generation is presented in the branch timezone and method codes remain CSV-only', () => {
    const javascript = ts.transpileModule(
        read('resources/js/lib/presentation.ts'),
        {
            compilerOptions: { target: ts.ScriptTarget.ES2022 },
        },
    ).outputText;
    const format = runInNewContext(
        `${javascript.replaceAll('export ', '')}\nformatDateTime`,
    );
    assert.equal(
        format('2026-12-31T16:10:00+00:00', 'Asia/Kuala_Lumpur'),
        '1 Jan 2027, 12:10 AM',
    );
    assert.equal(
        format('2026-12-31T16:10:00+00:00', 'UTC'),
        '31 Dec 2026, 4:10 PM',
    );
    assert.match(page, /formatDateTime\(data\.generatedAt, branch\.timezone\)/);
    assert.match(page, /:datetime="data\.generatedAt"/);
    assert.match(page, /\(\{\{ branch\.timezone \}\}\)/);
    assert.doesNotMatch(page, /method\.code|\(UTC\)/);
    const service = read(
        'app/Domain/Visit/Billing/Services/PatientBillingReportService.php',
    );
    assert.match(service, /'label' => \(string\) \$r->name/);
    assert.match(service, /'Generated at UTC', \$result\['generatedAt'\]/);
    assert.match(service, /\$method\['code'\]\.' · '\.\$method\['name'\]/);
});
