<?php

namespace App\Domain\Agents\Chat;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One conversation in the AI chatbox. The title (the first question, cut short) is encrypted. Never deleted; the owner may archive it.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $user_id
 * @property string $surface
 * @property string $title
 * @property bool $is_archived
 * @property CarbonInterface $created_at
 * @property CarbonInterface $last_used_at
 */
class AiChatThread extends Model
{
    public const SURFACE_DIRECTOR_OVERVIEW = 'director_overview';

    public $timestamps = false;

    protected $table = 'ai_chat_threads';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Chat threads are never deleted.'));
    }

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'is_archived' => 'boolean', 'created_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    /** @return HasMany<AiChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'thread_id')->orderBy('id');
    }
}
