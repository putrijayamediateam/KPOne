<?php

namespace App\Domain\Agents\Chat;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One provider attempt's spend (docs/agents/AI-CHATBOX-DESIGN.md section 5). Reserved before the provider is called and settled to the
 * real cost afterwards, also when the answer failed after tokens were spent. Never deleted.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $user_id
 * @property string $state
 * @property int $cost_sen
 * @property int $tokens_in
 * @property int $tokens_out
 * @property string|null $outcome
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $settled_at
 */
class AiChatUsage extends Model
{
    public $timestamps = false;

    protected $table = 'ai_chat_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'settled_at' => 'datetime'];
    }
}
