import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Queue/V2/Index.vue');
const logic = read('resources/js/composables/queue/useQueueBoardV2.ts');
const callNext = read('resources/js/components/queue-v2/CallNextV2.vue');

test('the V2 queue poll waits while a menu, dialog, sheet, treatment dialog or Call next is open', () => {
    const start = page.indexOf('const overlayOpen = () =>');
    const end = page.indexOf('const board = useQueueBoardV2');
    const overlay = page.slice(start, end);

    for (const piece of [
        'dialogKind.value !== null',
        'sheetOpen.value',
        'callNextOpen.value',
        'treatmentDialogKey.value !== null',
        '[data-slot="v2-row-menu"][aria-expanded="true"]',
    ]) {
        assert.ok(overlay.includes(piece), piece);
    }

    assert.match(page, /useQueueBoardV2\(props, \{ paused: overlayOpen \}\)/);
    assert.match(page, /@dialog="callNextOpen = \$event"/);
});

test('the paused poll asks again on the next tick and never fetches while paused', () => {
    const schedule = logic.slice(
        logic.indexOf('const schedule = () => {'),
        logic.indexOf('const refresh = '),
    );

    assert.match(
        schedule,
        /if \(options\.paused\?\.\(\)\) \{\s*schedule\(\);\s*return;\s*\}\s*refresh\(/,
    );
    assert.match(
        callNext,
        /watch\(open, \(value\) => emit\('dialog', value\)\)/,
    );
});

test('a failed refresh shows a visible notice with the time of the last good result', () => {
    assert.match(logic, /refreshFailedAt\.value = new Date\(\)/);
    assert.match(logic, /refreshFailedAt\.value = null/);
    assert.match(
        page,
        /Live refresh paused\. Showing last successful\s+results \(\{\{ updatedAt \}\}\)\./,
    );
    assert.match(page, /role="status"/);
});
