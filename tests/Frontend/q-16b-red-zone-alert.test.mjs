import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
const banner = read('resources/js/components/queue/RedZoneAlertBanner.vue');
const controller = read('resources/js/composables/useEmergencyAlerts.ts');
const host = read('resources/js/components/queue/EmergencyAlertHost.vue');
const queue = read('resources/js/pages/Queue/Index.vue');
const clinical = read('resources/js/pages/Clinical/Show.vue');
const layouts = [
    'ClinicLayout.vue',
    'AdminLayout.vue',
    'app/AppSidebarLayout.vue',
    'app/AppHeaderLayout.vue',
].map((path) => read(`resources/js/layouts/${path}`));

test('the shared emergency alert retains narrow endpoints, number-only privacy and never calls the patient (Q-16b/TRI2)', () => {
    assert.match(controller, /\/queue\/red-zone-alerts\?expected_branch_id=/);
    assert.match(
        controller,
        /\/visits\/\$\{[^}]*visitNumber[^}]*\}\/queue\/take-red-zone/,
    );
    assert.match(controller, /document\.visibilityState === 'hidden'/);
    assert.match(controller, /5_000/);
    assert.match(banner, /I'll see this patient/);
    assert.doesNotMatch(
        banner + controller,
        /patientName|fullName|reason|zone:/i,
    );
    assert.doesNotMatch(banner + controller, /queue\/call|call-next/);
});

test('one shared host covers main and focus layouts with emergency authority or narrow recorder read access', () => {
    for (const layout of layouts) {
        assert.equal((layout.match(/<EmergencyAlertHost/g) ?? []).length, 1);
    }

    assert.doesNotMatch(queue + clinical, /<RedZoneAlertBanner/);
    assert.match(host, /branchContext/);
    assert.match(host, /queue\.emergency\.respond\.branch/);
    assert.match(host, /visits\.triage\.record\.branch/);
    assert.match(host, /<RedZoneAlertBanner/);
});

test('failed takes remain visible and retain the validated original consultation return (Q-16b review)', () => {
    assert.match(controller, /onError:/);
    assert.match(banner, /role="status"/);
    assert.match(banner, /role="alert"/);
    assert.match(banner, /aria-label=.*I'll see/);
    assert.match(controller, /from: 'consultation', from_visit:/);
    assert.match(clinical, /useEmergencyHoldBridge/);
    assert.match(banner, /Live alerts paused/);
});

test('emergency state is restored by polling, with separate sound clock and bounded device mute', () => {
    assert.match(controller, /10_000/);
    assert.match(controller, /120_000/);
    assert.match(controller, /localStorage/);
    assert.match(controller, /AbortController/);
    assert.match(banner, /Seen, not taking/);
    assert.match(banner, /Sound is off on this device/);
    assert.doesNotMatch(banner, /Minimise|Show alert/);
});
