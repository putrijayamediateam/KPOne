<?php

namespace App\Domain\MyDay\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $title
 * @property int $position
 * @property CarbonImmutable|null $hidden_at
 *
 * DAY-1b: one line of a person's own routine. The title is encrypted; nobody but its owner reads it. */
final class MyRoutineItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'hidden_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
