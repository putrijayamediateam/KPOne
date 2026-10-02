<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentReconciliation;
use App\Domain\Visit\Billing\Services\PaymentReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PaymentReconciliationController extends Controller
{
    public function index(Request $request, BranchAccessService $branches): Response
    {
        $actor = $request->user();
        $branch = $branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);

        $dateValidator = Validator::make($request->query(), ['date' => ['nullable', 'date_format:Y-m-d']]);
        if ($dateValidator->fails()) {
            throw (new ValidationException($dateValidator))->redirectTo(route('payment-reconciliations.index'));
        }

        $queryDate = $request->query('date');
        $businessDate = is_string($queryDate) && $queryDate !== ''
            ? $queryDate
            : now()->setTimezone($branch->timezone)->toDateString();
        $localDate = CarbonImmutable::createFromFormat('!Y-m-d', $businessDate, $branch->timezone);
        if (! $localDate || $localDate->format('Y-m-d') !== $businessDate || $localDate->startOfDay()->greaterThan(now()->setTimezone($branch->timezone)->startOfDay())) {
            throw ValidationException::withMessages(['date' => 'Choose today or an earlier business date.'])
                ->redirectTo(route('payment-reconciliations.index'));
        }
        $start = $localDate->startOfDay()->utc();
        $end = $localDate->addDay()->startOfDay()->utc();

        $methods = PaymentMethod::query()
            ->where('organisation_id', $actor->organisation_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $methodNames = $methods->pluck('name', 'id');
        $totals = DB::table('payments')
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)
            ->whereIn('payment_method_id', $methods->pluck('id'))
            ->whereIn('status', ['posted', 'reversed'])
            ->where('received_at', '>=', $start)
            ->where('received_at', '<', $end)
            ->selectRaw('payment_method_id, status, COUNT(*) as payment_count, COALESCE(SUM(amount_sen), 0) as payment_total_sen')
            ->groupBy('payment_method_id', 'status')
            ->get()
            ->keyBy(fn (object $row): string => $row->payment_method_id.':'.$row->status);
        $reconciliations = PaymentReconciliation::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate)
            ->orderByDesc('revision')
            ->limit(30)
            ->get()
            ->map(fn (PaymentReconciliation $reconciliation): array => [
                'publicId' => $reconciliation->public_id,
                'method' => $methodNames->get($reconciliation->payment_method_id, 'Unavailable method'),
                'revision' => $reconciliation->revision,
                'businessDate' => $reconciliation->business_date->toDateString(),
                'terminalSalesCount' => $reconciliation->terminal_sales_count,
                'terminalSalesSen' => $reconciliation->terminal_sales_sen,
                'terminalRefundsCount' => $reconciliation->terminal_refunds_count,
                'terminalRefundsSen' => $reconciliation->terminal_refunds_sen,
                'terminalVoidsCount' => $reconciliation->terminal_voids_count,
                'terminalVoidsSen' => $reconciliation->terminal_voids_sen,
                'kponePaymentCount' => $reconciliation->kpone_payment_count,
                'kponePaymentTotalSen' => $reconciliation->kpone_payment_total_sen,
                'paymentCountVariance' => $reconciliation->payment_count_variance,
                'paymentTotalVarianceSen' => $reconciliation->payment_total_variance_sen,
                'terminalBatchReference' => $reconciliation->terminal_batch_reference,
                'varianceReason' => $reconciliation->variance_reason,
                'notes' => $reconciliation->notes,
                'reconciledAt' => $reconciliation->reconciled_at->setTimezone($branch->timezone)->format('Y-m-d H:i'),
            ]);

        return Inertia::render('PaymentReconciliations/Index', [
            'branch' => ['name' => $branch->name, 'timezone' => $branch->timezone],
            'branchId' => $branch->id,
            'businessDate' => $businessDate,
            'methods' => $methods->map(fn (PaymentMethod $method): array => [
                'id' => $method->id,
                'name' => $method->name,
                'code' => $method->code,
                'isActive' => $method->is_active,
                'requiresReference' => $method->requires_reference,
            ])->values(),
            'paymentsByMethod' => $methods->mapWithKeys(fn (PaymentMethod $method): array => [
                $method->id => [
                    'postedCount' => (int) ($totals->get($method->id.':posted')->payment_count ?? 0),
                    'postedTotalSen' => (int) ($totals->get($method->id.':posted')->payment_total_sen ?? 0),
                    'reversedCount' => (int) ($totals->get($method->id.':reversed')->payment_count ?? 0),
                    'reversedTotalSen' => (int) ($totals->get($method->id.':reversed')->payment_total_sen ?? 0),
                ],
            ]),
            'reconciliations' => $reconciliations,
        ]);
    }

    public function store(Request $request, PaymentReconciliationService $service, BranchAccessService $branches): RedirectResponse
    {
        $branch = $branches->activeBranch($request->user());
        $timezone = $branch !== null ? $branch->timezone : (string) config('app.timezone');
        $date = is_string($request->input('business_date')) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $request->input('business_date')) === 1
            ? $request->input('business_date')
            : now()->setTimezone($timezone)->toDateString();
        $validator = Validator::make($request->all(), [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'payment_method_id' => ['required', 'integer', 'min:1'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'terminal_sales_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'terminal_sales_total' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'terminal_refunds_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'terminal_refunds_total' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'terminal_voids_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'terminal_voids_total' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'terminal_batch_reference' => ['nullable', 'string', 'max:100'],
            'variance_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo(route('payment-reconciliations.index', ['date' => $date]));
        }

        try {
            $service->reconcile($request->user(), $validator->validated());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('payment-reconciliations.index', ['date' => $date]));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Terminal close recorded. Any correction must be recorded as a new revision.')]);

        return to_route('payment-reconciliations.index', ['date' => $date]);
    }
}
