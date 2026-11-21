<?php

namespace App\Domain\Clinical\Triage;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * TRI-1a: one saved version of the clinic's triage guideline. Content never changes after insert; only the status
 * moves (draft → approved → replaced, or draft → discarded). Never deleted.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $version_number
 * @property string $status
 * @property int $child_age_limit_years
 * @property string $approving_doctor_name
 * @property CarbonImmutable $doctor_signed_on
 * @property int $red_flag_count
 * @property int $yellow_sign_count
 * @property int $threshold_count
 * @property int $entered_by_user_id
 * @property CarbonImmutable $entered_at
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $replaced_at
 * @property int|null $discarded_by_user_id
 * @property CarbonImmutable|null $discarded_at
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property int $lock_version
 */
class TriageGuidelineVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REPLACED = 'replaced';

    public const STATUS_DISCARDED = 'discarded';

    protected $table = 'triage_guideline_versions';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Triage guideline versions are retained.'));
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'child_age_limit_years' => 'integer',
            'red_flag_count' => 'integer',
            'yellow_sign_count' => 'integer',
            'threshold_count' => 'integer',
            'lock_version' => 'integer',
            'doctor_signed_on' => 'immutable_date',
            'entered_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'replaced_at' => 'immutable_datetime',
            'discarded_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<TriageGuidelineSign, $this> */
    public function signs(): HasMany
    {
        return $this->hasMany(TriageGuidelineSign::class, 'guideline_version_id')->orderBy('kind')->orderBy('position');
    }

    /** @return HasMany<TriageGuidelineThreshold, $this> */
    public function thresholds(): HasMany
    {
        return $this->hasMany(TriageGuidelineThreshold::class, 'guideline_version_id')->orderBy('id');
    }
}
