import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-23: "Bills to collect" shows pay later and money due now on completed visits, with what each bill still owes.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );
const page = read('resources/js/pages/Billing/PayLaterCollections.vue');

test('the page is named Bills to collect and says what it lists', () => {
    assert.ok(page.includes('<Head title="Bills to collect" />'));
    assert.ok(
        page.includes('approved pay later and money due on completed visits'),
    );
    assert.ok(
        page.includes(
            'Due now is money that should have been paid at the visit; it counts from the visit day. A payment pays due now before pay later.',
        ),
    );
});

test('the table has Pay later, Due now and Still owed columns', () => {
    for (const heading of [
        '>Pay later</th>',
        '>Due now</th>',
        '>Still owed</th>',
    ]) {
        assert.ok(page.includes(heading), heading);
    }

    assert.ok(page.includes(':columns="9"'));
    assert.ok(page.includes('{{ myr(owedOf(row)) }}'));
});

test('each card leads with what the bill still owes and breaks it down', () => {
    const card = page
        .split('data-testid="pay-later-card"')[1]
        .split('</article>')[0];
    const order = [
        'Still owed',
        'myr(owedOf(row))',
        'bill-card-breakdown',
        'Pay later',
        'Due now',
        'row.patientName',
    ];
    let last = -1;

    for (const marker of order) {
        const at = card.indexOf(marker);
        assert.ok(at > last, `${marker} is in order`);
        last = at;
    }
});

test('money due now with no pay-later counts days since the visit', () => {
    assert.ok(
        page.includes("`${days} ${days === 1 ? 'day' : 'days'} since visit`"),
    );
    assert.equal(page.split('daysSinceVisit(row.daysOverdue)').length - 1, 2);
    assert.equal(
        page.split('row.daysOverdue > 0 && dueNowOf(row) > 0').length - 1,
        2,
    );
});

test('a bill with money due now still shows its agreed pay-later date', () => {
    assert.ok(page.includes('payLaterDue?: Record<string, string>;'));
    assert.ok(page.includes('data-testid="bill-card-pay-later-due"'));
    assert.ok(page.includes('data-testid="bill-pay-later-due"'));
    assert.ok(page.includes('aria-label="Bills to collect"'));
});

test('a bill with money due now reads Now in its due date, and the totals card says what it counts', () => {
    assert.equal(
        page.split("dueNowOf(row) > 0 ? 'Now' : row.dueDate").length - 1,
        2,
    );
    assert.ok(
        page.includes(
            'Owed on bills past due (today is {{ collections.today }})',
        ),
    );
    assert.match(page, />\s*Approved pay later\s*<\/th>/);
    assert.ok(page.includes('v-if="row.approvedSen > 0"'));
});
