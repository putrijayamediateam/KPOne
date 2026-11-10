import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// BIL-18: the pay-later list shows cards below lg, and the summary row names what it is.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8').replace(
        /\s+/g,
        ' ',
    );
const collections = read('resources/js/pages/Billing/PayLaterCollections.vue');

test('below lg the pay-later list is cards; the table stays from lg up', () => {
    assert.match(
        collections,
        /class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden"/,
    );
    assert.ok(collections.includes('data-testid="pay-later-card"'));
    assert.match(
        collections,
        /class="hidden min-w-0 lg:block"> <OperationalTable/,
    );
    assert.ok(collections.includes('data-testid="pay-later-row"'));
});

test('each card leads with the amount, keeps the due-now note beside it and has a 44 px action', () => {
    const card = collections
        .split('data-testid="pay-later-card"')[1]
        .split('</article>')[0];
    const order = [
        'myr(row.remainingSen)',
        'pay-later-card-due-now',
        'row.patientName',
        'Due date',
        'Visit date',
        'Invoice',
        'Approved',
        'Open billing',
    ];
    let last = -1;

    for (const marker of order) {
        const at = card.indexOf(marker);
        assert.ok(at > last, `${marker} is in order`);
        last = at;
    }

    assert.ok(card.includes('is also due now. A payment pays that first.'));
    assert.match(card, /class="min-h-11 w-full"/);
    assert.ok(
        card.includes(':aria-label="`Open billing for ${row.visitNumber}`"'),
    );
    assert.match(
        collections,
        /<label class="flex min-h-11 items-center gap-2 text-sm">/,
    );
});

test('the summary row reads Pay later still owed on screens, never on the print', () => {
    for (const path of [
        'resources/js/components/billing/BillingWorkspace.vue',
        'resources/js/pages/Billing/Invoice.vue',
        'resources/js/pages/Visits/History.vue',
    ]) {
        const source = read(path);
        assert.ok(source.includes("'Pay later still owed'"), path);
        assert.ok(!source.includes("'Approved pay later'"), path);
    }

    assert.ok(
        read('resources/js/pages/Billing/Print.vue').includes(
            'Approved pay later',
        ),
    );
});

test('both past-due chips (card and table) say day or days, never day(s)', () => {
    assert.equal(
        collections.split("row.daysOverdue === 1 ? 'day' : 'days'").length - 1,
        2,
    );
    assert.ok(!collections.includes('day(s)'));
});
