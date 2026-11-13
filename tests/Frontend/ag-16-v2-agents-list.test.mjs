import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the V2 agents list is registered and keeps the Classic route, payload and notice', () => {
    assert.match(read('config/v2-pages.php'), /'Agents\/Index'/);

    const v2 = read('resources/js/pages/Agents/V2/Index.vue');

    assert.match(v2, /`\/agents\/\$\{agent\.id\}\/enabled`/);
    assert.match(
        v2,
        /is_enabled: !agent\.isEnabled, lock_version: agent\.lockVersion/,
    );
    assert.match(v2, /canManage/);
    assert.match(v2, /data-test="agents-notice"/);
    assert.match(v2, /KPOne sends nothing to any AI service/);
});

test('V2 agents list: no filled button, plain words for sources, tasks and errors', () => {
    const v2 = read('resources/js/pages/Agents/V2/Index.vue');

    assert.match(v2, /dataSourceLabel/);
    assert.match(v2, /codeSentence\(run\.taskType\)/);
    assert.match(v2, /codeSentence\(run\.errorCode\)/);
    assert.doesNotMatch(v2, /font-mono/);
    assert.doesNotMatch(
        v2.split('<V2Dialog')[0],
        /bg-primary|variant="default"/,
    );
    assert.match(v2, /tone="info" label="AI agent"/);
    assert.match(v2, /xl:hidden/);
    assert.match(v2, /max-xl:hidden/);
    assert.match(v2, /jobTitle\(agent\)/);
    assert.match(v2, /\\\(AI agent\\\)\$/);
});

test('dataSourceLabel names every contract data source and never shows a raw code', () => {
    const lib = read('resources/js/lib/agent-plain.ts');

    for (const code of [
        'clinic.day_summary',
        'insights.sales_summary',
        'insights.today',
        'inventory.branch_balances',
        'inventory.dispensing_activity',
        'inventory.stock_counts',
        'inventory.stock_review',
        'money.day_summary',
        'panel.claims_summary',
        'system.health',
    ]) {
        assert.ok(lib.includes(`'${code}'`), code);
    }

    assert.match(lib, /replaceAll\('\.', ' '\)\.replaceAll\('_', ' '\)/);
});

test('V2 agents list: the dialog says what happens, and a viewer who cannot manage is told who can', () => {
    const v2 = read('resources/js/pages/Agents/V2/Index.vue');

    assert.match(v2, /makes no new suggestions/);
    assert.match(v2, /Suggestions already waiting stay in the approval inbox/);
    assert.match(v2, /v-if="agents\.length > 0 && !canManage"/);
    assert.match(v2, /Only a Director can enable or disable an agent\./);
    assert.match(v2, /data-test="agents-manage-note"/);
    // The note sits outside the empty-state v-if / v-else chain, so the list still shows for a viewer.
    assert.match(v2, /v-if="agents\.length === 0"[^]*?<\/p>\s*<ul v-else/);
});

test('V2 agents list: a build agent says what Disable does, and values read left-aligned', () => {
    const v2 = read('resources/js/pages/Agents/V2/Index.vue');

    assert.match(v2, /Disable only shows it as Off in the office/);
    assert.match(v2, /confirming\?\.kind === 'build'/);
    assert.match(v2, /This agent runs no tasks in KPOne/);
    assert.doesNotMatch(v2, /text-right/);
    // Status chips keep their own width in the table.
    assert.match(v2, /flex flex-col items-start/);
});
