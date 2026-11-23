<?php

namespace App\Domain\Agents\Proposals;

use App\Models\User;

/**
 * Implemented by a domain team (Inventory, for transfer and restock suggestions) so that approving an agent suggestion can also
 * prepare that team's own record (docs/agents/CONTRACT.md section 15). The agent platform calls it; the domain keeps its own rules.
 *
 * `prepare` runs inside the approval transaction, as the approving person, after the proposal row is locked and the decision
 * checks have passed. It must call the domain's normal service (permission, branch, row locks, status checks, audit as that
 * person) and throw a ValidationException or AuthorizationException to refuse, which rolls the whole approval back. It must be
 * retry-safe with `ProposalPreparers::retryKey()` and never trust agent text.
 */
interface PreparesWork
{
    /** @return list<string> the proposal types this preparer handles */
    public function types(): array;

    /**
     * Why this person cannot prepare this pending proposal, in plain words for the inbox, or null when they can. Read only.
     */
    public function cannotPrepareReason(User $approver, AiProposal $proposal): ?string;

    /**
     * @param  array<string, mixed>  $values  the proposal's payload with the approver's validated edits applied
     */
    public function prepare(User $approver, AiProposal $proposal, array $values): PreparedRecord;
}
