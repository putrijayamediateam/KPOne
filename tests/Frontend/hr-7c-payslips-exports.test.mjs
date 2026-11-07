import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-7c payslips and exports (HR-PLAN section 17; approved by B.E.R.N.I.S.S for the owner, 2026-11-07).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    payslip: 'resources/js/pages/HR/Payslip.vue',
    exports: 'resources/js/components/hr/payroll/ExportsTab.vue',
    records: 'resources/js/pages/HR/Records.vue',
    payroll: 'resources/js/pages/HR/Payroll.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('the payslip page, the Exports tab and the pages using them compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the payslip prints on A4, white in every theme, with no app layout', () => {
    assert.match(source.payslip, /@page \{\s*size: A4;\s*margin: 15mm;/);
    assert.match(template('payslip'), /class="document-print payslip/);
    assert.match(template('payslip'), /print:bg-white print:text-black/);
    assert.match(template('payslip'), /print:hidden/);
    assert.match(read('resources/js/app.ts'), /case name === 'HR\/Payslip':/);
});

test('the payslip shows net pay in bold, the bank masked, employer parts as information and the accountant line', () => {
    assert.match(template('payslip'), /text-xl font-bold tabular-nums/);
    assert.match(
        template('payslip'),
        /ending\s+\{\{ payslip\.bank\.last4 \}\}/,
    );
    // Only the last four reach the payslip; no full account number field exists on it.
    assert.doesNotMatch(
        source.payslip,
        /bank\.account\b|accountNumber|account_number/,
    );
    assert.match(template('payslip'), /Employer contributions \(information\)/);
    assert.match(
        template('payslip'),
        /To confirm with the clinic's accountant before\s+real use\. Synthetic data only\./,
    );
    assert.match(source.payslip, /import \{ myr \} from '@\/types\/billing';/);
    assert.doesNotMatch(source.payslip, /toFixed\(|\/ 100\b|parseFloat\(/);
});

test('My HR details lists your own payslips with no amount and links to each', () => {
    assert.match(template('records'), /My payslips/);
    assert.match(template('records'), /:href="`\/hr\/payslips\/\$\{p\.id\}`"/);
    assert.match(template('records'), /No payslips yet\./);
    const section = template('records').split('data-test="my-payslips"')[1];
    assert.doesNotMatch(section.split('</section>')[0], /myr\(|Sen\b/);
});

test('exports download through a recorded POST, never a stored file, and the bank file waits for bank details', () => {
    assert.match(source.exports, /method: 'POST'/);
    assert.match(source.exports, /'X-CSRF-TOKEN'/);
    assert.match(
        source.exports,
        /\/hr\/payroll\/runs\/\$\{run\.id\}\/export\/\$\{kind\}/,
    );
    assert.match(
        template('exports'),
        /:disabled="!!busy \|\| run\.missingBank\.length === run\.people"/,
    );
    assert.match(template('exports'), /pay\s+them\s+outside\s+the\s+file/);
    assert.match(template('exports'), /keep\s+their\s+leading\s+zeros/);
    assert.match(template('exports'), /Statutory summary for the portals/);
    assert.match(template('exports'), /KPOne files nothing\./);
    assert.match(template('payroll'), /<ExportsTab/);
    assert.match(source.payroll, /exports: 'Exports'/);
});

test('nothing in HR-7c sends a message', () => {
    for (const key of ['payslip', 'exports']) {
        assert.doesNotMatch(source[key], /whatsapp|mailto:|sms:/i, files[key]);
    }
});
