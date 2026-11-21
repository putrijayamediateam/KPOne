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

test('every row shows the size of the change and a start that has passed, and sheets manage focus', () => {
    assert.match(page, /data-testid="row-change"/);
    assert.match(page, /Start has passed: takes effect at approval\./);
    assert.match(page, /scrollIntoView\(\{ block: 'center' \}\)/);
    assert.match(page, /start-\$\{row\.id\}-\$\{kind\}/);
    assert.match(page, /size-2 shrink-0 rounded-full/);
});
