<?php

namespace App\Domain\Agents\Proposals;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An agent's proposal waiting for, or holding, a person's decision. Created and decided only through
 * ProposalService; never deleted (a PostgreSQL trigger also refuses deletes and changes after a decision).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $ai_agent_run_id
 * @property int $ai_agent_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string $status
 * @property int|null $decided_by_user_id
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property int $lock_version
 * @property string $idempotency_key
 * @property CarbonInterface|null $created_at
 */
class AiProposal extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'withdrawn', 'expired'];

    protected $table = 'ai_proposals';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Agent proposals are never deleted.'));
    }

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'decided_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<AiAgent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    /** @return BelongsTo<AiAgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiAgentRun::class, 'ai_agent_run_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
