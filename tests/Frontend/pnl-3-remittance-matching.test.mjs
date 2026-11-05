import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-3: record one Panel payment and split it across that Panel's accepted claims.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/PanelRemittances/Index.vue');
const claims = read('resources/js/pages/PanelClaims/Index.vue');
const invoices = read('resources/js/pages/PanelInvoices/Index.vue');
const routes = read('routes/web.php');
const billing = read('app/Domain/Access/BillingPermissions.php');

test('The page is reached by the new narrow permission from Panel Claims and Panel invoices', () => {
    assert.match(
        routes,
        /Route::middleware\(\['permission:panel\.remittances\.record\.branch', 'sensitive\.no-store'\]\)/,
    );
    assert.match(
        routes,
        /Route::post\('panel-remittances', \[PanelRemittancesController::class, 'store'\]\)->middleware\('throttle:20,1'\)/,
    );

    for (const source of [claims, invoices]) {
        assert.match(source, /v-if="(work\.)?can\.recordRemittances"/);
        assert.match(
            source,
            /<Link href="\/panel-remittances">Record Panel payment<\/Link>/,
        );
    }

    const holders = [
        ...billing.matchAll(/'([a-z_]+)' => \[([^\]]*)\]/g),
    ].filter(([, , list]) => list.includes('panel.remittances.record.branch'));
    assert.deepEqual(holders.map(([, role]) => role).sort(), [
        'finance_officer',
        'panel_officer',
    ]);
});

test('Amounts are converted to sen without floating point and the split must equal the payment', () => {
    assert.match(page, /const toSen = \(text: string\): number \| null =>/);
    assert.match(page, /\/\^\(\\d\{1,9\}\)\(\?:\\\.\(\\d\{1,2\}\)\)\?\$\//);
    assert.doesNotMatch(page, /parseFloat|\* 100\)/);
    assert.match(page, /leftSen\.value === 0/);
    assert.match(page, /sen > claim\.outstandingSen/);
    assert.match(page, /Enter the amount received/);
    assert.match(page, /over the payment/);
    assert.match(page, /Fill oldest claims first/);
});

test('One idempotency key per payment, renewed only after success', () => {
    assert.match(page, /const idempotencyKey = ref\(crypto\.randomUUID\(\)\)/);
    assert.match(
        page,
        /onSuccess: \(\) => \{[\s\S]*?idempotencyKey\.value = crypto\.randomUUID\(\);[\s\S]*?onFinish/,
    );
    assert.match(page, /idempotency_key: idempotencyKey\.value/);
    assert.doesNotMatch(page, /localStorage|sessionStorage/);
});

test('Phones get one card per claim so the amount box stays on screen', () => {
    assert.match(page, /class="divide-y rounded-xl border md:hidden"/);
    assert.match(
        page,
        /relative hidden max-w-full overflow-x-auto[^"]*md:block/,
    );
    assert.match(
        page,
        /A\s+recorded\s+payment\s+cannot\s+be\s+edited\s+or\s+deleted/,
    );
    assert.match(page, /v-for="message in errorMessages"/);
});
