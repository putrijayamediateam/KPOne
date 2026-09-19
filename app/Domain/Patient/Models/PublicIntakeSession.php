<?php

namespace App\Domain\Patient\Models;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Models\PublicCheckInLink;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property-read Organisation $organisation
 * @property-read Branch $branch
 * @property-read PublicCheckInLink $link
 */
#[Guarded([])]
#[Hidden(['nonce_digest', 'status_receipt_digest', 'submission_idempotency_key', 'payload_fingerprint'])]
class PublicIntakeSession extends Model
{
    protected $table = 'public_intake_sessions';

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<PublicCheckInLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(PublicCheckInLink::class, 'public_checkin_link_id');
    }
}
