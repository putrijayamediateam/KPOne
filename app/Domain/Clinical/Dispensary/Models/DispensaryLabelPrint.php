<?php

namespace App\Domain\Clinical\Dispensary\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * DSP-6: append-only log of one drug label print for one medicine line. References only, never label text.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $dispensary_case_id
 * @property int $dispensary_item_id
 * @property int $print_number
 * @property int $printed_by_user_id
 * @property CarbonImmutable $printed_at
 */
#[Guarded(['*'])]
class DispensaryLabelPrint extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary label prints are append-only.'));
        static::updating(fn (): never => throw new LogicException('Dispensary label prints are append-only.'));
    }

    protected function casts(): array
    {
        return ['printed_at' => 'immutable_datetime'];
    }
}
