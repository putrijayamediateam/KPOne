import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the V2 inbox shows the server\'s own reason for "you cannot decide" and keeps a plain fallback', () => {
    const page = read('resources/js/pages/Agents/V2/Inbox.vue');

    assert.match(page, /cannotDecideReason: string \| null;/);
    assert.match(page, /proposal\.cannotDecideReason \?\?/);
    assert.match(
        page,
        /'You cannot decide this one\. Someone else must decide\.'/,
    );
    // The paragraph still appears only for a pending proposal the person cannot decide.
    assert.match(
        page,
        /proposal\.status === 'pending' &&\s*!proposal\.canDecide/,
    );
    assert.match(page, /data-test="cannot-decide"/);
});

test('the reason is read only: the service helper decides nothing and never names a person', () => {
    const service = read('app/Domain/Agents/Proposals/ProposalService.php');
    const inbox = read('app/Domain/Agents/Proposals/ProposalInbox.php');

    assert.match(service, /public function cannotDecideReason\(/);
    assert.match(
        service,
        /\$this->cannotDecideReason\(\$actor, \$proposal, \$assigned\) === null/,
    );
    assert.match(inbox, /'cannotDecideReason' => \$this->proposals->cannotDecideReason\(/);
    assert.doesNotMatch(service, /requested_by_user_id\s*\.\s*'/);
});
