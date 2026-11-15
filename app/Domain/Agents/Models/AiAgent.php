<?php

namespace App\Domain\Agents\Models;

use App\Domain\Organisation\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A registered AI agent. Never deleted: disable it instead (a PostgreSQL trigger also refuses deletes).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $display_name
 * @property string $job_title
 * @property int|null $department_id
 * @property string $kind
 * @property string|null $avatar_key
 * @property bool $is_enabled
 * @property int $lock_version
 */
class AiAgent extends Model
{
    protected $table = 'ai_agents';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Agents are never deleted; disable them instead.');
        });
    }

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<AiAgentTool, $this> */
    public function tools(): HasMany
    {
        return $this->hasMany(AiAgentTool::class, 'ai_agent_id');
    }

    /** @return HasMany<AiAgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AiAgentRun::class, 'ai_agent_id');
    }

    public function allows(string $toolCode): bool
    {
        return $this->tools()->where('tool_code', $toolCode)->exists();
    }
}
