import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    'resources/js/pages/PanelPriceChanges/Index.vue',
    'utf8',
);
const routes = readFileSync('routes/web.php', 'utf8');
const nav = readFileSync(
    'app/Domain/Access/ClinicManagementNavigation.php',
    'utf8',
);

test('the page offers Propose only to proposers and keeps the approver rules', () => {
    assert.match(page, /props\.canPropose \? 'propose' : 'waiting'/);
    assert.match(page, /You proposed this\. Someone else must decide\./);
    assert.match(page, /v-if="canApprove && !row\.mine"/);
    assert.match(page, /This will take effect now, at\s+approval\./);
});

test('the propose button stays off until everything is filled and says what is missing', () => {
    for (const line of [
        'Pick a Panel to continue.',
        'Pick a charge to continue.',
        'Choose today or a later date.',
        'Enter the agreement reference, 3 to 120 characters.',
    ]) {
        assert.ok(page.includes(line), line);
    }

    assert.match(page, /:disabled="!canSubmit"/);
    assert.match(page, /Large change\. Check the agreement reference\./);
    assert.match(
        page,
        /Plain text, 3 to 120 characters\. Do not enter patient details\./,
    );
});

test('reject needs a reason before it can be confirmed, and no request carries the reference text elsewhere', () => {
    assert.match(page, /rejectReason === ''/);
    assert.doesNotMatch(page, /localStorage|sessionStorage/);
});

test('routes and the Clinic management row use the two Panel price permissions', () => {
    assert.match(
        routes,
        /permission:panel\.prices\.propose\.organisation,panel\.prices\.approve\.organisation/,
    );
    assert.match(nav, /'Panel price changes'/);
    assert.doesNotMatch(routes, /panel-price-changes[^\n]*return back/);
});
