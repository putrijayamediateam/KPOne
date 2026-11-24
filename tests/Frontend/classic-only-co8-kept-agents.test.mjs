// Classic only, CO-8: the Classic half of ag-15-v2-inbox, ag-16-v2-agents-list, ag-17-v2-daily-summary,
// ag-18-v2-quick-answers and ag-23-v2-office, kept when their V2 pages were removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('Classic approval inbox posts the decision to the inbox route with the lock version and shows its AI notice (ag-15)', () => {
    const page = read('resources/js/pages/Agents/Inbox.vue');

    assert.match(
        page,
        /\/agents\/inbox\/\$\{current\.proposal\.id\}\/\$\{current\.action\}/,
    );
    assert.match(page, /lock_version: current\.proposal\.lockVersion/);
    assert.match(
        page,
        /AI agents suggest; you decide\. Approving records your/,
    );
    assert.match(page, /data-test="inbox-notice"/);
});

test('Classic agents list toggles an agent on its own route with the lock version and keeps its notice (ag-16)', () => {
    const page = read('resources/js/pages/Agents/Index.vue');

    assert.match(page, /`\/agents\/\$\{agent\.id\}\/enabled`/);
    assert.match(
        page,
        /is_enabled: !agent\.isEnabled, lock_version: agent\.lockVersion/,
    );
    assert.match(page, /canManage/);
    assert.match(page, /data-test="agents-notice"/);
    assert.match(page, /KPOne sends nothing to any AI service/);
});

test('Classic daily summary reloads its own route with the chosen date inside the server range and keeps its notice (ag-17)', () => {
    const page = read('resources/js/pages/Agents/DailySummary.vue');

    assert.match(page, /'\/agents\/daily-summary'/);
    assert.match(page, /\{ date: chosen\.value \}/);
    assert.match(page, /:min="summary\.earliest"/);
    assert.match(page, /:max="summary\.today"/);
    assert.match(page, /data-test="summary-notice"/);
    assert.match(
        page,
        /AI agents read the clinic's totals and write this page; they/,
    );
    assert.match(page, /canInbox/);
    assert.match(page, /href="\/agents\/inbox"/);
});

test('Classic daily summary draws only the parts the server sent (ag-17)', () => {
    const page = read('resources/js/pages/Agents/DailySummary.vue');

    for (const guard of [
        'v-if="summary.faris"',
        'v-if="branch.hana"',
        'v-if="branch.rizal"',
        'v-if="summary.canInbox"',
        'v-if="lina.clinic"',
        'v-if="lina.doctors && lina.doctors.length > 0"',
        'v-if="lina.money"',
        'v-if="lina.panel"',
        'v-if="lina.stock"',
    ]) {
        assert.ok(page.includes(guard), guard);
    }
});

test('Classic quick answers asks on its own route with the question, branch and an optional date, and says nothing is changed (ag-18)', () => {
    const page = read('resources/js/pages/Agents/Assistant.vue');

    assert.match(page, /'\/agents\/assistant'/);
    assert.match(page, /question: selected\.value\.key/);
    assert.match(page, /branch: branch\.value/);
    assert.match(
        page,
        /\.\.\.\(usesDate\.value \? \{ date: date\.value \} : \{\}\)/,
    );
    assert.match(page, /@submit\.prevent="ask"/);
    assert.match(page, /value: 'all', label: 'All branches'/);
    assert.match(page, /label="AI agent"/);
    assert.match(page, /not generated text/);
    assert.match(page, /Nothing is changed\./);
});

test('Classic office is Classic only and gets its live behaviour from the one composable with one poll (ag-23)', () => {
    const classic = read('resources/js/pages/Agents/Office.vue');
    const logic = read(
        'resources/js/components/agents/office/useAgentOffice.ts',
    );

    assert.doesNotMatch(classic, /useUiVersion|isV2|V2WorkLayout|V2Dialog/);
    assert.match(classic, /useAgentOffice\(props\)/);
    assert.equal((classic.match(/POLL_MS/g) ?? []).length, 0);
    assert.equal((logic.match(/POLL_MS = 10_000/g) ?? []).length, 1);
    assert.match(
        classic,
        /Everyone in this office is an AI agent, not a member of\s+staff\./,
    );
});

test('the office composable keeps an open detail updated on each poll and clears it only on a new selection; motion is remembered (ag-23)', () => {
    const logic = read(
        'resources/js/components/agents/office/useAgentOffice.ts',
    );

    assert.match(
        logic,
        /watch\(\s*\(\) => feed\.value\.serverTime,\s*\(\) => \{\s*if \(selected\.value\) \{\s*void loadDetail\(selected\.value\);/,
    );
    assert.match(
        logic,
        /const select = \(code: string\) => \{\s*selected\.value = code;\s*detail\.value = null;/,
    );
    assert.match(logic, /kpone\.agents\.office\.motion/);
});
