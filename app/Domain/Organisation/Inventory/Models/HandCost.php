<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class HandCost extends Model
{
    protected $table = 'inventory_hand_costs';

    public $timestamps = false;

    protected $hidden = ['payload_hash', 'decision_payload_hash', 'idempotency_key', 'decision_idempotency_key'];

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Hand cost history is immutable.'));
    }

    protected function casts(): array
    {
        return ['note' => 'encrypted', 'cost_total_sen' => 'integer', 'lock_version' => 'integer', 'entered_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }
}
