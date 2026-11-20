<?php

namespace App\Domain\Clinical\Triage;

use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * TRI-1a: one append-only triage save. Never updated or deleted (PostgreSQL refuses both); a correction is a new row.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $visit_id
 * @property int $queue_entry_id
 * @property int $sequence
 * @property int|null $systolic_bp
 * @property int|null $diastolic_bp
 * @property int|null $pulse_bpm
 * @property string|null $temperature_celsius
 * @property string|null $spo2_percent
 * @property string|null $weight_kg
 * @property string|null $height_cm
 * @property list<string> $red_flag_codes
 * @property list<string> $yellow_sign_codes
 * @property string|null $age_band
 * @property int|null $guideline_version_id
 * @property string|null $suggested_zone
 * @property string|null $chosen_zone
 * @property string|null $override_reason_code
 * @property int $recorded_by_user_id
 * @property CarbonImmutable $recorded_at
 * @property string $idempotency_key
 * @property string $payload_hash
 */
class VisitTriageAssessment extends Model
{
    public const UPDATED_AT = null;

    public const READINGS = ['systolic_bp', 'diastolic_bp', 'pulse_bpm', 'temperature_celsius', 'spo2_percent', 'weight_kg', 'height_cm'];

    protected $table = 'visit_triage_assessments';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Triage assessments are append-only.'));
        static::deleting(fn () => throw new LogicException('Triage assessments are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'systolic_bp' => 'integer',
            'diastolic_bp' => 'integer',
            'pulse_bpm' => 'integer',
            'temperature_celsius' => 'decimal:2',
            'spo2_percent' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'red_flag_codes' => CodeArrayCast::class,
            'yellow_sign_codes' => CodeArrayCast::class,
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
