import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-4: the Follow-up list and the per-Panel follow-up days setting.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/PanelClaims/FollowUp.vue');
const index = read('resources/js/pages/PanelClaims/Index.vue');
const form = read('resources/js/pages/PanelSettings/Form.vue');
const routes = read('routes/web.php');
const service = read(
    'app/Domain/Visit/Billing/Services/PanelClaimFollowUpService.php',
);

test('The Follow-up list is a read-only page reached from Panel Claims', () => {
    assert.match(
        index,
        /<Link href="\/panel-claims\/follow-up">Follow-up list<\/Link>/,
    );
    const followUpRoute = routes.indexOf("'panel-claims/follow-up'");
    const claimRoute = routes.indexOf("Route::get('panel-claims/{claim}'");
    assert.ok(followUpRoute > 0 && followUpRoute < claimRoute);
    assert.doesNotMatch(page, /router\.(post|patch|put|delete)\(/);
    assert.doesNotMatch(page, /localStorage|sessionStorage/);
});

test('Each row shows the last action, its date, who did it and the days since', () => {
    for (const field of [
        'claim.lastAction',
        'claim.lastActionDate',
        'claim.lastActionBy',
        'claim.daysSinceAction',
        'claim.followUpDays',
        'claim.amountLabel',
        'claim.amountSen',
        'claim.showUrl',
    ]) {
        assert.ok(page.includes(field), field);
    }

    assert.match(page, /No claim needs a follow-up right now/);
    assert.match(page, /Paid and rejected claims are not listed/);
});

test('Open claims and actions are defined once in the service', () => {
    assert.match(
        service,
        /OPEN = \['prepared', 'submitted', 'queried', 'accepted', 'partially_accepted', 'partially_paid'\]/,
    );
    assert.match(service, /panel_claim_events/);
    assert.match(service, /panel_claim_payments/);
    assert.match(service, /panel_claim_documents/);
    assert.match(service, /make_interval\(days => pn\.claim_follow_up_days\)/);
});

test('Panel settings offer follow-up days between 1 and 365, default 30', () => {
    assert.match(
        form,
        /claim_follow_up_days: props\.panel\?\.claimFollowUpDays \?\? 30/,
    );
    assert.match(
        form,
        /v-model\.number="form\.claim_follow_up_days"\s+type="number"\s+min="1"\s+max="365"/,
    );
    assert.match(form, /form\.errors\.claim_follow_up_days/);
});
