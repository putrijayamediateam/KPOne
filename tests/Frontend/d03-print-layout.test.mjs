import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

const require = createRequire(import.meta.url);
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/Billing/Print.vue');
const descriptor = parse(source).descriptor;
const transpile = (code) => ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const money = { exports: {} };
runInNewContext(transpile(read('resources/js/types/billing.ts')), { exports: money.exports, require });
const compile = (path) => {
    const module = { exports: {} };
    const code = compileScript(parse(read(path)).descriptor, { id: path, inlineTemplate: true }).content;
    runInNewContext(transpile(code), {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return { Head: { render: () => null } };
            }

            if (name === '@/components/billing/BillToRecipient.vue') {
                return { default: compile('resources/js/components/billing/BillToRecipient.vue') };
            }

            if (name === '@/types/billing') {
                return money.exports;
            }

            return require(name);
        },
    });

    return module.exports.default;
};
const component = compile('resources/js/pages/Billing/Print.vue');
const propsFor = (kind, footerText = null) => ({
    billing: {
        patient: { name: 'Synthetic D03 Patient', patientNumber: 'KP-SYNTHETIC' },
        visit: { visitNumber: 'VISIT-SYNTHETIC' },
        invoice: { number: 'KPI-00000001', status: 'finalized', finalizedAt: '1 Jan 2027, 12:30 AM',
            lines: [{ type: 'consultation', name: 'Synthetic consultation', quantity: '1.000', unit: 'consultation', unitPriceSen: 4000, totalSen: 4000 }],
            adjustment: { subtotalSen: 4000, adjustmentSen: 0 },
            state: { total: 4000, self_pay: 2500, panel: 0, deferred: 0, due_now: 1500 } },
        payments: [{ number: 'KPR-00000001', amountSen: 1000 }, { number: 'KPR-00000002', amountSen: 1500 }],
    },
    receipt: kind === 'receipt' ? { number: 'KPR-00000001', status: 'posted', receivedAt: '1 Jan 2027, 12:35 AM', method: 'Synthetic saved method', amountSen: 1000 } : null,
    letterhead: { details: { clinic_name: 'Synthetic D03 Clinic', address_line_1: 'Synthetic address', phone: '+60300000000', email: 'clinic@d03.test' }, logo: null, footerText },
});
const render = (props) => renderToString(createSSRApp({ render: () => h(component, props) }));
const text = (html) => html.replace(/<!--[\s\S]*?-->/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

for (const kind of ['invoice', 'receipt']) {
    test(`D03 ${kind}: official title, To block, full number, original date and visible status`, async () => {
        const props = propsFor(kind);
        const html = await render(props);
        assert.match(html, new RegExp(`OFFICIAL ${kind.toUpperCase()}`));
        assert.match(html, /<h2 class="[^"]*text-center[^"]*font-bold[^"]*underline/);
        assert.match(html, /<h1 class="text-lg font-bold">/);
        assert.ok(html.indexOf('clinic@d03.test') < html.indexOf('Tel:'));
        const recipient = html.match(/<div[^>]*aria-label="Patient recipient"[^>]*>([\s\S]*?)<\/div>/)?.[1];
        assert.equal(text(recipient), 'To Synthetic D03 Patient KP-SYNTHETIC');
        assert.match(text(html), /VISIT-SYNTHETIC/);
        assert.ok(html.includes(kind === 'invoice' ? 'Invoice No:' : 'Receipt No:'));
        assert.ok(html.includes(kind === 'invoice' ? 'KPI-00000001' : 'KPR-00000001'));
        assert.ok(html.includes(kind === 'invoice' ? props.billing.invoice.finalizedAt : props.receipt.receivedAt));
        assert.match(html, /class="[^"]*text-right/);
        assert.match(text(html), new RegExp(`Status: ${kind === 'invoice' ? 'finalized' : 'posted'}`));
        assert.doesNotMatch(html, /Tax RM|Tax 0|Category|Package/);
    });
    test(`D03 ${kind}: escaped centred italic complete footer and no empty fallback`, async () => {
        const empty = await render(propsFor(kind));
        assert.doesNotMatch(empty, /document-footer|Development \/|synthetic validation document/);
        const long = '<b>Synthetic & footer</b>\n' + 'Complete synthetic line.\n'.repeat(35);
        const html = await render(propsFor(kind, long));
        assert.match(html, /class="document-footer[^"]*text-center[^"]*italic/);
        assert.match(html, /&lt;b&gt;Synthetic &amp; footer&lt;\/b&gt;/);
        assert.doesNotMatch(html, /<b>Synthetic/);
        assert.equal((html.match(/Complete synthetic line\./g) ?? []).length, 35);
        assert.ok(html.indexOf('document-footer') > html.indexOf(kind === 'invoice' ? 'Due now' : 'Originating Invoice'));
    });
    test(`D03 ${kind}: rendering leaves input payload untouched and ignores forbidden patient fields`, async () => {
        const props = propsFor(kind);
        Object.assign(props.billing.patient, { phone: 'PHONE_SENTINEL', identity: 'IDENTITY_SENTINEL', guardian: 'GUARDIAN_SENTINEL' });
        const before = structuredClone(props);
        const html = await render(props);
        assert.deepEqual(props, before);
        assert.doesNotMatch(html, /PHONE_SENTINEL|IDENTITY_SENTINEL|GUARDIAN_SENTINEL/);
    });
}

test('D03 invoice keeps every financial label and amount, and receipt has no item table or invoice totals', async () => {
    const invoice = await render(propsFor('invoice'));
    assert.match(text(invoice), /Item Qty Unit price \(RM\) Total \(RM\)/);
    assert.match(text(invoice), /Synthetic consultation 1\.000 consultation RM 40\.00 RM 40\.00/);

    for (const label of ['Original subtotal', 'Adjusted total', 'Due now', 'Panel responsibility', 'Approved pay later', 'Self-pay received']) {
        assert.ok(invoice.includes(label));
    }

    const receipt = await render(propsFor('receipt'));
    assert.doesNotMatch(receipt, /<table|Original subtotal|Adjusted total|Due now/);
});
test('D03 receipt sentence uses only its own original amount and saved method with exact reversal warning', async () => {
    const props = propsFor('receipt');
    const posted = text(await render(props));
    assert.match(posted, /Received for Synthetic D03 Patient the sum of RM 10\.00 via Synthetic saved method towards Invoice KPI-00000001\./);
    assert.doesNotMatch(posted, /RM RM|RM 25\.00|RM 40\.00/);
    props.receipt.status = 'reversed';
    const reversed = text(await render(props));
    assert.match(reversed, /Status: reversed/);
    assert.match(reversed, /Recording reversed — this is not a refund receipt\./);
    assert.match(reversed, /the sum of RM 10\.00/);
    const invoice = propsFor('invoice');
    invoice.billing.invoice.status = 'voided';
    assert.match(text(await render(invoice)), /Status: voided/);
});
test('D03 Bill to stays invoice-only and does not replace the patient To block', async () => {
    const billTo = { display_name: 'Synthetic separate recipient', party_type: 'company', company_name: 'Synthetic company', address_line_1: 'Synthetic recipient address' };
    const invoice = propsFor('invoice');
    invoice.billTo = billTo;
    const html = text(await render(invoice));
    assert.match(html, /To Patient: Synthetic D03 Patient KP-SYNTHETIC/);
    assert.match(html, /Bill to Synthetic separate recipient/);
    const receipt = propsFor('receipt');
    receipt.billTo = billTo;
    assert.doesNotMatch(await render(receipt), /Synthetic separate recipient|Synthetic recipient address/);
});
test('D03 discount and surcharge remain the existing signed adjustment cases', async () => {
    for (const [adjustment, label, sign, total] of [[-500, 'Discount', '−', 3500], [500, 'Surcharge', '+', 4500]]) {
        const props = propsFor('invoice');
        props.billing.invoice.adjustment.adjustmentSen = adjustment;
        props.billing.invoice.state.total = total;
        const html = text(await render(props));
        assert.ok(html.includes(`${label} ${sign}RM 5.00`));
        assert.ok(html.includes(`Adjusted total RM ${(total / 100).toFixed(2)}`));
    }

    assert.match(descriptor.styles[0].content, /size: A4;\s*margin: 15mm;/);
    assert.match(descriptor.styles[0].content, /background: white;\s*color: black;/);
    assert.doesNotMatch(source, /v-html|innerHTML|fetch\(|router\.|new Date|toLocaleDate|line-clamp|text-ellipsis/);
});
