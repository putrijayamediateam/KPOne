import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/Agents/DailySummary.vue');
const routes = read('routes/web.php');

test('the daily summary route is permissioned, not cached and throttled', () => {
    assert.match(
        routes,
        /'agents\/daily-summary', \[AgentDailySummaryController::class, 'index'\]\)\s+->middleware\(\['permission:ai_daily_summary\.view\.branch', 'sensitive\.no-store', 'throttle:60,1'\]\)->name\('agents\.daily-summary'\)/,
    );
});

test('every section names its agent as an AI agent, never as staff', () => {
    for (const label of [
        'Faris (AI agent)',
        'Lina (AI agent)',
        'Hana (AI agent)',
        'Rizal (AI agent)',
    ]) {
        assert.ok(page.includes(label), label);
    }

    assert.match(page, /they change\s+nothing and send no messages/);
});

test('the morning report compares with last week and the afternoon update shows no arrows', () => {
    assert.match(page, /the same\s+weekday last week/);
    assert.match(page, /Half a day, so no comparison is shown\./);
    assert.match(page, /Today so far/);
    // Arrows are hidden from screen readers; the words carry the meaning.
    assert.match(
        page,
        /<span aria-hidden="true">\{\{\s*row\.trend\.symbol\s*\}\}<\/span>/,
    );
    assert.match(page, /if \(before === null \|\| before === undefined\)/);
});

test('the date picker stays inside the last 31 days and today', () => {
    assert.match(page, /:min="summary\.earliest"/);
    assert.match(page, /:max="summary\.today"/);
    assert.match(page, /<Label for="summary-date">Date<\/Label>/);
});

test('the inbox link shows only to inbox viewers and parts hide when not sent', () => {
    assert.match(page, /v-if="summary\.canInbox"/);

    for (const part of [
        'lina.clinic',
        'lina.money',
        'lina.panel',
        'lina.stock',
        'branch.hana',
        'branch.rizal',
        'summary.faris',
    ]) {
        assert.ok(page.includes(`v-if="${part}"`), part);
    }
});

test('touch targets are 44 px below 1024 px', () => {
    assert.match(page, /class="w-44 max-lg:min-h-11"/);
    assert.match(
        page,
        /<Button type="submit" class="max-lg:min-h-11">Show<\/Button>/,
    );
});
