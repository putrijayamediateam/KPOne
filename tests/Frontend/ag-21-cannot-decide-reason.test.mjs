import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the reason is read only: the service helper decides nothing and never names a person', () => {
    const service = read('app/Domain/Agents/Proposals/ProposalService.php');
    const inbox = read('app/Domain/Agents/Proposals/ProposalInbox.php');

    assert.match(service, /public function cannotDecideReason\(/);
    assert.match(
        service,
        /\$this->cannotDecideReason\(\$actor, \$proposal, \$assigned\) === null/,
    );
    assert.doesNotMatch(
        inbox,
        /'cannotDecideReason' => \$this->proposals->cannotDecideReason\(/,
    );
    assert.doesNotMatch(service, /requested_by_user_id\s*\.\s*'/);
});
