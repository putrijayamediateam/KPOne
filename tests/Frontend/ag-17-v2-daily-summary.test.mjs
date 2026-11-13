import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = () => read('resources/js/pages/Agents/V2/DailySummary.vue');
const report = () =>
    read('resources/js/components/agents/v2/DailyLinaReport.vue');
const lib = () => read('resources/js/lib/agent-summary.ts');

test('the V2 daily summary is registered and keeps the Classic route, props and notice', () => {
    assert.match(read('config/v2-pages.php'), /'Agents\/DailySummary'/);

    const v2 = page();

    assert.match(v2, /'\/agents\/daily-summary'/);
    assert.match(v2, /\{ date: chosen\.value \}/);
    assert.match(v2, /:min="summary\.earliest"/);
    assert.match(v2, /:max="summary\.today"/);
    assert.match(v2, /data-test="summary-notice"/);
    assert.match(
        v2,
        /AI agents read the clinic's totals and write this page; they/,
    );
    assert.match(v2, /canInbox/);
    assert.match(v2, /href="\/agents\/inbox"/);
});

test('V2 daily summary: a part the server did not send is not drawn, and the headline names only what was sent', () => {
    const v2 = page();
    const part = report();

    for (const guard of [
        'v-if="summary.faris"',
        'v-if="branch.hana"',
        'v-if="branch.rizal"',
        'v-if="summary.canInbox"',
    ]) {
        assert.ok(v2.includes(guard), guard);
    }

    for (const guard of [
        'v-if="lina.clinic"',
        'v-if="lina.doctors && lina.doctors.length > 0"',
        'v-if="lina.money"',
        'v-if="lina.panel"',
        'v-if="lina.stock"',
    ]) {
        assert.ok(part.includes(guard), guard);
    }

    // A headline figure is added only when its part arrived: net sales never shows for a role without money.
    assert.match(v2, /if \(lina\.clinic\) \{[^}]*registered/);
    assert.match(v2, /if \(lina\.money\) \{[^}]*Net sales/);
});

test('V2 daily summary: the words carry the direction, never a colour, and an agent is always labelled', () => {
    const helpers = lib();
    const v2 = page();
    const part = report();

    assert.match(helpers, /Up from/);
    assert.match(helpers, /Down from/);
    assert.match(helpers, /Same as/);
    // "0, same as 0" says nothing, so a day with nothing on both sides shows no comparison.
    assert.match(helpers, /now === 0 && before === 0/);

    for (const source of [v2, part]) {
        assert.doesNotMatch(
            source,
            /text-success|text-destructive|text-green|text-red/,
        );
    }

    assert.equal((v2.match(/tone="info" label="AI agent"/g) ?? []).length, 3);
    assert.equal((part.match(/tone="info" label="AI agent"/g) ?? []).length, 1);
    // One filled button at most, and only when the date was changed.
    assert.match(v2, /:class="changed \? filled : outline"/);
});

test('V2 daily summary: only the newest report is open, an older one is a closed bar without Panel and Stock', () => {
    const v2 = page();

    assert.match(v2, /v-for="\(lina, reportIndex\) in reports\(branch\)"/);
    assert.match(v2, /v-if="reportIndex === 0"/);
    assert.match(v2, /include-panel-stock/);
    assert.match(
        v2,
        /v-else\s+:title="`\$\{linaHeading\(lina\)\} · Lina, AI agent`"/,
    );
    assert.doesNotMatch(
        v2,
        /<V2DisclosureBar[^>]*default-open[^>]*>\s*<DailyLinaReport/,
    );
    assert.match(report(), /<template v-if="includePanelStock">/);
});

test('V2 daily summary: plain words, units from what the server counts, a crumb and money two to a row', () => {
    const helpers = lib();
    const v2 = page();
    const part = report();

    assert.match(part, /Panel claims when this report was built/);
    assert.match(part, /Stock when this report was built/);
    assert.doesNotMatch(`${v2}${part}${helpers}`, /as of this run/);
    assert.match(helpers, /'After reversals'/);
    assert.doesNotMatch(helpers, /'Net'/);
    // Low stock is one row per item at a location; expiring and expired are batches; counts due is item and location.
    assert.match(helpers, /plural\(stock\.lowStock, 'item'\)\}, by location/);
    assert.match(helpers, /plural\(stock\.expiring, 'batch', 'batches'\)/);
    assert.match(helpers, /plural\(stock\.expired, 'batch', 'batches'\)/);
    assert.match(v2, /plural\(rizal\.countsDue, 'count'\)/);
    assert.match(v2, /usePageCrumbs\(\(\) => \[/);
    assert.match(v2, /\{ title: 'Agents' \}, \{ title: 'Daily summary' \}/);
    // Money is two to a row from 390 px while the longest amount fits (12 characters); a longer one gets a row.
    assert.match(part, /MONEY_TWO_UP_MAX = 12/);
    assert.match(part, /'grid-cols-1 min-\[390px\]:grid-cols-2'/);
    assert.match(part, /moneyColumns\(lina\)/);
    assert.match(part, /max-sm:\[&_span\.tabular-nums\]:text-base/);
});

test('V2 daily summary: stats and lists use the shared V2 blocks and no raw code is shown', () => {
    const v2 = page();
    const part = report();

    assert.match(part, /<V2Stat/);
    assert.match(part, /<V2KeyValueList/);
    assert.match(v2, /<V2KeyValueList/);
    assert.match(v2, /<V2DisclosureBar/);
    assert.doesNotMatch(`${v2}${part}`, /font-mono/);
    assert.match(v2, /aria-expanded="!!showAllDue\[branch\.code\]"/);
    assert.match(v2, /RIZAL_SHOWN = 5/);
});
