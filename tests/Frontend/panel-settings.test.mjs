import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const index = read('resources/js/pages/PanelSettings/Index.vue');
const form = read('resources/js/pages/PanelSettings/Form.vue');
const navigation = read('resources/js/lib/workspace-navigation.ts');
const service = read('app/Domain/Visit/Services/PanelSettingsService.php');

test('Checkout settings lists Panels with tabs, search, status switch and Edit/Archive', () => {
    assert.match(index, /Panel payment method/);
    assert.match(index, /label: 'All'/);
    assert.match(index, /label: 'Active'/);
    assert.match(index, /label: 'Archived'/);
    assert.match(index, /Add\s+panel/);
    assert.match(index, /role="switch"/);
    assert.match(index, /rel="noopener noreferrer"/);
    assert.match(index, /\/checkout-settings\/panels\/\$\{panel\.id\}\/active/);
    assert.match(index, /lock_version: panel\.lockVersion/);
    assert.match(index, /Self-pay payment method/);
    assert.match(navigation, /href: '\/checkout-settings'/);
});

test('Panel form keeps the code fixed on edit and offers URL or manual remark verification', () => {
    assert.match(form, /:disabled="editing"/);
    assert.match(form, /Third Party Administrator/);
    assert.match(form, /Direct company/);
    assert.match(form, /URL to panel platform/);
    assert.match(form, /Manual remark/);
    assert.match(form, /days after the visit/);
    assert.match(form, /Daily, weekly or monthly/);
    assert.match(form, /Last day of the visit month/);
    assert.match(form, /lock_version: props\.panel\?\.lockVersion \?\? 1/);
    assert.doesNotMatch(form, /bulk claim/i);
});

test('Panel settings are permissioned, versioned, audited and keep contact details out of the audit trail', () => {
    assert.match(service, /'panels\.manage\.organisation'/);
    assert.match(service, /lockForUpdate\(\)/);
    assert.match(service, /lock_version/);
    assert.match(service, /'changed_fields' => \$changed/);
    assert.match(service, /url:https/);
});

test('Claim message template is plain text with smart-field buttons and a Copy message button on the claim page', () => {
    const messageService = read(
        'app/Domain/Visit/Billing/Services/PanelClaimMessageService.php',
    );
    const claimPage = read('resources/js/pages/PanelClaims/Show.vue');

    assert.match(index, /Claim message template/);
    assert.match(index, /insertField/);
    assert.match(index, /Plain text only/);
    assert.match(claimPage, /Copy message/);
    assert.match(claimPage, /v-if="claim\.message"/);
    assert.match(messageService, /'panels\.manage\.organisation'/);
    assert.match(messageService, /lockForUpdate\(\)/);

    for (const field of [
        'panel_name',
        'patient_nric',
        'staff_id',
        'claim_link',
        'clinic_name',
    ]) {
        assert.match(messageService, new RegExp(`'${field}' =>`));
    }

    for (const excluded of ['diagnosis', 'medication', 'mc_days', 'chit']) {
        assert.doesNotMatch(messageService, new RegExp(`'${excluded}'`));
    }
});
