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
    assert.match(page, /the\s+same\s+weekday\s+last\s+week/);
    assert.match(page, /Half a day, so no comparison is shown\./);
    assert.match(page, /14:00 update · today so far/);
    assert.match(page, /07:00 report · covers/);
    // Arrows are hidden from screen readers; the words carry the meaning.
    assert.match(
        page,
        /<span aria-hidden="true">\{\{\s*row\.trend\.symbol\s*\}\}<\/span>/,
    );
    assert.match(page, /before === null \|\|\s+before === undefined/);
});

test('the date picker stays inside the last 31 days and today', () => {
    assert.match(page, /:min="summary\.earliest"/);
    assert.match(page, /:max="summary\.today"/);
    assert.match(page, /<Label for="summary-date">Summary on<\/Label>/);
});

test('the inbox link shows only to inbox viewers and parts hide when not sent', () => {
    assert.match(page, /v-if="summary\.canInbox"/);
    assert.match(page, /The inbox lists your active branch\./);

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

test('branches fold with the shared collapsible; the active branch opens, today so far comes first', () => {
    assert.match(
        page,
        /<Collapsible\s+v-for="\(branch, index\) in summary\.branches"/,
    );
    assert.match(page, /:default-open="openFirst\(branch\.code, index\)"/);
    assert.match(page, /<CollapsibleTrigger/);
    assert.match(page, /\{\{ headline\(branch\) \}\}/);
    assert.match(page, /'No report yet'/);
    assert.match(page, /\[branch\.linaAfternoon, branch\.linaMorning\]/);
});

test('nothing on either day shows no comparison; the inbox is a button; long count lists fold', () => {
    assert.match(page, /\(now === 0 && before === 0\)/);
    assert.match(page, /<Button\s+as-child\s+variant="outline"/);
    assert.match(page, /const RIZAL_SHOWN = 5;/);
    assert.match(
        page,
        /`and \$\{branch\.rizal\.due\.length - RIZAL_SHOWN\} more`/,
    );
});

test('AG-12: a doctor seen last week but none on the report day reads "None seen", not "0 seen, about 0 min"', () => {
    assert.match(
        page,
        /<span v-if="doctor\.seen > 0"\s*>\{\{ doctor\.seen \}\} seen, about/,
    );
    assert.match(
        page,
        /<span v-else data-test="lina-doctor-none"\s*>None seen<\/span\s*>/,
    );
});
