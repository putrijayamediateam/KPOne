import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the V2 office uses the shared titleCaseCode and keeps no copy of its own', () => {
    const office = read('resources/js/pages/Agents/V2/Office.vue');

    assert.match(
        office,
        /import \{ titleCaseCode \} from '@\/lib\/agent-plain';/,
    );
    assert.doesNotMatch(office, /const titleCase = /);
    assert.match(office, /titleCaseCode\(readMatch\[3\]\)/);
    assert.match(office, /\(code\) => titleCaseCode\(code\)/);
    // The shared helper is the same one the agents list uses.
    assert.match(
        read('resources/js/lib/agent-plain.ts'),
        /export const titleCaseCode = /,
    );
});

test('the refused-decision sheet says what happened instead of repeating the question', () => {
    const inbox = read('resources/js/pages/Agents/V2/Inbox.vue');

    assert.match(
        inbox,
        /if \(refused\.value\) \{\s*return 'Could not record your decision';/,
    );
    // The Approve and Reject questions are unchanged for a normal decision.
    assert.match(inbox, /`Reject this \$\{kind\}\?`/);
    assert.match(inbox, /`Approve this \$\{kind\}\?`/);
    // A refused sheet no longer says "This records your decision" above a message that it did not.
    assert.match(
        inbox,
        /refused\s*\?\s*undefined\s*:\s*deciding\?\.action === 'reject'/,
    );
});
