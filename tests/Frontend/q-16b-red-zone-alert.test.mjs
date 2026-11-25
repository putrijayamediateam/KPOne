import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const banner = readFileSync(
    'resources/js/components/queue/RedZoneAlertBanner.vue',
    'utf8',
);
const queue = readFileSync('resources/js/pages/Queue/Index.vue', 'utf8');
const clinical = readFileSync('resources/js/pages/Clinical/Show.vue', 'utf8');

test('the red-zone banner polls its own endpoint, shows only number and minutes, and never calls the patient (Q-16b)', () => {
    assert.match(banner, /\/queue\/red-zone-alerts\?expected_branch_id=/);
    assert.match(
        banner,
        /\/visits\/\$\{alert\.visitNumber\}\/queue\/take-red-zone/,
    );
    assert.match(banner, /document\.visibilityState === 'hidden'/);
    assert.match(banner, /10_000/);
    assert.match(banner, /I'll take it/);
    // Nothing identifying is read or drawn.
    assert.doesNotMatch(banner, /patientName|fullName|reason|zone:/i);
    assert.doesNotMatch(banner, /queue\/call|call-next/);
    // Minimise and Show alert are browser state only.
    assert.match(banner, /Minimise/);
    assert.match(banner, /Show alert/);
});

test('the banner sits on the Queue board and the consultation page, the latter only for who may take or record', () => {
    assert.match(
        queue,
        /<RedZoneAlertBanner :branch-id="live\.branch\.id" \/>/,
    );
    assert.match(clinical, /<RedZoneAlertBanner/);
    assert.match(clinical, /queue\.call\.own/);
    assert.match(clinical, /visits\.triage\.record\.branch/);
});
