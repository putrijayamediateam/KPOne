<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Collection;
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

    /** Batch equivalent of allows() for already scoped queue rows. Commands still call their own authority.
     * @param  Collection<int,Visit>  $visits
     * @return array<int,bool>
     */
    public function forVisits(User $actor, Collection $visits): array
    {
        if (! $actor->is_active || ! $actor->hasRole('resident_doctor') || ! $actor->can('consultations.reopen.own')) {
            return [];
        }
        $ids = $visits->filter(fn (Visit $visit): bool => $visit->organisation_id === $actor->organisation_id && $visit->status === Visit::STATUS_REGISTERED && $visit->assigned_doctor_user_id === $actor->id)->map(fn (Visit $visit): int => $visit->id)->all();
        if ($ids === []) {
            return [];
        }
        $query = ConsultationCheckout::query()->whereIn('current_visit_guard', $ids)->where('attending_doctor_user_id', $actor->id)->where('route', 'billing')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices')->whereColumn('invoices.visit_id', 'consultation_checkouts.current_visit_guard')->where('invoices.status', '<>', 'draft'));
        foreach (['payment_allocations', 'coverage_allocations', 'patient_receivables'] as $table) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table.' as evidence')->join('invoices', 'invoices.id', '=', 'evidence.invoice_id')->whereColumn('invoices.visit_id', 'consultation_checkouts.current_visit_guard'));
        }

        return $query->pluck('current_visit_guard')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }
}
