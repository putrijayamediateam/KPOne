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
const source = readFileSync(new URL('../../resources/js/pages/Billing/Print.vue', import.meta.url), 'utf8');
const descriptor = parse(source).descriptor;
const compiled = ts.transpileModule(compileScript(descriptor, {
    id: 'd02-print', inlineTemplate: true,
}).content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const module = { exports: {} };
runInNewContext(compiled, {
    exports: module.exports,
    require: (name) => {
        if (name === '@inertiajs/vue3') {
return { Head: { render: () => null } };
}

        if (name === '@/components/billing/BillToRecipient.vue') {
return { default: { render: () => null } };
}

        if (name === '@/types/billing') {
return { myr: (sen) => `RM ${(sen / 100).toFixed(2)}` };
}

        return require(name);
    },
});
const propsFor = (kind, footerText) => ({
    billing: {
        patient: { name: 'Synthetic Patient', patientNumber: 'KP-SYNTHETIC' },
        visit: { visitNumber: 'VISIT-SYNTHETIC' },
        invoice: { number: 'KPI-00000001', status: 'finalized', finalizedAt: '2026-10-30',
            lines: [{ name: 'Synthetic consultation', quantity: '1.000', unit: 'consultation', unitPriceSen: 4000, totalSen: 4000 }],
            adjustment: { subtotalSen: 4000, adjustmentSen: 0 },
            state: { total: 4000, self_pay: 1000, panel: 0, deferred: 0, due_now: 3000 } },
    },
    receipt: kind === 'receipt' ? { number: 'KPR-00000001', status: 'posted', receivedAt: '2026-10-30', method: 'Cash', amountSen: 1000 } : null,
    letterhead: { details: { clinic_name: 'Synthetic Clinic', address_line_1: 'Synthetic address', phone: null, email: null }, logo: null, footerText },
});
const render = (props) => renderToString(createSSRApp({ render: () => h(module.exports.default, props) }));
const footer = /<p[^>]*class="document-footer[^>]*>[\s\S]*?<\/p>/;

for (const kind of ['invoice', 'receipt']) {
    test(`D02 ${kind}: configured footer follows all financial content and empty footer adds no wording`, async () => {
        const props = propsFor(kind, null);
        const snapshot = structuredClone(props);
        const empty = await render(props);
        assert.deepEqual(props, snapshot);
        assert.doesNotMatch(empty, /document-footer|Development \/|synthetic validation document|e-Invoice document/);
        const configured = await render(propsFor(kind, 'Synthetic approved footer'));
        assert.match(configured, /Synthetic approved footer/);
        assert.ok(configured.indexOf('document-footer') > configured.indexOf(kind === 'invoice' ? 'Due now' : 'Originating Invoice'));
        const visible = (html) => html.replace(/<!--[\s\S]*?-->/g, '');
        assert.equal(visible(configured.replace(footer, '')), visible(empty));
    });
    test(`D02 ${kind}: HTML passed directly to the renderer stays text`, async () => {
        const html = await render(propsFor(kind, '<img src=x onerror="alert(1)"> & <script>alert(2)</script>'));
        assert.match(html, /&lt;img src=x onerror=&quot;alert\(1\)&quot;&gt;/);
        assert.match(html, /&lt;script&gt;alert\(2\)&lt;\/script&gt;/);
        assert.doesNotMatch(html, /<img src=x|<script>/);
    });
    test(`D02 ${kind}: a long multiline footer is retained in full`, async () => {
        const long = 'Synthetic line wraps and is never truncated.\n'.repeat(22);
        assert.ok(long.length <= 1000);
        const html = await render(propsFor(kind, long));
        assert.ok(html.includes(long));
        assert.equal((html.match(/Synthetic line wraps and is never truncated\./g) ?? []).length, 22);
    });
}

test('D02 footer retains normal flow, wrapping and existing white A4 print rules without environment logic', () => {
    assert.match(source, /document-footer[^"\n]*break-words whitespace-pre-wrap/);
    assert.doesNotMatch(source, /line-clamp|text-ellipsis|truncate|position:\s*(?:fixed|absolute)|overflow:\s*hidden|import\.meta\.env|process\.env/);
    assert.match(descriptor.styles[0].content, /size: A4/);
    assert.match(descriptor.styles[0].content, /margin: 15mm/);
    assert.match(descriptor.styles[0].content, /background: white;\s*color: black;/);
    assert.match(descriptor.styles[0].content, /\.document-footer\s*\{\s*break-inside: avoid;/);
});
