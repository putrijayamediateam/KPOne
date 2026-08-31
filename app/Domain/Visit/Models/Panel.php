<?php

namespace App\Domain\Visit\Models;

use App\Domain\Organisation\Models\Organisation;
use Database\Factories\PanelFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property-read Organisation $organisation
 */
#[Guarded(['*'])]
class Panel extends Model
{
    /** @use HasFactory<PanelFactory> */
    use HasFactory;

    protected static function newFactory(): PanelFactory
    {
        return PanelFactory::new();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return HasMany<Visit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
