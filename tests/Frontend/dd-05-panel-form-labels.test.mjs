import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-05: every control on the Panel form (Add or Edit panel in Checkout settings) has a visible label. The address
// fields keep their accessible names; Frequency and Day use the shared select and Time uses the shared time field.
const form = readFileSync(
    new URL('../../resources/js/pages/PanelSettings/Form.vue', import.meta.url),
    'utf8',
);

test('each address field sits inside a visible label and keeps its accessible name', () => {
    for (const [field, label] of [
        ['address_line_1', 'Address line 1'],
        ['address_line_2', 'Address line 2'],
        ['postcode', 'Postcode'],
        ['city', 'City'],
        ['state', 'State'],
        ['country', 'Country'],
    ]) {
        const block = new RegExp(
            `<label class="grid gap-1 text-xs">\\s*${label}\\s*<Input\\s+v-model="form\\.${field}"[^>]*aria-label="${label}"`,
        );
        assert.match(form, block, `${label} has a visible label`);
        assert.match(form, new RegExp(`form\\.errors\\.${field}`));
    }

    // No field is named by a placeholder alone any more.
    assert.doesNotMatch(form, /placeholder="Address line 1"/);
});

test('Frequency, Time and Day each sit inside a visible label (native controls kept)', () => {
    for (const label of ['Frequency', 'Time', 'Day']) {
        assert.match(
            form,
            new RegExp(
                `<label class="grid content-start gap-1">\\s*${label}\\s*<(select|Input)\\b[\\s\\S]*?aria-label="${label}"`,
            ),
            `${label} has a visible label`,
        );
    }

    assert.match(form, /@change="form\.claim_due_offset = ''"/);
    assert.match(form, /Submit by this time\./);
});

test('the saved values and the request are unchanged', () => {
    for (const key of [
        'claim_due_rule',
        'claim_due_days',
        'claim_due_time',
        'claim_due_offset',
        'claim_follow_up_days',
    ]) {
        assert.ok(form.includes(`form.${key}`), `${key} is still bound`);
    }

    assert.match(form, /blankToNull\(data\.claim_due_time\)/);

    // The day choices are the same as before.
    for (const text of [
        'Same day',
        'Next day',
        '2 days after',
        '3 days after',
        'Last day of the visit month',
    ]) {
        assert.ok(form.includes(text), text);
    }
});
