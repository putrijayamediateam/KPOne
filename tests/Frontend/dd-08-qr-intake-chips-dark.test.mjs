import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-08 (Patients & Registration part): the QR intake status chips use theme tokens, as the board chips do (DD-38),
// so they read in dark mode.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const table = read('resources/js/components/registration/QrIntakeTable.vue');

test('QR intake chips use tokens: warning Pending, accent Reviewed, danger Rejected, outline quiet states', () => {
    assert.match(
        table,
        /pending: 'border-warning\/25 bg-warning\/10 text-warning-strong'/,
    );
    assert.match(
        table,
        /reviewed: 'border-brand\/25 bg-brand\/10 text-brand-strong'/,
    );
    assert.match(
        table,
        /rejected: 'border-destructive\/25 bg-destructive\/10 text-destructive'/,
    );

    for (const tone of ['expired', 'data_purged']) {
        assert.match(
            table,
            new RegExp(`${tone}: 'border-border text-muted-foreground'`),
        );
    }
});

test('every QR intake chip draws its border, and no pale palette colour is left', () => {
    const chips = table.match(
        /class="inline-flex[^"]*"\s*:class="statusClass\[item\.displayStatus\]"/g,
    );
    assert.equal(chips?.length, 4);

    for (const chip of chips) {
        assert.match(chip, / border /);
    }

    assert.doesNotMatch(
        table,
        /\b(?:bg|text)-(?:amber|emerald|red|rose|pink|green)-\d/,
    );
});
