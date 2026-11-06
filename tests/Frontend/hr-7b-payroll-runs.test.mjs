import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-7b payroll runs (HR-PLAN section 16; approved by B.E.R.N.I.S.S for the owner, 2026-11-06).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    run: 'resources/js/pages/HR/PayrollRun.vue',
    tab: 'resources/js/components/hr/payroll/RunsTab.vue',
    page: 'resources/js/pages/HR/Payroll.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const types = read('resources/js/components/hr/payroll/types.ts');
const template = (key) => parse(source[key]).descriptor.template.content;

test('the run page and the Runs tab compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the run page carries the accountant line and shows money only with myr()', () => {
    assert.match(template('run'), /<AccountantNote \/>/);
    assert.match(source.run, /import \{ myr \} from '@\/types\/billing';/);
    assert.doesNotMatch(source.run, /toFixed\(|\/ 100\b|parseFloat\(/);
    assert.doesNotMatch(source.tab, /toFixed\(|\/ 100\b|parseFloat\(/);
});

test('the run table keeps the person in view from 1024 px, with cards below it', () => {
    assert.match(
        template('run'),
        /class="hidden overflow-x-auto[^"]*lg:block"/,
    );
    assert.match(template('run'), /scope="row"\s+class="sticky left-0/);
    assert.match(template('run'), /:aria-label="`Lines for \$\{p\.name\}`"/);
    assert.match(template('run'), /max-lg:min-h-11/);
});

test('each step is a confirmed POST and Lock run asks first', () => {
    assert.match(source.run, /router\.post\(/);
    assert.match(source.run, /OperationalConfirmDialog/);
    assert.match(source.run, /label: 'Lock run'/);
    assert.match(source.run, /You then lock it to finish\./);
    assert.match(source.run, /idempotency_key/);
    assert.match(source.run, /expected_lock_version/);
    assert.doesNotMatch(source.run, /\bfetch\(|axios/);
});

test('Payroll has a Runs tab, and Pay profiles stays the default', () => {
    assert.match(source.page, /RunsTab/);
    assert.match(types, /RunStatus/);
    assert.match(types, /runWords/);
});

test('no page in this slice sends messages or exports anything', () => {
    for (const key of Object.keys(files)) {
        assert.doesNotMatch(
            source[key],
            /download|\.csv|whatsapp|mailto:|sms:/i,
            files[key],
        );
    }
});
