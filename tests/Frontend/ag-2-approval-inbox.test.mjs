import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/Agents/Inbox.vue');
const routes = read('routes/web.php');

test('the inbox has Pending, Approved and Rejected tabs', () => {
    for (const label of ['Pending', 'Approved', 'Rejected']) {
        assert.match(page, new RegExp(`label: '${label}'`));
    }

    assert.match(page, /\/agents\/inbox\?tab=\$\{item\.key\}/);
});

test('agents are labelled as AI and approval is explained as recording only', () => {
    assert.match(page, /label="AI agent"/);
    assert.match(page, /Approving records your decision only/);
    assert.match(page, /nothing in stock changes/);
});

test('decisions send the lock version, need confirmation and a reason to reject', () => {
    assert.match(page, /lock_version: current\.proposal\.lockVersion/);
    assert.match(
        page,
        /\/agents\/inbox\/\$\{current\.proposal\.id\}\/\$\{current\.action\}/,
    );
    assert.match(page, /OperationalConfirmDialog/);
    assert.match(page, /Give a short reason for rejecting\./);
    assert.match(page, /Do not enter patient details\./);
    assert.match(page, /v-if="proposal\.canDecide"/);
});

test('inbox routes are permissioned and throttled', () => {
    assert.match(
        routes,
        /permission:ai_proposals\.view\.branch', 'sensitive\.no-store'\]\)->prefix\('agents\/inbox'\)/,
    );
    assert.match(routes, /agents\.inbox\.approve/);
    assert.match(routes, /agents\.inbox\.reject/);
});
