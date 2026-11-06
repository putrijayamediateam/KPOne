import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const month = read('resources/js/pages/Billing/CashClosingMonth.vue');
const daily = read('resources/js/pages/Billing/CashClosings.vue');

test('the month view is read only and links each day to the daily page', () => {
    assert.doesNotMatch(month, /router\.(post|put|patch|delete)|useForm/);
    assert.ok(month.includes('`/cash-closings?date=${row.date}`'));
    assert.ok(month.includes('`Open day, ${longDate(row.date)}`'));
    assert.ok(month.includes('Open day ›'));
    assert.ok(month.includes('href="/cash-closings" variant="outline"'));
    assert.ok(
        daily.includes(
            '`/cash-closings/month?month=${day.businessDate.slice(0, 7)}`',
        ),
    );
    assert.ok(daily.includes('Month view'));
});

test('the month bar is a labelled nav and the current month cannot go forward', () => {
    assert.ok(month.includes('aria-label="Month"'));
    assert.ok(
        month.includes(
            ':aria-disabled="view.nextMonth === null ? \'true\' : undefined"',
        ),
    );
    assert.match(month, /if \(month === null \|\| loading\.value\)/);
});

test('statuses have explicit tones and flags are text with an icon, never colour alone', () => {
    for (const line of [
        "approved: { label: 'Approved', tone: 'success' }",
        "waiting: { label: 'Waiting for approval', tone: 'warning' }",
        "returned: { label: 'Returned', tone: 'danger' }",
        "not_counted: { label: 'Not counted', tone: 'danger' }",
        "open: { label: 'Open', tone: 'neutral' }",
    ]) {
        assert.ok(month.includes(line), line);
    }

    assert.ok(month.includes("no_count: 'No count'"));
    assert.ok(month.includes("not_approved: 'Not approved'"));
    assert.ok(
        month.includes(
            'Expected cash changed after approval. The approved figures are shown.',
        ),
    );
    assert.ok(month.includes('aria-hidden="true"'));
    assert.ok(month.includes('border-l-4 border-l-warning'));
    assert.ok(month.includes('Day not ended.'));
    assert.doesNotMatch(month, /emerald|amber/);
});

test('money allows minus and the difference reads short or over', () => {
    assert.ok(month.includes('(sen < 0 ? `−${myr(-sen)}` : myr(sen))'));
    assert.ok(month.includes("${sen < 0 ? 'short' : 'over'}"));
    assert.ok(
        month.includes(
            'Totals of the counts shown; days not counted add nothing.',
        ),
    );
    assert.ok(month.includes("'So far this month'"));
});

test('empty, loading and error states are shown', () => {
    assert.ok(month.includes('No cash counts this month yet.'));
    assert.ok(month.includes(':loading="loading"'));
    assert.ok(month.includes('title="Could not load this month"'));
    assert.ok(month.includes('onHttpException'));
    assert.ok(month.includes('onNetworkError'));
    assert.ok(month.includes('class="block min-h-11'));
});
