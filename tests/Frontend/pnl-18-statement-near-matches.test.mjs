import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-18: a statement row whose invoice number differs from one open claim's only in format, or in letters that
// look like digits, is offered as a near match. The officer must tick it; it never starts ticked.

const helper = readFileSync(
    new URL(
        '../../resources/js/components/panel/PanelStatementHelper.vue',
        import.meta.url,
    ),
    'utf8',
);

test('Only matched and near-match rows can be ticked, and only matched rows start ticked', () => {
    assert.match(
        helper,
        /const canTick = \(row: StatementRow\) =>\s*row\.result === 'matched' \|\| row\.result === 'near_match';/,
    );
    assert.equal(helper.match(/:disabled="!canTick\(row\)"/g)?.length, 2);
    assert.match(
        helper,
        /ticked\.value = new Set\(\s*\(statement\?\.rows \?\? \[\]\)\s*\.filter\(\(row\) => row\.result === 'matched'\)/,
    );
    assert.match(helper, /canTick\(row\) && ticked\.value\.has\(row\.line\)/);
});

test('A near match reads "Near match: check" and shows both invoice numbers', () => {
    assert.ok(
        helper.includes(
            "near_match: { label: 'Near match: check', tone: 'warning' }",
        ),
    );
    const card = helper.slice(
        helper.indexOf('data-testid="statement-near-card"'),
        helper.indexOf(
            'v-else',
            helper.indexOf('data-testid="statement-near-card"'),
        ),
    );
    assert.match(card, /Statement:[\s\S]*row\.statementInvoice/);
    assert.match(card, /KPOne:[\s\S]*row\.claim\.invoiceNumber/);
    assert.match(card, /font-mono/);
    assert.match(helper, /data-testid="statement-near-table"/);
    assert.match(helper, /KPOne:\s*\{\{ row\.claim\.invoiceNumber \}\}/);
    assert.match(helper, /NEAR_REASONS\[row\.nearReason\]/);
    assert.ok(
        helper.includes(
            'Same apart from spaces, dashes, capitals or leading zeros.',
        ),
    );
});

test('The tick box names the near match and the summary counts near matches apart', () => {
    assert.equal(helper.match(/:aria-label="tickLabel\(row\)"/g)?.length, 2);
    assert.match(
        helper,
        /near match: statement \$\{row\.statementInvoice \?\? row\.reference\}, KPOne \$\{row\.claim\.invoiceNumber\}/,
    );
    assert.match(helper, /'near match to check'/);
    assert.match(helper, /'near matches to check'/);
    assert.match(helper, /statement\.rows\.length - matchedCount - nearCount/);
    assert.match(helper, /v-if="matchedCount === 0 && nearCount === 0"/);
});
