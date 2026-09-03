<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CheckoutReopenEligibility
{
    /** Presentation hint only; the command independently rechecks under canonical locks. */
    public function allows(User $actor, Visit $visit): bool
    {
        if (! $actor->is_active || ! $actor->hasRole('resident_doctor') || ! $actor->can('consultations.reopen.own') || $actor->organisation_id !== $visit->organisation_id
            || $visit->status !== Visit::STATUS_REGISTERED || $visit->assigned_doctor_user_id !== $actor->id) {
            return false;
        }
        $checkout = ConsultationCheckout::query()->where('current_visit_guard', $visit->id)->where('attending_doctor_user_id', $actor->id)->where('route', 'billing')->first();
        if (! $checkout) {
            return false;
        }
        $ids = DB::table('invoices')->where('visit_id', $visit->id)->pluck('id');
        if (DB::table('invoices')->whereIn('id', $ids)->where('status', '<>', 'draft')->exists()) {
            return false;
        }
        foreach (['payment_allocations', 'coverage_allocations', 'patient_receivables'] as $table) {
            if (DB::table($table)->whereIn('invoice_id', $ids)->exists()) {
                return false;
            }
        }

        return true;
    }
}
