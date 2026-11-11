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
            'Money due now counts from the visit day; a payment pays it before pay later.',
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
        page.split('row.daysOverdue > 0 && row.approvedSen === 0').length - 1,
        2,
    );
});
