import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const panel = read('resources/js/components/patient/FamilyPanel.vue');
const card = read('resources/js/components/clinical/FamilyWaitingCard.vue');
const create = read('resources/js/pages/Registration/Create.vue');
const clinical = read('resources/js/pages/Clinical/Show.vue');

test('Registration shows the Family panel for the chosen patient only', () => {
    assert.match(
        create,
        /<FamilyPanel\s+v-if="selectedPatient"[\s\S]*?:branch-id="options\.branch\.id"/,
    );
});

test('the Family panel links by hand: no phone search, explicit choice, retry key and family version', () => {
    assert.doesNotMatch(
        panel,
        /search_type:\s*'phone'|maskedPhone|mobile_phone/,
    );
    assert.match(panel, /'patient_number'\s*:\s*'name'/);
    assert.match(panel, /expected_family_version:/);
    assert.match(panel, /expected_branch_id:/);
    assert.match(panel, /idempotency_key: pendingKey/);
    assert.match(panel, /crypto\.randomUUID\(\)/);
    assert.match(panel, /v-if="family\?\.canManage && !adding"/);
    assert.match(panel, /<OperationalConfirmDialog[\s\S]*?destructive/);
    assert.doesNotMatch(panel, /localStorage|sessionStorage|v-html/);
});

test('the Consultation Family card lists relatives waiting today with links and no clinical fields', () => {
    assert.match(
        clinical,
        /<FamilyWaitingCard\s+v-if="clinical\.familyWaiting"/,
    );
    assert.match(card, /No linked relatives waiting in your queue today\./);
    assert.match(card, /:href="row\.url"/);
    assert.doesNotMatch(
        card.slice(card.indexOf('<template>')),
        /allerg|diagnos|condition|triage|phone|medicine|v-html/i,
    );
});
