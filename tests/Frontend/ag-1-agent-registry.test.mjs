import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/Agents/Index.vue');
const routes = read('routes/web.php');

test('every agent on the registry page carries a visible AI agent label', () => {
    assert.match(page, /label="AI agent"/);
    assert.match(page, /These are AI agents, not staff\./);
    assert.match(page, /KPOne sends nothing to any AI service\./);
});

test('enable and disable send the lock version and need confirmation', () => {
    assert.match(page, /\/agents\/\$\{agent\.id\}\/enabled/);
    assert.match(page, /lock_version: agent\.lockVersion/);
    assert.match(page, /is_enabled: !agent\.isEnabled/);
    assert.match(page, /OperationalConfirmDialog/);
    assert.match(page, /v-if="canManage"/);
});

test('office lights use the four contract colours and the activity log is read-only', () => {
    for (const light of ['green', 'yellow', 'red', 'grey']) {
        assert.match(page, new RegExp(`${light}: '`));
    }

    assert.match(page, /Recent activity/);
    assert.doesNotMatch(page, /router\.(delete|patch|put)\(/);
});

test('agent routes are permissioned and the feed is read-only', () => {
    assert.match(
        routes,
        /permission:ai_agents\.view\.organisation', 'sensitive\.no-store'\]\)->prefix\('agents'\)/,
    );
    assert.match(
        routes,
        /Route::get\('office-feed', \[AgentRegistryController::class, 'feed'\]\)/,
    );
    assert.match(routes, /permission:ai_agents\.manage\.organisation/);
});
