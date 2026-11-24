<?php

namespace App\Domain\Agents\Ask;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One question asked in the KPOne panel and answered through the Director's own Claude (CHAT-2). The question and answer are
 * encrypted. A row is never deleted; it leaves `pending` once (answered, expired or cancelled) and the retention purge empties
 * the text once (PostgreSQL enforces the same).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $user_id
 * @property string $request_code
 * @property string|null $request_key
 * @property string|null $question
 * @property string|null $answer
 * @property int $question_chars
 * @property string $status
 * @property CarbonInterface $created_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $answered_at
 * @property CarbonInterface|null $purged_at
 */
class AiAskRequest extends Model
{
    public const STATUSES = ['pending', 'answered', 'expired', 'cancelled'];

    public $timestamps = false;

    protected $table = 'ai_ask_requests';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Ask requests are never deleted.'));
    }

    protected function casts(): array
    {
        return ['question' => 'encrypted', 'answer' => 'encrypted', 'created_at' => 'datetime', 'expires_at' => 'datetime', 'answered_at' => 'datetime', 'purged_at' => 'datetime'];
    }
}
