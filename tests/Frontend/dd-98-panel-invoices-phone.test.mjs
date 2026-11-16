import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-98: on a phone, Panel invoices shows each invoice as a card (nothing
// sits off screen), uses the shared dropdown and has one filled action.

const page = readFileSync(
    new URL(
        '../../resources/js/pages/PanelInvoices/Index.vue',
        import.meta.url,
    ),
    'utf8',
);
const flat = page.replace(/\s+/g, ' ');

test('DD-98: cards below lg (two columns from md), the wide table from lg up', () => {
    assert.match(
        flat,
        /<ul v-else class="grid gap-3 md:grid-cols-2 lg:hidden" data-testid="panel-invoice-cards" >/,
    );
    assert.match(
        flat,
        /class="hidden overflow-x-auto rounded-xl border bg-card lg:block"/,
    );

    for (const label of [
        'Member reference',
        'Invoice total',
        'Panel amount',
        'Panel adjustment (included in Panel amount)',
        'Claim',
    ]) {
        assert.ok(
            flat.includes(
                `<dt class="text-xs text-muted-foreground"> ${label} </dt>`,
            ) ||
                flat.includes(
                    `<dt class="text-xs text-muted-foreground">${label}</dt>`,
                ),
            label,
        );
    }

    assert.match(flat, /\{\{ adjustment\(row\.panelAdjustmentSen\) \}\}/);
    assert.match(flat, /sen === 0 \? myr\(0\) : `\$\{sen > 0 \? '\+' : '−'\}/);
});

test('DD-98: shared dropdowns, no native select, and Download CSV is an outline button', () => {
    assert.doesNotMatch(page, /<select\b/);
    assert.equal([...page.matchAll(/<OperationalSelect\b/g)].length, 2);
    assert.match(
        flat,
        /<Button v-if="canDownload && downloadUrl" as-child variant="outline" >/,
    );
    assert.match(
        flat,
        /<Button v-else type="button" variant="outline" disabled/,
    );
    assert.match(flat, /<Button type="submit">Show invoices<\/Button>/);
});

test('DD-98: money cells line up as tabular numbers', () => {
    assert.ok([...page.matchAll(/tabular-nums/g)].length >= 6);
});

test('DD-98: the claim reads "PCL-00000001 · Paid" in cards and table', () => {
    assert.match(
        flat,
        /row\.claimNo \? `PCL-\$\{row\.claimNo\.padStart\(8, '0'\)\} · \$\{row\.claimStatus\}` : row\.claimStatus/,
    );
    assert.equal([...page.matchAll(/\{\{ claimLabel\(row\) \}\}/g)].length, 2);
    assert.doesNotMatch(page, /· \{\{ row\.claimNo \}\}/);
});
