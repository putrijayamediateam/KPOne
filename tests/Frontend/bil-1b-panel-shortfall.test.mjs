import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

const path = 'resources/js/components/billing/BillingWorkspace.vue';
const raw = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const billing = raw.replace(/\s+/g, ' ');
const types = readFileSync(
    new URL('../../resources/js/types/billing.ts', import.meta.url),
    'utf8',
).replace(/\s+/g, ' ');

test('the billing page compiles with the Panel shortfall warning', () => {
    const descriptor = parse(raw, { filename: path }).descriptor;
    const result = compileTemplate({
        id: 'test-panel-shortfall',
        filename: path,
        source: descriptor.template?.content ?? '',
    });

    assert.deepEqual(result.errors, []);
});

test('the warning appears only when the server sends a shortfall and says the amount owed is unchanged', () => {
    assert.ok(billing.includes('v-if="billing.panelShortfall"'));
    assert.ok(billing.includes('data-testid="panel-shortfall"'));
    assert.ok(billing.includes('role="note"'));
    assert.ok(billing.includes('The Panel rejected the claim.'));
    assert.ok(billing.includes('The Panel accepted only part of the claim.'));
    assert.ok(billing.includes('The claim was for less than the Panel share.'));
    assert.ok(billing.includes('Panel shortfall:'));
    assert.ok(billing.includes('myr(billing.panelShortfall.shortfallSen)'));
    assert.ok(
        billing.includes(
            'Shown for information only. What the patient owes is unchanged.',
        ),
    );
});

test('the warning adds no action: no button, form or request inside it', () => {
    const start = billing.indexOf('data-testid="panel-shortfall"');
    // The warning holds only <p> elements, so its first closing </div> ends it.
    const end = billing.indexOf('</div>', start);
    const block = billing.slice(start, end);

    assert.ok(start > 0 && end > start);
    assert.ok(!/<Button|<button|<form|@click|router\.|post\(/.test(block));
});

test('the payload type carries numbers and the decision only', () => {
    assert.match(
        types,
        /export type PanelShortfall = \{ shortfallSen: number; panelShareSen: number; acceptedSen: number; decision: 'rejected' \| 'partially_accepted' \| 'claim_reduced'; \};/,
    );
    assert.ok(types.includes('panelShortfall: PanelShortfall | null;'));
});
