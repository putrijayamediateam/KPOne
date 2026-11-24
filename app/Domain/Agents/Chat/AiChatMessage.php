<?php

namespace App\Domain\Agents\Chat;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One message in the AI chatbox. Append-only (PostgreSQL also refuses update and delete); the retention purge is the only update:
 * it empties the encrypted text and figures once and sets `purged_at`. The text and figures are encrypted at rest.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $organisation_id
 * @property int $user_id
 * @property string $role
 * @property string|null $body
 * @property array<string, mixed>|null $figures
 * @property list<string>|null $tools
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $cost_sen
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $purged_at
 */
class AiChatMessage extends Model
{
    public $timestamps = false;

    protected $table = 'ai_chat_messages';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $message): void {
            if ($message->getOriginal('purged_at') !== null || ! $message->isDirty('purged_at')) {
                throw new LogicException('Chat messages are append-only.');
            }
        });
        static::deleting(fn () => throw new LogicException('Chat messages are append-only.'));
    }

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'figures' => 'encrypted:array', 'tools' => 'array', 'created_at' => 'datetime', 'purged_at' => 'datetime'];
    }
}
