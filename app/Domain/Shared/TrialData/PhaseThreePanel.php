<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPayment;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceChangeProposal;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PanelClaimAdjustmentService;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PanelPaymentCorrectionService;
use App\Domain\Visit\Billing\Services\PanelPriceProposalService;
use App\Domain\Visit\Billing\Services\PanelRemittanceService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TRIAL-DATA phase 3: Panel claims in every stage at every branch. Each branch gets its own fictional Panel patients,
 * each with one completed Panel visit (seen by the branch's second doctor, billed, the whole invoice approved for the
 * Panel), and a claim taken to a different stage. If a claim was already created for an approval (PNL-28a's automatic
 * claims, when that switch is on), it is reused, never duplicated. Each scenario runs in one transaction and is
 * skipped on a rerun when its patient already has a visit.
 */
final class PhaseThreePanel
{
    use ClinicFlow;

    /** The claim stage each of a branch's Panel patients reaches, by position (Panel B for the bulk claim). */
    public const SCENARIOS = [
        1 => 'Prepared, not yet submitted',
        2 => 'Submitted, with a Panel claim adjustment confirmed',
        3 => 'Queried by the Panel',
        4 => 'Accepted in full',
        5 => 'Partly accepted',
        6 => 'Rejected',
        7 => 'Partly paid',
        8 => 'Paid by a Panel remittance',
        9 => 'Payment recorded, then corrected (reversed)',
        10 => 'Bulk claim (Panel B), submitted',
        11 => 'Bulk claim (Panel B), submitted',
    ];

    public const AGREEMENT_REFERENCE = 'TRIAL-PANEL-PRICE-CHANGE';

    public function __construct(private readonly TrialDataContext $context) {}

    /** @return array<string, int> what was created, by kind */
    public function run(): array
    {
        $this->created = [];
        foreach ($this->context->branches as $code => $branch) {
            foreach (array_keys(self::SCENARIOS) as $position) {
                if ($position === 11) {
                    continue; // made together with 10
                }
                $patients = $position === 10 ? [$this->patient($code, $branch, 10), $this->patient($code, $branch, 11)] : [$this->patient($code, $branch, $position)];
                if (Visit::query()->whereIn('patient_id', array_map(fn (Patient $p): int => $p->id, $patients))->exists()) {
                    continue;
                }
                DB::transaction(function () use ($code, $branch, $position, $patients): void {
                    $allocations = array_map(fn (Patient $patient): CoverageAllocation => $this->panelVisit($code, $branch, $patient, $this->panel($position === 10 ? 1 : 0)), $patients);
                    $this->claimStage($code, $branch, $position, $allocations);
                });
                $this->count('panel_visits', count($patients));
            }
        }
        $this->priceProposals();

        return $this->created;
    }

    /** A fictional Panel patient, registered by the branch's first CA the first time it is needed. */
    private function patient(string $code, Branch $branch, int $position): Patient
    {
        $index = (int) array_search($code, array_keys(TrialDataContext::BRANCHES), true);
        $number = $index * count(self::SCENARIOS) + $position;
        $name = sprintf('Trial Panel Patient %02d', $number);
        $patient = Patient::query()->where('organisation_id', $this->context->organisation->id)->where('full_name', $name)->first();
        if ($patient !== null) {
            return $patient;
        }
        $this->count('patients');

        return app(PatientAdministrationService::class)->create($this->ca($code, $branch), [
            'full_name' => $name,
            'date_of_birth' => sprintf('%04d-%02d-10', 1960 + $number, ($number % 12) + 1),
            'sex' => $number % 2 === 0 ? 'female' : 'male',
            'nationality_code' => 'MY',
            'mobile_phone' => '+60123456789',
            'phone_country' => 'MY',
            'email' => sprintf('panel-patient-%02d@%s', $number, TrialDataContext::EMAIL_DOMAIN),
            'country_code' => 'MY',
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => sprintf('TRIALPANEL%04d', $number)]],
            'duplicate_override' => true,
            'expected_branch_id' => $branch->id,
        ]);
    }

    /** A completed Panel visit: consultation only, the whole invoice approved for the Panel. Returns the approval. */
    private function panelVisit(string $code, Branch $branch, Patient $patient, Panel $panel): CoverageAllocation
    {
        $visit = $this->register($code, $branch, $patient, 'doctor2', $panel, $patient->id);
        $this->queueAndCall($code, $branch, $visit, 'doctor2');
        $this->startConsultation($code, $branch, $visit, 'doctor2', $patient->id);
        app(CompleteConsultationService::class)->complete($this->context->as(TrialDataContext::key($code, 'doctor2'), $branch), $visit->refresh(), [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $this->queue($visit)->lock_version,
            'encounter_lock_version' => $this->encounter($visit)->lock_version,
            'lock_version' => null,
            'service_deliveries' => [],
        ]);

        $invoice = $this->bill($code, $branch, $visit);
        $responsibility = app(ResponsibilityService::class);
        $proposal = $responsibility->propose($this->ca($code, $branch), $visit->refresh(), $invoice->refresh(), 'panel', [
            'expected_branch_id' => $branch->id,
            'lock_version' => $invoice->lock_version,
            'amount_sen' => $this->due($invoice),
            'panel_id' => $panel->id,
            'member_reference' => $this->memberReference($patient),
            'reason' => 'Fictional trial: covered by the Panel',
        ]);
        $responsibility->approve($this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch), $visit->refresh(), $invoice->refresh(), 'panel', $proposal->public_id, [
            'expected_branch_id' => $branch->id,
            'lock_version' => $invoice->lock_version,
            'proposal_lock_version' => $proposal->refresh()->lock_version,
        ]);
        $this->completeVisit($code, $branch, $visit, $invoice);

        return CoverageAllocation::query()->where('invoice_id', $invoice->id)->where('status', 'approved')->sole();
    }

    /** @param list<CoverageAllocation> $allocations */
    private function claimStage(string $code, Branch $branch, int $position, array $allocations): void
    {
        $claims = app(PanelClaimService::class);
        $officer = fn () => $this->context->as('panel', $branch);
        $today = $this->today($branch)->toDateString();
        $base = ['expected_branch_id' => $branch->id];

        if ($position === 10) {
            $existing = PanelClaim::query()->whereIn('coverage_allocation_id', array_map(fn (CoverageAllocation $a): int => $a->id, $allocations))->get();
            // With automatic claims on (PNL-28a) the approvals already have prepared claims: submit those instead.
            foreach ($existing as $claim) {
                if ($claim->status === 'prepared') {
                    $claims->submit($officer(), $claim, $base + ['lock_version' => $claim->lock_version]);
                }
            }
            if ($existing->isEmpty()) {
                $claims->bulkCreate($officer(), $base + [
                    'submitted_date' => $today,
                    'remarks' => 'Fictional trial bulk claim',
                    'claims' => array_map(fn (CoverageAllocation $a): array => [
                        'allocation' => $a->public_id,
                        'allocation_lock_version' => $a->refresh()->lock_version,
                        'invoice_lock_version' => Invoice::query()->findOrFail($a->invoice_id)->lock_version,
                        'idempotency_key' => (string) Str::uuid(),
                    ], $allocations),
                ]);
            }
            $this->count('claims', 2);

            return;
        }

        $allocation = $allocations[0];
        $claim = $this->claimFor($allocation, $branch, $position !== 1);
        $this->count('claims');
        $fresh = fn (): PanelClaim => $claim->refresh();
        $amount = (int) $claim->claim_amount_sen;

        switch ($position) {
            case 2:
                $this->panelAdjustment($branch, $allocation);
                break;
            case 3:
                $claims->recordQuery($officer(), $fresh(), $base + ['lock_version' => $claim->lock_version, 'note' => 'Fictional trial: Panel asked for the referral letter']);
                break;
            case 4:
            case 7:
            case 8:
            case 9:
                $claims->recordDecision($officer(), $fresh(), $base + ['lock_version' => $claim->lock_version, 'decision' => 'accepted', 'accepted_amount_sen' => $amount, 'note' => 'Fictional trial: accepted in full']);
                break;
            case 5:
                $claims->recordDecision($officer(), $fresh(), $base + ['lock_version' => $claim->lock_version, 'decision' => 'partially_accepted', 'accepted_amount_sen' => intdiv($amount * 4, 5), 'note' => 'Fictional trial: one item not covered']);
                break;
            case 6:
                $claims->recordDecision($officer(), $fresh(), $base + ['lock_version' => $claim->lock_version, 'decision' => 'rejected', 'accepted_amount_sen' => 0, 'note' => 'Fictional trial: member not covered on the visit date']);
                break;
        }

        if ($position === 7 || $position === 9) {
            $payment = $claims->recordPayment($officer(), $fresh(), $base + [
                'amount_sen' => $position === 7 ? intdiv($amount, 2) : $amount,
                'received_date' => $today,
                'reference' => 'TRIAL-PAY-'.$claim->id,
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->count('claim_payments');
            if ($position === 9) {
                // Recorded by the Panel Officer, reversed by Finance (never the person who recorded it).
                app(PanelPaymentCorrectionService::class)->reverse($this->context->as('finance', $branch), $base + [
                    'idempotency_key' => (string) Str::uuid(),
                    'kind' => 'payment',
                    'target' => PanelClaimPayment::query()->findOrFail($payment->id)->public_id,
                    'reason_code' => 'recorded_twice',
                    'note' => 'Fictional trial: the same payment was entered twice',
                    'recording_error_only' => true,
                ]);
                $this->count('payment_corrections');
            }
        }
        if ($position === 8) {
            app(PanelRemittanceService::class)->record($this->context->as('finance', $branch), $base + [
                'idempotency_key' => (string) Str::uuid(),
                'panel' => $this->panel(0)->code,
                'amount_sen' => $amount,
                'received_date' => $today,
                'reference' => 'TRIAL-REMIT-'.$claim->id,
                'splits' => [['claim' => $claim->public_id, 'amount_sen' => $amount]],
            ]);
            $this->count('remittances');
        }
    }

    /**
     * The claim for an approval: an existing one (PNL-28a may have created it) is reused. A new claim is prepared, or
     * created directly as submitted.
     */
    private function claimFor(CoverageAllocation $allocation, Branch $branch, bool $submitted): PanelClaim
    {
        $claims = app(PanelClaimService::class);
        $officer = $this->context->as('panel', $branch);
        $claim = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->first();
        if ($claim === null && ! $submitted) {
            return $claims->prepare($officer, $allocation->refresh(), ['expected_branch_id' => $branch->id]);
        }
        if ($claim === null) {
            return $claims->create($officer, $allocation->refresh(), [
                'expected_branch_id' => $branch->id,
                'allocation_lock_version' => $allocation->lock_version,
                'invoice_lock_version' => Invoice::query()->findOrFail($allocation->invoice_id)->lock_version,
                'idempotency_key' => (string) Str::uuid(),
                'submitted_date' => $this->today($branch)->toDateString(),
                'remarks' => 'Fictional trial claim',
            ]);
        }
        if ($submitted && $claim->status === 'prepared') {
            $claim = $claims->submit($officer, $claim, ['expected_branch_id' => $branch->id, 'lock_version' => $claim->lock_version]);
        }

        return $claim;
    }

    /** A Panel service charge added to the claim: requested by the Panel Officer, confirmed by Finance. */
    private function panelAdjustment(Branch $branch, CoverageAllocation $allocation): void
    {
        $service = app(PanelClaimAdjustmentService::class);
        $adjustment = $service->request($this->context->as('panel', $branch), $allocation->refresh(), [
            'expected_branch_id' => $branch->id,
            'invoice_lock_version' => Invoice::query()->findOrFail($allocation->invoice_id)->lock_version,
            'direction' => 'add',
            'amount_sen' => 300,
            'reason_code' => 'panel_service_charge',
            'note' => 'Fictional trial: Panel administration charge',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $service->confirm($this->context->as('finance', $branch), $adjustment, [
            'expected_branch_id' => $branch->id,
            'lock_version' => $adjustment->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->count('claim_adjustments');
    }

    /** Two default Panel price changes from tomorrow: one approved by Finance, one left waiting. Proposed by the Panel Officer. */
    private function priceProposals(): void
    {
        $main = $this->context->mainBranch();
        if (PriceChangeProposal::query()->where('organisation_id', $this->context->organisation->id)->where('agreement_reference', self::AGREEMENT_REFERENCE)->exists()) {
            return;
        }
        $book = PriceBook::query()->where('organisation_id', $this->context->organisation->id)->where('scope_key', 'panel:default')->firstOrFail();
        $service = app(PanelPriceProposalService::class);
        foreach ([['TRIAL-INJ', 1300, true], ['TRIAL-SUTURE-OFF', 2200, false]] as [$chargeCode, $sen, $approve]) {
            $charge = ChargeDefinition::query()->where('organisation_id', $this->context->organisation->id)->where('code', $chargeCode)->firstOrFail();
            $version = (int) PriceEntry::query()->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->max('version');
            $proposal = $service->propose($this->context->as('panel', $main), $book, $charge, [
                'expected_branch_id' => $main->id,
                'expected_version' => $version,
                'amount_sen' => $sen,
                'agreement_reference' => self::AGREEMENT_REFERENCE,
                // From tomorrow: the phase 1 price already takes effect today.
                'effective_date' => $this->today($main)->addDay()->toDateString(),
                'idempotency_key' => (string) Str::uuid(),
            ]);
            if ($approve) {
                $service->approve($this->context->as('finance', $main), $proposal, ['expected_branch_id' => $main->id, 'lock_version' => $proposal->lock_version]);
            }
            $this->count('panel_price_proposals');
        }
    }
}
