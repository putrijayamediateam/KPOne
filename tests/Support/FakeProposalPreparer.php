<?php

namespace Tests\Support;

use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\PreparedRecord;
use App\Domain\Agents\Proposals\PreparesWork;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A stand-in for a domain team's preparer: records every call, can refuse, makes no real record. */
final class FakeProposalPreparer implements PreparesWork
{
    /** @var list<array{actor: int, proposal: int, values: array<string, mixed>, transactionLevel: int}> */
    public array $calls = [];

    public ?string $cannotReason = null;

    public ?string $refuseWith = null;

    /** @param list<string> $types */
    public function __construct(private array $types = ['inventory.restock_suggestion', 'inventory.transfer_suggestion']) {}

    public function types(): array
    {
        return $this->types;
    }

    public function cannotPrepareReason(User $approver, AiProposal $proposal): ?string
    {
        return $this->cannotReason;
    }

    public function prepare(User $approver, AiProposal $proposal, array $values): PreparedRecord
    {
        $this->calls[] = ['actor' => $approver->id, 'proposal' => $proposal->id, 'values' => $values, 'transactionLevel' => DB::transactionLevel()];
        if ($this->refuseWith !== null) {
            throw ValidationException::withMessages(['quantity' => $this->refuseWith]);
        }

        return new PreparedRecord('inventory.stock_request', '11111111-1111-4111-8111-111111111111', 'SR-000001');
    }
}
