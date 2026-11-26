<?php

namespace App\Domain\Queue\Emergency;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $visit_id
 * @property int $queue_entry_id
 * @property int $raised_triage_assessment_id
 * @property string $raised_kind
 * @property CarbonImmutable $raised_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $close_code
 * @property int $claim_floor_id
 * @property int $lock_version
 * @property int|null $taken_claim_id
 */
class EmergencyAlert extends Model
{
    protected $table = 'queue_emergency_alerts';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['raised_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'lock_version' => 'integer', 'claim_floor_id' => 'integer', 'taken_claim_id' => 'integer'];
    }
}
