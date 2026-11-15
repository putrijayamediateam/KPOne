import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// Execute the actual component helpers, not a duplicate normalization algorithm.
const source = readFileSync(
    new URL(
        '../../resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue',
        import.meta.url,
    ),
    'utf8',
);
const helpers = source.slice(
    source.indexOf('const normalizeAmount ='),
    source.indexOf('const selectValue ='),
);
assert.ok(helpers.includes('const composeAmountUnit ='));
const javascript = ts.transpileModule(helpers, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;
const { normalizeAmount, composeAmountUnit } = runInNewContext(
    `${javascript}\n({ normalizeAmount, composeAmountUnit })`,
);

test('numeric and string Vue amounts compose unchanged dosage/duration text', () => {
    for (const amount of [1, 0.5, 2, '1', '0.5', ' 2 ']) {
        assert.equal(
            composeAmountUnit({ mode: 'structured', amount, unit: 'tablet' }),
            `${String(amount).trim()} tablet`,
        );
    }

    for (const amount of [3, 5, 7]) {
        assert.equal(
            composeAmountUnit(
                { mode: 'structured', amount, unit: 'day' },
                true,
            ),
            `${amount} days`,
        );
    }

    assert.equal(
        composeAmountUnit({ mode: 'structured', amount: 1, unit: 'day' }, true),
        '1 day',
    );
});

test('custom text remains free text', () => {
    assert.equal(
        composeAmountUnit({ mode: 'custom', custom: ' as directed ' }),
        'as directed',
    );
    assert.equal(
        composeAmountUnit(
            { mode: 'custom', custom: ' custom duration ' },
            true,
        ),
        'custom duration',
    );
});

test('empty values do not fabricate a dose or duration', () => {
    for (const amount of ['', '  ', null, undefined]) {
        assert.equal(normalizeAmount(amount), '');
        assert.equal(
            composeAmountUnit({ mode: 'structured', amount, unit: 'tablet' }),
            '',
        );
    }

    assert.equal(
        composeAmountUnit({ mode: 'structured', amount: 5, unit: '' }),
        '',
    );
});

test('invalid numeric values are flagged; text is not partially parsed or defaulted', () => {
    for (const amount of [NaN, Infinity, -Infinity]) {
        assert.equal(normalizeAmount(amount), null);
    }

    assert.equal(normalizeAmount('2invalid'), '2invalid');
    assert.equal(normalizeAmount(-1), '-1');
    assert.ok(source.includes('normalizeAmount(composer.amount) === null'));
    assert.match(source, /form\.setError\(\s*'medicines'/);
});
