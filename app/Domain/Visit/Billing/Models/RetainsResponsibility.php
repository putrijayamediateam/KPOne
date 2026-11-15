<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

trait RetainsResponsibility
{
    protected static function bootRetainsResponsibility(): void
    {
        static::updating(function (Model $record): void {
            $from = $record->getOriginal('status');
            $to = $record->getAttribute('status');
            if ($from === 'superseded' || ($to !== $from && ! (($from === 'proposed' && in_array($to, ['approved', 'superseded'], true)) || ($from === 'approved' && $to === 'superseded')))) {
                throw new LogicException('Responsibility evidence cannot be reactivated.');
            }
            $allowed = ['status', 'current_invoice_guard', 'lock_version', 'updated_at'];
            if ($from === 'proposed' && $to === 'approved') {
                $allowed = [...$allowed, 'approved_by_user_id', 'approved_at'];
            }
            if ($record instanceof PatientReceivable && $from === 'approved' && $to === 'approved') {
                $allowed = [...$allowed, 'remaining_sen', 'last_payment_id', 'settled_at'];
                if ($record->remaining_sen > $record->getOriginal('remaining_sen')) {
                    throw new LogicException('A prior deferment cannot silently expand.');
                }
            }
            if (array_diff(array_keys($record->getDirty()), $allowed) !== []) {
                throw new LogicException('Responsibility proposal and approval facts are immutable.');
            }
        });
    }
}
