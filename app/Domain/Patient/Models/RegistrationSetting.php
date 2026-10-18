<?php

namespace App\Domain\Patient\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class RegistrationSetting extends Model
{
    protected function casts(): array
    {
        return ['require_date_of_birth' => 'boolean', 'require_nationality' => 'boolean', 'require_email' => 'boolean', 'require_address' => 'boolean', 'lock_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Registration settings are retained.'));
    }
}
