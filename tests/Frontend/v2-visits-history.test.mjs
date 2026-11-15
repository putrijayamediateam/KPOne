import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Visits/History.vue');
const page = read('resources/js/pages/Visits/V2/History.vue');
const flat = (s) => s.replace(/\s+/g, ' ');
const tight = (s) => s.replace(/\s+/g, '');

// Every money expression of the Classic page, written the same way, in V2.
const moneyCalls = (s) =>
    [...flat(s).matchAll(/myr\(\s*([^()]*(?:\([^()]*\))?[^()]*)\)/g)]
        .map((m) => m[1].replace(/\s+/g, ''))
        .sort();

test('V2 history shows exactly the money values Classic shows, through myr()', () => {
    const a = moneyCalls(classic.split('</script>')[1]);
    const b = moneyCalls(page.split('</script>')[1]);

    assert.ok(a.length >= 5, 'expected the Classic money expressions');
    assert.deepEqual(b, a);
    assert.ok(
        !/[+\-*/]\s*(history\.financial|line\.|payment\.)/.test(
            flat(page.split('</script>')[1]),
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
        assert.ok(flat(page).includes(piece), `V2 lacks ${piece}`);
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
    assert.ok(/rail-below="stack-bottom"/.test(page));
    assert.ok(
        /tabular-nums/.test(flat(page)) && /whitespace-nowrap/.test(flat(page)),
    );
});
