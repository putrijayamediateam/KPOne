<?php

namespace App\Domain\Visit\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
class VisitReasonAssignment extends Model
{
    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<VisitReason, $this> */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(VisitReason::class, 'visit_reason_catalogue_item_id');
    }
}
