import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Clinic management has an icon for the AI agents group', () => {
    const page = read('resources/js/pages/ClinicManagement/Index.vue');

    assert.match(page, /agents: Bot,/);
    assert.match(page, /\bBot,\n/);
});

test('the AI agents group lists the registry, the office and the inbox under their own permissions', () => {
    const nav = read('app/Domain/Access/ClinicManagementNavigation.php');

    assert.match(nav, /\$add\('AI agents', 'agents', \[/);
    assert.match(
        nav,
        /\[\$actor->can\('ai_agents\.view\.organisation'\), 'All agents', .*'agents\.index'\]/,
    );
    assert.match(
        nav,
        /\[\$actor->can\('ai_agents\.view\.organisation'\), 'Agent office', .*'agents\.office'\]/,
    );
    assert.match(
        nav,
        /\[\$activeBranch !== null && \$actor->can\('ai_proposals\.view\.branch'\) && \$this->branches->hasEffectiveAssignment\(\$actor, \$activeBranch\), 'Approval inbox', .*'agents\.inbox'\]/,
    );
});
