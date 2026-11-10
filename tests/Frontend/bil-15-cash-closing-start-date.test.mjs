import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// BIL-15 (owner decisions 2026-11-07): the branch's cash closing start date on the month page.
const path = 'resources/js/components/billing/CashClosingStartDate.vue';
const raw = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = raw.replace(/\s+/g, ' ');
const month = readFileSync(
    new URL(
        '../../resources/js/pages/Billing/CashClosingMonth.vue',
        import.meta.url,
    ),
    'utf8',
).replace(/\s+/g, ' ');

test('the start date setting compiles and sits on the month page', () => {
    const descriptor = parse(raw, { filename: path }).descriptor;
    const result = compileTemplate({
        id: 'test-start-date',
        filename: path,
        source: descriptor.template?.content ?? '',
    });
    assert.deepEqual(result.errors, []);
    assert.ok(
        month.includes(
            '<CashClosingStartDate :start-date="view.startDate" :month="view.month" />',
        ),
    );
    assert.ok(
        month.includes('`Cash closing starts on ${plainDate(view.startsOn)}.`'),
    );
});

test('only those allowed see the form, and the current date and who set it are shown', () => {
    assert.ok(page.includes('v-if="startDate.canSet"'));
    assert.ok(page.includes("'/cash-closings/start-date'"));
    assert.ok(page.includes('expected_revision: props.startDate.revision'));
    assert.ok(
        page.includes(
            'Days from this date without a count show as Not counted.',
        ),
    );
    assert.ok(
        page.includes(
            "Not set. Days before the branch's first count are not marked as missed.",
        ),
    );
    assert.ok(page.includes('Save start date'));
});

test('every refusal of the start date form is shown, not only a bad date', () => {
    for (const key of [
        'starts_on',
        'expected_branch_id',
        'expected_revision',
        'idempotency_key',
    ]) {
        assert.ok(page.includes(`errors?.${key}`), key);
    }
});

test('a start date moved later is announced on the month page (owner decision 2026-11-10)', () => {
    assert.ok(page.includes('data-testid="start-date-moved-later"'));
    assert.ok(
        page.includes('Start date changed on {{ startDate.setAt }} (was'),
    );
    assert.ok(month.includes('movedLaterFrom: string | null;'));
});
