import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the info-only sheets that only close use the outline Close', () => {
    const office = read('resources/js/pages/Agents/V2/Office.vue');
    const inbox = read('resources/js/pages/Agents/V2/Inbox.vue');

    // The office detail dialog has no action to take: one outline Close.
    assert.match(
        office,
        /single-action\s+single-action-style="outline"\s+@confirm="closePanel"|confirm-label="Close"\s+single-action\s+single-action-style="outline"/,
    );
    // The refused-decision sheet only closes (submit just shuts the dialog when `refused`): outline too. The Approve and
    // Reject sheet is not a single-action sheet, so it keeps its filled Confirm.
    assert.match(
        inbox,
        /:single-action="refused"\s+single-action-style="outline"/,
    );
    assert.match(
        inbox,
        /if \(refused\.value\) \{\s*dialogOpen\.value = false;/,
    );
});
