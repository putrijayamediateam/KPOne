import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the V2 approval inbox is registered and keeps the Classic routes, payload and notice', () => {
    assert.match(read('config/v2-pages.php'), /'Agents\/Inbox'/);
    const v2 = read('resources/js/pages/Agents/V2/Inbox.vue');
    assert.match(
        v2,
        /\/agents\/inbox\/\$\{current\.proposal\.id\}\/\$\{current\.action\}/,
    );
    assert.match(v2, /lock_version: current\.proposal\.lockVersion, reason/);
    assert.match(
        v2,
        /AI agents suggest; you decide\. Approving only records your/,
    );
    assert.match(v2, /data-test="inbox-notice"/);
    assert.match(v2, /PER_PAGE = 20/);
});

test('V2 inbox: tabs, no filled button on a card, a reason is required to reject, plain words', () => {
    const v2 = read('resources/js/pages/Agents/V2/Inbox.vue');
    assert.match(v2, /<V2Tabs/);
    assert.match(v2, /tone="info" label="AI agent"/);
    assert.match(v2, /reason-label="[^"]*"|'Reason for rejecting'/);
    assert.match(v2, /deciding\?\.action === 'reject' \? 'danger' : 'default'/);
    assert.match(v2, /shown\(line\.value\)/);
    // Approve and Reject on a card share one outline class: no filled button on the page itself.
    assert.doesNotMatch(
        v2.split('<V2Dialog')[0],
        /bg-primary|variant="default"/,
    );

    for (const text of ['Pending holds', 'Approved holds', 'Rejected holds']) {
        assert.ok(v2.includes(text));
    }
});

test('plainValue turns an upper-case code into a name and leaves other text alone', () => {
    const lib = read('resources/js/lib/agent-plain.ts');
    assert.match(lib, /titleCaseCode/);
    assert.match(lib, /\^\[A-Z\]\{3,\}\(\?:_\[A-Z\]\+\)\*\$/);
});

test('V2 inbox: after a refusal the dialog has one Close, focus moves to the message, and a decider id is not shown', () => {
    const v2 = read('resources/js/pages/Agents/V2/Inbox.vue');
    assert.match(v2, /:single-action="refused"/);
    assert.match(v2, /message\.value\?\.focus\(\)/);
    assert.match(v2, /another staff member/);
    assert.match(
        read('resources/js/components/v2/V2Dialog.vue'),
        /v-if="!singleAction"/,
    );
});
