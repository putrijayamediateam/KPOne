<?php

namespace App\Domain\Queue\Models;

use App\Domain\Organisation\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property string $kind
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 * @property int $lock_version
 * @property int $updated_by_user_id
 * @property-read Branch $branch
 */
#[Guarded(['*'])]
class BranchRoom extends Model
{
    public const KIND_CONSULTATION = 'consultation';

    public const KIND_DISPENSARY = 'dispensary';

    public const KIND_TREATMENT = 'treatment';

    public const KINDS = [self::KIND_CONSULTATION, self::KIND_DISPENSARY, self::KIND_TREATMENT];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
