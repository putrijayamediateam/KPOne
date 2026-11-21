import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the Daily summary and the quick question no longer promise a flat 90 days', () => {
    // STK-42: each item has its own alert window; the agent data source caps it at 90 days.
    assert.match(
        read('resources/js/lib/agent-summary.ts'),
        /label: 'Expiring soon \(up to 90 days\)'/,
    );
    assert.match(
        read('resources/js/pages/Agents/DailySummary.vue'),
        /expiring soon \(up to 90 days\)/,
    );
    assert.match(
        read('app/Domain/Agents/Assistant/QuickQuestions.php'),
        /Batches expiring soon \(up to 90 days\)/,
    );
    assert.doesNotMatch(
        read('resources/js/lib/agent-summary.ts'),
        /Expiring within 90 days/,
    );
});
