import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

// BIL-9 (owner decisions 2026-11-06): the issued invoice and receipt in the clinic's paper layout. Synthetic data only.
const require = createRequire(import.meta.url);
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transpile = (code) =>
    ts.transpileModule(code, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const money = { exports: {} };
runInNewContext(transpile(read('resources/js/types/billing.ts')), {
    exports: money.exports,
    require,
});
const compile = (path) => {
    const module = { exports: {} };
    const code = compileScript(parse(read(path)).descriptor, {
        id: path,
        inlineTemplate: true,
    }).content;
    runInNewContext(transpile(code), {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return { Head: { render: () => null } };
            }

            if (name.startsWith('@/components/billing/')) {
                return {
                    default: compile(
                        `resources/js/components/billing/${name.slice('@/components/billing/'.length)}`,
                    ),
                };
            }

            if (name === '@/types/billing') {
                return money.exports;
            }

            return require(name);
        },
    });

    return module.exports.default;
};
const page = compile('resources/js/pages/Billing/Print.vue');
const text = (html) =>
    html
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
const categories = [
    ['Service', 0],
    ['Procedure', 2000],
    ['Investigation', 0],
    ['Consultation', 4000],
    ['Medicine', 1250],
    ['Document', 0],
    ['Package', 0],
].map(([name, totalSen]) => ({ name, totalSen }));
const props = (overrides = {}) => ({
    layout: 'bil9',
    billing: {
        patient: {
            name: 'Synthetic Patient',
            patientNumber: 'KP-SYN',
            identifier: '900101-14-5678',
            phone: '+60123456789',
        },
        visit: { visitNumber: 'VISIT-SYN' },
        invoice: {
            number: 'KPI-00000009',
            status: 'finalized',
            finalizedAt: '30 Oct 2026, 10:53 AM',
            lines: [
                {
                    name: 'Consultation 8AM - 5PM',
                    quantity: '1.000',
                    unit: 'visit',
                    unitPriceSen: 4000,
                    totalSen: 4000,
                    category: 'Consultation',
                },
                {
                    name: 'Synthetic tablet 500 mg',
                    quantity: '10.000',
                    unit: 'tab',
                    unitPriceSen: 125,
                    totalSen: 1250,
                    category: 'Medicine',
                },
                {
                    name: 'Synthetic dressing',
                    quantity: '1.000',
                    unit: 'service',
                    unitPriceSen: 2000,
                    totalSen: 2000,
                    category: 'Procedure',
                },
            ],
            categories,
            adjustment: { subtotalSen: 7250, adjustmentSen: -500 },
            state: {
                total: 6750,
                self_pay: 2000,
                panel: 3000,
                deferred: 0,
                due_now: 1750,
            },
        },
    },
    receipt: null,
    billTo: null,
    letterhead: {
        details: {
            clinic_name: 'Synthetic Clinic',
            address_line_1: '1 Jalan Sintetik',
            postcode: '43000',
            city: 'Kajang',
            state: 'Selangor',
            country: 'Malaysia',
            email: 'clinic@bil9.test',
            phone: '03-1111 1111',
            phone_secondary: '012-222 2222',
        },
        logo: null,
        footerText:
            'This is a computer-generated document. No signature is required.',
    },
    issued: {
        label: 'original',
        recordedLabel: 'original',
        version: 1,
        issuedAt: '30 Oct 2026, 10:53 AM',
        printedAt: '30 Oct 2026, 10:53 AM',
        printedByYou: true,
        notes: [],
        backUrl: '/billing',
        backLabel: 'Back to billing',
    },
    ...overrides,
});
const render = async (p) =>
    text(await renderToString(createSSRApp({ render: () => h(page, p) })));

test('the invoice follows the clinic paper: letterhead, To block, items, categories and totals', async () => {
    const out = await render(props());

    for (const part of [
        'Synthetic Clinic',
        'Email: clinic@bil9.test',
        'Tel: 03-1111 1111 / 012-222 2222',
        'OFFICIAL INVOICE',
        'ORIGINAL',
        'To Synthetic Patient 900101-14-5678 +60123456789',
        'Invoice No: KPI-00000009',
        'Date: 30 Oct 2026, 10:53 AM',
        'Item Qty Unit Price (RM) Total Price (RM)',
        'Consultation 8AM - 5PM 1 40.00 40.00',
        'Synthetic tablet 500 mg 10 1.25 12.50',
        'Synthetic dressing 1 20.00 20.00',
        'Category Total Amount (RM) Service 0.00 Procedure 20.00 Investigation 0.00 Consultation 40.00 Medicine 12.50 Document 0.00 Package 0.00',
        'Subtotal RM 72.50 Discount −RM 5.00 Tax RM 0.00 Invoice total RM 67.50 Total paid RM 20.00 Panel covers RM 30.00 Total due RM 17.50',
        'This is a computer-generated document. No signature is required.',
    ]) {
        assert.ok(out.includes(part), `${part}\n---\n${out}`);
    }

    assert.ok(!out.includes('Pay later'), 'Pay later is hidden at zero');
    assert.ok(!out.includes('VOID'));
    assert.ok(!out.includes('VISIT-SYN'), 'no visit number on the paper');
    assert.ok(!out.includes('KP-SYN'), 'no KP number in the To block');
    assert.ok(!out.includes('Status:'));
});

test('missing IC, phone and second phone leave their lines out, and a void invoice says VOID', async () => {
    const base = props();
    const out = await render({
        ...base,
        billing: {
            ...base.billing,
            patient: { ...base.billing.patient, identifier: null, phone: null },
            invoice: { ...base.billing.invoice, status: 'voided' },
        },
        letterhead: {
            ...base.letterhead,
            details: { ...base.letterhead.details, phone_secondary: null },
        },
    });
    assert.ok(out.includes('To Synthetic Patient Invoice No:'), out);
    assert.ok(out.includes('Tel: 03-1111 1111 ') && !out.includes(' / '), out);
    assert.ok(out.includes('OFFICIAL INVOICE VOID ORIGINAL'), out);
    assert.ok(!out.includes('Total due'), 'nothing is due on a void invoice');
    assert.ok(out.includes('Invoice total RM 67.50 Total paid RM 20.00'), out);
});

test('the receipt has its sentence, item table, invoice number and its own Total paid, with no category table', async () => {
    const out = await render(
        props({
            receipt: {
                number: 'KPR-00000004',
                receivedAt: '30 Oct 2026, 11:02 AM',
                status: 'posted',
                amountSen: 2000,
                method: 'Synthetic cash',
            },
        }),
    );

    for (const part of [
        'OFFICIAL RECEIPT',
        'Receipt No: KPR-00000004 Date: 30 Oct 2026, 11:02 AM Invoice No: KPI-00000009',
        'Received from Synthetic Patient the sum of RM 20.00 by Synthetic cash being payment for the treatment charges.',
        'Item Qty Unit Price (RM) Total Price (RM)',
        'Subtotal RM 72.50 Discount −RM 5.00 Tax RM 0.00 Total paid RM 20.00',
    ]) {
        assert.ok(out.includes(part), `${part}\n---\n${out}`);
    }

    assert.ok(!out.includes('Category'));
    assert.ok(!out.includes('Total due'));
    assert.ok(!out.includes('Invoice total'));
});

test('an earlier version without the bil9 layout still renders the D03 paper', async () => {
    const out = await render({ ...props(), layout: undefined });
    assert.ok(out.includes('Original subtotal'), out);
    assert.ok(out.includes('KP-SYN'));
});

test('the page footer numbers pages 2 onward with the document number, and never a one-page document', async () => {
    // Server rendering escapes the quotes in the <style> text; the browser keeps them as they are.
    const html = (
        await renderToString(createSSRApp({ render: () => h(page, props()) }))
    ).replaceAll('&quot;', '"');
    assert.ok(
        html.includes(
            '@page { @bottom-center { content: "KPI-00000009 \\00B7  Page " counter(page) " of " counter(pages); font-size: 8pt; } } @page :first { @bottom-center { content: none; } }',
        ),
        html,
    );
    const receipt = await renderToString(
        createSSRApp({
            render: () =>
                h(
                    page,
                    props({
                        receipt: {
                            number: 'KPR-00000004',
                            receivedAt: '30 Oct 2026, 11:02 AM',
                            status: 'posted',
                            amountSen: 2000,
                            method: 'Synthetic cash',
                        },
                    }),
                ),
        }),
    );
    assert.ok(
        receipt
            .replaceAll('&quot;', '"')
            .includes('content: "KPR-00000004 \\00B7  Page "'),
    );
});

test('money: thousands separators and a true minus sign', () => {
    assert.equal(money.exports.myr(123456789), 'RM 1,234,567.89');
    assert.equal(money.exports.myr(-150), '−RM 1.50');
    assert.equal(money.exports.myr(-50), '−RM 0.50');
    assert.equal(money.exports.amount(-1200), '−12.00');
    assert.equal(money.exports.amount(0), '0.00');
});

test('an invoice with an approved refund credit shows it after Tax and before Invoice total (BIL-10)', async () => {
    const base = props();
    const out = await render({
        ...base,
        billing: {
            ...base.billing,
            invoice: {
                ...base.billing.invoice,
                refundCreditSen: 500,
                state: {
                    ...base.billing.invoice.state,
                    total: 6250,
                    self_pay: 1500,
                    due_now: 1750,
                },
            },
        },
    });
    assert.ok(
        out.includes(
            'Tax RM 0.00 Refund credit −RM 5.00 Invoice total RM 62.50 Total paid RM 15.00',
        ),
        out,
    );
    const plain = await render(props());
    assert.ok(!plain.includes('Refund credit'));
});
