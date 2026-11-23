// Classic only, CO-5: the Classic half of v2-visits-history.test.mjs, kept when its V2 page was removed: every statement that
// read a deleted V2 file was removed and the remaining assertions are unchanged; tests are retitled for Classic.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Visits/History.vue');
const flat = (s) => s.replace(/\s+/g, ' ');
const tight = (s) => s.replace(/\s+/g, '');
const moneyCalls = (s) =>
    [...flat(s).matchAll(/myr\(\s*([^()]*(?:\([^()]*\))?[^()]*)\)/g)]
        .map((m) => m[1].replace(/\s+/g, ''))
        .sort();

test('Classic visit history shows money through myr()', () => {
    const a = moneyCalls(classic.split('</script>')[1]);
    assert.ok(a.length >= 5, 'expected the Classic money expressions');
});

test('Classic visit history keeps its props, totals, labels and empty states', () => {
    for (const piece of [
        'history: VisitHistoryPage',
        "['Total', state.total]",
        "['Self-pay received', state.self_pay]",
        "['Panel responsibility', state.panel]",
        "['Pay later still owed', state.deferred]",
        'history.financial.state.due_now',
        'Due now',
        'Totals only for your access.',
        'No invoice was issued for this visit.',
        'Billing details are not shown for your role.',
        'No printable documents for your access.',
        'Completed · read only',
        'history.canSeeFinancial',
    ]) {
        assert.ok(flat(classic).includes(piece), `Classic lacks ${piece}`);
    }
});

test('Classic visit history keeps its Documents print buttons', () => {
    const a = tight(classic);

    for (const piece of [
        '<IssuePrintButton',
        ':url="issueUrl(history.financial.printUrl)"',
        ':label="`Print invoice ${history.financial.invoiceNumber}`"',
        ':url="issueUrl(payment.printUrl)"',
        ':label="`Print receipt ${payment.receiptNumber}`"',
        'variant="ghost"',
        'return-to="history"',
        "url.replace(/\\/print$/, '/issue')",
    ]) {
        assert.ok(a.includes(tight(piece)), `Classic lacks ${piece}`);
    }
});

test('Classic visit history keeps its money row order and wording', () => {
    assert.ok(
        flat(classic).includes(
            "history.canSeeFinancial ? 'No invoice was issued for this visit.' : 'Billing details are not shown for your role.'",
        ),
    );
});

test('Classic visit history keeps the PCA-1 invoice print branch', () => {
    for (const piece of [
        'history.financial?.panelNoInvoicePrint',
        "'Panel patient: no invoice is printed. A receipt prints only for cash the patient paid.'",
        "'No printable documents for your access.'",
        '<li v-if="history.financial?.printUrl">',
    ]) {
        assert.ok(
            tight(classic).includes(tight(piece)),
            `Classic lacks ${piece}`,
        );
    }
});
