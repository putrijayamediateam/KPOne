import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = () => read('resources/js/pages/Agents/V2/DailySummary.vue');

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

test('V2 daily summary: a part the server did not send is not drawn', () => {
    const v2 = page();

    for (const guard of [
        'v-if="summary.faris"',
        'v-if="lina.clinic"',
        'v-if="lina.doctors && lina.doctors.length > 0"',
        'v-if="lina.money"',
        'v-if="lina.panel"',
        'v-if="lina.stock"',
        'v-if="branch.hana"',
        'v-if="branch.rizal"',
        'v-if="summary.canInbox"',
    ]) {
        assert.ok(v2.includes(guard), guard);
    }
});

test('V2 daily summary: the words carry the direction, never a colour, and an agent is always labelled', () => {
    const v2 = page();

    assert.match(v2, /Up from/);
    assert.match(v2, /Down from/);
    assert.match(v2, /Same as/);
    // "0, same as 0" says nothing, so a day with nothing on both sides shows no comparison.
    assert.match(v2, /now === 0 && before === 0/);
    assert.doesNotMatch(
        v2,
        /text-success|text-destructive|text-green|text-red/,
    );
    assert.equal((v2.match(/tone="info" label="AI agent"/g) ?? []).length, 4);
    // One filled button at most, and only when the date was changed.
    assert.match(v2, /:class="changed \? filled : outline"/);
});

test('V2 daily summary: stats and lists use the shared V2 blocks and no raw code is shown', () => {
    const v2 = page();

    assert.match(v2, /<V2Stat/);
    assert.match(v2, /<V2KeyValueList/);
    assert.match(v2, /<V2DisclosureBar/);
    assert.doesNotMatch(v2, /font-mono/);
    assert.match(v2, /aria-expanded="!!showAllDue\[branch\.code\]"/);
    assert.match(v2, /RIZAL_SHOWN = 5/);
});
