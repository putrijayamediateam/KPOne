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

test('Frequency and Day use the shared select, Time the shared time field, each with a visible label', () => {
    assert.doesNotMatch(form, /<select\b/);
    assert.doesNotMatch(form, /type="time"/);
    assert.match(
        form,
        /import \{ OperationalSelect \} from '@\/components\/ui\/select'/,
    );
    assert.match(
        form,
        /import V2TimeField from '@\/components\/v2\/V2TimeField\.vue'/,
    );
    assert.match(form, /id="panel-due-frequency"[^>]*>\s*Frequency/);
    assert.match(form, /id="panel-due-day"[^>]*>\s*Day/);
    assert.match(form, /labelledby="panel-due-frequency"/);
    assert.match(form, /labelledby="panel-due-day"/);
    assert.match(form, /<V2TimeField[^>]*label="Time"/);
    assert.match(form, /:error="form\.errors\.claim_due_time"/);
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
    // Changing the frequency still clears the day, as the old select's change handler did.
    assert.match(form, /const setFrequency[\s\S]*form\.claim_due_offset = ''/);

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
