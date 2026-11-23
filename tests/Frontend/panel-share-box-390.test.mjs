import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// On a phone the long "Adjust Panel amount" and "Change claim amount only"
// labels wrap inside the Panel share box instead of widening the page.

const panel = readFileSync(
    new URL(
        '../../resources/js/components/billing/PanelAdjustmentPanel.vue',
        import.meta.url,
    ),
    'utf8',
);

test('the long Panel share buttons wrap at phone width', () => {
    const wrap =
        'class="max-lg:min-h-11 max-sm:!h-auto max-sm:w-full max-sm:justify-start max-sm:py-2 max-sm:text-left max-sm:whitespace-normal"';
    assert.equal(panel.split(wrap).length - 1, 3);
});
