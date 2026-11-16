import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-33: the "Added by CA" / "Edited by CA" / "Removed by CA" chips on the
// Invoices page use the shared accent StatusBadge (with dark colours), as
// DD-27 did on the Dispensary page, instead of hand-styled pink spans.
const history = readFileSync(
    new URL('../../resources/js/pages/Visits/History.vue', import.meta.url),
    'utf8',
);
const flat = history.replace(/\s+/g, ' ');

test('DD-33: CA change chips are accent StatusBadges with no hand-styled pink', () => {
    assert.doesNotMatch(history, /bg-pink-100|text-pink-900/);

    for (const [kind, id] of [
        ['medicine', 'change-label'],
        ['service', 'service-change-label'],
    ]) {
        assert.ok(
            flat.includes(
                `<StatusBadge v-if=" changeLabel( ${kind}.changeState, ${kind}.source, ) " status="ca_change" tone="accent" :label=" changeLabel( ${kind}.changeState, ${kind}.source, ) " data-testid="${id}" />`,
            ),
            kind,
        );
    }
});
