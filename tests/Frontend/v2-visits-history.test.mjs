import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Visits/History.vue');
const page = read('resources/js/pages/Visits/V2/History.vue');
const money = read('resources/js/components/visits-v2/HistoryMoneyCard.vue');
const flat = (s) => s.replace(/\s+/g, ' ');
const tight = (s) => s.replace(/\s+/g, '');

// Every money expression of the Classic page, written the same way, in V2.
const moneyCalls = (s) =>
    [...flat(s).matchAll(/myr\(\s*([^()]*(?:\([^()]*\))?[^()]*)\)/g)]
        .map((m) => m[1].replace(/\s+/g, ''))
        .sort();

test('V2 history shows exactly the money values Classic shows, through myr()', () => {
    const a = moneyCalls(classic.split('</script>')[1]);
    const b = moneyCalls(
        page.split('</script>')[1] + money.split('</script>')[1],
    );

    assert.ok(a.length >= 5, 'expected the Classic money expressions');
    assert.deepEqual(b, a);
    assert.ok(
        !/[+\-*/]\s*(history\.financial|line\.|payment\.)/.test(
            flat(page.split('</script>')[1] + money.split('</script>')[1]),
        ),
        'no arithmetic on money in the template',
    );
});

test('V2 history keeps the Classic props, totals, labels and empty states', () => {
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
        assert.ok(flat(page + money).includes(piece), `V2 lacks ${piece}`);
    }
});

test('V2 history keeps the Documents print buttons: same component, urls, labels and no filled variant', () => {
    const a = tight(classic);
    const b = tight(page);

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
        assert.ok(b.includes(tight(piece)), `V2 lacks ${piece}`);
    }

    assert.ok(!b.includes('bg-primary'), 'no filled action');
    assert.ok(!b.includes('Staff #'), 'no new name fields');
    assert.equal((b.match(/<IssuePrintButton/g) ?? []).length, 2);
});

test('V2 history posts nothing and adds no request', () => {
    assert.ok(!/router\.(post|put|patch|delete)|useForm|fetch\(/.test(page));
    assert.ok(/rail-from="xl"/.test(page));
    assert.ok(/rail-below="hide"/.test(page));
    assert.ok(/xl:hidden/.test(page), 'the inline money card hides at xl');
    assert.equal((page.match(/<HistoryMoneyCard/g) ?? []).length, 2);
    assert.ok(
        /tabular-nums/.test(flat(money)) &&
            /whitespace-nowrap/.test(flat(money)),
    );
});

test('V2 money card keeps the Classic row order and wording, and never relabels Due now', () => {
    const m = flat(money);
    const order = [
        "['Total', state.total]",
        "['Self-pay received', state.self_pay]",
        "['Panel responsibility', state.panel]",
        "['Pay later still owed', state.deferred]",
    ].map((piece) => m.indexOf(piece));

    assert.ok(order.every((at) => at >= 0));
    assert.deepEqual(
        [...order].sort((a, b) => a - b),
        order,
    );
    assert.ok(m.indexOf('Due now') > order[3]);
    assert.ok(!/Balance|Outstanding/.test(money));
    // The empty-state conditions and texts are Classic's, word for word.
    assert.ok(
        m.includes(
            "history.canSeeFinancial ? 'No invoice was issued for this visit.' : 'Billing details are not shown for your role.'",
        ),
    );
    assert.ok(
        flat(classic).includes(
            "history.canSeeFinancial ? 'No invoice was issued for this visit.' : 'Billing details are not shown for your role.'",
        ),
    );
});

test('V2 money card shows method, number and amount only, and codes never break', () => {
    const m = flat(money);

    assert.ok(
        !/refundedSen|payment\.status|adjustment|refundCreditSen/.test(money),
    );
    assert.ok(m.includes('{{ payment.method }} · {{ payment.receiptNumber }}'));
    assert.ok(
        /whitespace-nowrap">\s*\{\{ payment\.method \}\}/.test(m) ||
            /whitespace-nowrap"\s*>\{\{ payment\.method/.test(m),
    );
    assert.ok(
        /whitespace-nowrap[^>]*>\{\{\s*history\.financial\.invoiceNumber/.test(
            m,
        ),
    );
});

test('V2 Documents keeps the PCA-1 branch: the invoice button follows printUrl and a Panel visit says why', () => {
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
        assert.ok(tight(page).includes(tight(piece)), `V2 lacks ${piece}`);
    }

    // V2 reads the same two conditions through one computed.
    assert.ok(
        tight(page).includes(
            tight(
                '!!props.history.financial?.printUrl || !!props.history.financial?.payments.some((p) => p.printUrl)',
            ),
        ),
    );
});
