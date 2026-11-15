import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Inventory/SupplierInvoices.vue');
const page = read('resources/js/pages/Inventory/V2/SupplierInvoices.vue');
const registry = read('config/v2-pages.php');
const script = page.slice(0, page.indexOf('<template>'));
const template = page.slice(page.indexOf('<template>'));
const flat = (source) => source.replace(/\s+/g, ' ');
const tflat = flat(template);

/** The source of `const name = …` up to the next top-level statement, without whitespace. */
const block = (source, name) => {
    const start = source.search(
        new RegExp(`\\n[ \\t]*(?:const|type|function) ${name}\\b`),
    );
    assert.ok(start >= 0, `${name} not found`);
    const rest = source.slice(start + 1);
    const indent = /^[ \t]*/.exec(rest)[0];
    const next = rest
        .slice(indent.length)
        .search(
            new RegExp(
                `\\n${indent}(?:const |type |let |watch|function |//|/\\*\\*|return \\{|\\}\\n|<\\/script>)`,
            ),
        );

    return (next < 0 ? rest : rest.slice(0, next + indent.length))
        .replace(/\s+/g, '')
        .replace(/,(?=[)\]}])/g, '');
};

test('page 6: the Classic script is copied verbatim (types, labels, money, record and decide)', () => {
    for (const name of [
        'OrderLine',
        'Order',
        'InvoiceLine',
        'Invoice',
        'Tab',
        'props',
        'tabs',
        'statusLabel',
        'statusTone',
        'resultLabel',
        'basisLabel',
        'acceptReasons',
        'rejectReasons',
        'reasonLabel',
        'money',
        'rm',
        'difference',
        'errors',
        'busy',
        'visit',
        'form',
        'selectedOrder',
        'clientErrors',
        'fieldErrors',
        'checkRecordForm',
        'focusFirstError',
        'record',
        'decision',
        'reasonError',
        'noteError',
        'openDecision',
        'submitDecision',
        'decisionError',
    ]) {
        assert.equal(block(script, name), block(classic, name), name);
    }

    for (const path of [
        "'/inventory/supplier-invoices'",
        '`/inventory/supplier-invoices/${invoice.publicId}/${decision.kind}`',
    ]) {
        assert.ok(classic.includes(path) && page.includes(path), path);
    }
});

test('page 6: quantities read in plain words ("30 units"), not the Classic "30 unit"', () => {
    assert.ok(!script.includes('const quantity ='));
    assert.match(
        template,
        /quantityWithUnit\(\s*line\.invoicedQuantity,\s*line\.stockUnit,?\s*\)/,
    );
});

test('page 6: registered, uses the V2 blocks, and Classic does not import V2', () => {
    assert.match(registry, /'Inventory\/SupplierInvoices',/);

    for (const name of [
        'V2WorkLayout',
        'V2Table',
        'V2Tabs',
        'V2Dialog',
        'V2Chip',
        'V2Pager',
        'V2Picker',
        'V2RowMenu',
        'V2OptionGroup',
        'V2KeyValueList',
        'V2DisclosureBar',
        'V2MoneyInput',
        'V2DateField',
        'V2LeaveGuard',
    ]) {
        assert.ok(page.includes(`components/v2/${name}.vue`), name);
    }

    assert.ok(!classic.includes('components/v2'));
    // No native select or radio on the V2 page.
    assert.ok(!template.includes('<select'));
    assert.ok(!template.includes('type="radio"'));
});

test('page 6: no amount reaches the page without showAmounts', () => {
    // Every figure in the template sits inside a showAmounts block.
    const uses = [...template.matchAll(/money\(|difference\(/g)].map(
        (m) => m.index,
    );
    assert.ok(uses.length >= 3);

    for (const at of uses) {
        const before = template.slice(0, at);
        const opened = before.lastIndexOf('v-if="invoices.showAmounts"');
        assert.ok(opened >= 0, `figure at ${at} is not under showAmounts`);
        // The nearest enclosing element with the guard is still open (no closing tag of the same block between).
        const between = before.slice(opened);
        assert.ok(
            !/<\/(td|p)>/.test(between),
            `figure at ${at} is outside its showAmounts block`,
        );
    }

    // The sheet's total row only with showAmounts.
    assert.match(
        flat(block(script, 'decisionRows')),
        /props\.invoices\.showAmounts\?\[\{label:'Invoicetotal',value:money\(invoice\.totalSen\)\}\]:\[\]/,
    );
    // The total column only with showAmounts.
    assert.match(tflat, /<th v-if="invoices\.showAmounts"/);
});

test("page 6: Daniel's checklist (sentences, blocked reasons, own record, sheets, codes, guards)", () => {
    // Live record sentence names the order, invoice number and supplier; the button says why it is off.
    assert.match(
        script,
        /Records invoice \$\{unbroken\(form\.number\.trim\(\)\)\} for \$\{unbroken\(order\.number\)\} from \$\{order\.supplier\}/,
    );
    assert.match(script, /Enter the invoice number to continue\./);
    assert.match(tflat, /:disabled="busy \|\| missing !== null"/);
    assert.equal((template.match(/Record invoice/g) ?? []).length, 1);
    // On a phone the form sits closed after the invoices.
    assert.match(tflat, /class="max-sm:order-last"/);
    assert.match(tflat, /:default-open="wide"/);
    // RM in the box, not in the label.
    assert.match(tflat, /<V2MoneyInput[\s\S]*?label="Line total"/);
    assert.ok(!/label="[^"]*RM[^"]*"/.test(template));
    // Row menu order: Open, Check, Danger.
    const menu = flat(block(script, 'menuFor'));
    assert.ok(
        menu.indexOf("'Open'") < menu.indexOf("'Check'") &&
            menu.indexOf("'Check'") < menu.indexOf("'Danger'"),
    );
    // Own record: a line instead of a missing action.
    assert.match(
        flat(template),
        /You recorded this invoice, so a different Finance Officer or Director must decide it\./,
    );
    // Named actions, red Reject, Classic sentences beside the button, a reason before acting.
    assert.match(
        tflat,
        /:confirm-label="`\$\{decision\.kind === 'accepted' \? 'Accept' : 'Reject'\}/,
    );
    assert.match(tflat, /decision\.kind === 'rejected' \? 'danger'/);
    assert.match(tflat, /<template #footer-note>[\s\S]*?decisionSentence/);
    assert.match(script, /This cannot be undone\./);
    assert.match(
        tflat,
        /:confirm-disabled-reason="decisionBlocked \?\? undefined"/,
    );
    // View lines: outline Close.
    assert.match(tflat, /single-action-style="outline"/);
    // Chips: warning word for differences; status on one line.
    assert.match(tflat, /`\$\{differing\(invoice\)\} differs`/);
    assert.match(tflat, /class="w-56">Status/);

    // Codes unbroken; guards.
    for (const code of ['invoice.number', 'invoice.orderNumber', 'line.sku']) {
        assert.match(
            tflat,
            new RegExp(
                `unbroken\\(\\s*${code.replace('.', '\\.')}\\s*,?\\s*\\)`,
            ),
            code,
        );
    }

    assert.match(tflat, /<V2LeaveGuard :dirty="recordDirty" \/>/);
    assert.match(tflat, /:dirty="decision\.note\.trim\(\) !== ''"/);
    assert.equal((template.match(/cards="xl"/g) ?? []).length, 1);
});
