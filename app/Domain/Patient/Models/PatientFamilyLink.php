<?php

namespace App\Domain\Patient\Models;

use Illuminate\Database\Eloquent\Model;

class PatientFamilyLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'lock_version' => 'integer'];
    }
}
