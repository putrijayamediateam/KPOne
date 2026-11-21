// PNL-26b and PNL-26c: the Panel's limit per visit warns only. The doctor reads one line and never an item price.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const flat = (text) => text.replace(/\s+/g, ' ');
const workspace = read('resources/js/components/billing/BillingWorkspace.vue');
const clinical = read('resources/js/pages/Clinical/Show.vue');
const service = read('app/Domain/Visit/Billing/Services/PanelVisitLimit.php');

test('Billing warns above the limit, says it is only a warning and never disables an action', () => {
    assert.match(workspace, /billing\.panelVisitLimit\?\.over/);
    assert.match(flat(workspace), /Above the Panel's limit per visit/);
    assert.match(
        flat(workspace),
        /This is a warning only\. You can continue\./,
    );
    const block = workspace.slice(
        workspace.indexOf('data-testid="panel-visit-limit"'),
        workspace.indexOf('data-testid="panel-shortfall"'),
    );
    assert.ok(!/disabled|:disabled/.test(block), 'the notice disables nothing');
    assert.ok(
        block.includes('border-warning/25') && !/red-|destructive/.test(block),
    );
});

test('The doctor reads one estimate line, with the limit, and no item price', () => {
    assert.match(clinical, /data-testid="consultation-panel-estimate"/);
    assert.match(flat(clinical), /Estimated Panel total/);
    assert.match(flat(clinical), /is over the/);
    assert.match(flat(clinical), /limit\. Estimate only\./);
    // Shown only when over the limit, and the amounts never break across lines.
    assert.match(clinical, /v-if="clinical\.visit\.panelEstimate\?\.over"/);
    assert.match(clinical, /myrNoBreak/);
    assert.match(workspace, /myrNoBreak\(billing\.panelVisitLimit\.totalSen\)/);
    assert.match(flat(clinical), /some items have no Panel price yet/);
    const block = clinical.slice(
        clinical.indexOf('data-testid="consultation-panel-estimate"'),
        clinical.indexOf('data-testid="consultation-panel-note"'),
    );
    const compact = block.replace(/\s+/g, '');
    assert.ok(
        compact.includes('panelEstimate.totalSen') &&
            compact.includes('panelEstimate.limitSen'),
    );
    assert.ok(
        !/unitPrice|unit_price|lineTotal|\.lines/.test(block),
        'no item price on the line',
    );
});

test('The server computes the estimate, read only and without locks, from the Panel price rule billing uses', () => {
    assert.match(service, /PriceResolutionService/);
    assert.match(service, /->select\(/);
    assert.ok(
        !/lockForUpdate/.test(service),
        'a read of the estimate takes no lock',
    );
    assert.match(service, /where\('status', 'active'\)/);
});
