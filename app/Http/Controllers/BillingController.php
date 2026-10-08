<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\BillingDirectoryService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function show(Request $request, Visit $visit, BillingDirectoryService $directory): Response
    {
        return Inertia::render('Billing/Show', ['billing' => $directory->detail($request->user(), $visit)]);
    }

    public function build(Request $request, Visit $visit, BillingBuilderService $service): RedirectResponse
    {
        $this->stayOnBilling($visit, fn () => $service->build($request->user(), $visit, $request->only(['expected_branch_id', 'lock_version'])));

        return to_route('billing.show', $visit);
    }

    public function finalize(Request $request, Visit $visit, Invoice $invoice, BillingBuilderService $service): RedirectResponse
    {
        $this->stayOnBilling($visit, fn () => $service->finalize($request->user(), $visit, $invoice, $request->only(['expected_branch_id', 'lock_version'])));

        return to_route('billing.show', $visit);
    }

    public function payment(Request $request, Visit $visit, Invoice $invoice, PaymentService $service): RedirectResponse
    {
        $this->stayOnBilling($visit, fn () => $service->add($request->user(), $visit, $invoice, $request->only(['expected_branch_id', 'lock_version', 'amount_sen', 'method', 'reference', 'idempotency_key'])));

        return to_route('billing.show', $visit);
    }

    public function propose(Request $request, Visit $visit, Invoice $invoice, string $kind, ResponsibilityService $service): RedirectResponse
    {
        $this->stayOnBilling($visit, fn () => $service->propose($request->user(), $visit, $invoice, $kind, $request->only(['expected_branch_id', 'lock_version', 'amount_sen', 'reason', 'due_date', 'panel_id', 'member_reference'])));

        return to_route('billing.show', $visit);
    }

    public function approve(Request $request, Visit $visit, Invoice $invoice, string $kind, string $proposal, ResponsibilityService $service): RedirectResponse
    {
        $this->stayOnBilling($visit, fn () => $service->approve($request->user(), $visit, $invoice, $kind, $proposal, $request->only(['expected_branch_id', 'lock_version', 'proposal_lock_version'])));

        return to_route('billing.show', $visit);
    }

    public function reverse(Request $request, Visit $visit, Invoice $invoice, Payment $payment, InvoiceCorrectionService $service): RedirectResponse
    {
        $this->stayOnBilling($visit, fn () => $service->reverse($request->user(), $visit, $invoice, $payment, $request->only(['expected_branch_id', 'lock_version', 'payment_lock_version', 'reason', 'recording_error_only'])));

        return to_route('billing.show', $visit);
    }

    public function void(Request $request, Visit $visit, Invoice $invoice, InvoiceCorrectionService $service): RedirectResponse
    {
        $this->stayOnBilling($visit, fn () => $service->void($request->user(), $visit, $invoice, $request->only(['expected_branch_id', 'lock_version', 'reason'])));

        return to_route('billing.show', $visit);
    }

    public function complete(Request $request, Visit $visit, CompleteVisitationService $service): RedirectResponse
    {
        $this->stayOnBilling($visit, fn () => $service->complete($request->user(), $visit, $request->only(['expected_branch_id', 'lock_version', 'visit_lock_version'])));

        return to_route('billing.show', $visit);
    }

    public function printInvoice(Request $request, Visit $visit, Invoice $invoice, BillingDirectoryService $directory): Response
    {
        $data = $directory->detail($request->user(), $visit, $invoice, true);
        abort_if($data['invoice']['status'] === 'draft', 404);

        return Inertia::render('Billing/Print', ['billing' => $data, 'receipt' => null]);
    }

    public function printReceipt(Request $request, Visit $visit, Invoice $invoice, Payment $payment, BillingDirectoryService $directory): Response
    {
        $data = $directory->detail($request->user(), $visit, $invoice, true);
        $receipt = null;
        foreach ($data['payments'] as $candidate) {
            if ($candidate['publicId'] === $payment->public_id) {
                $receipt = $candidate;
                break;
            }
        }
        abort_unless($receipt, 404);

        return Inertia::render('Billing/Print', ['billing' => $data, 'receipt' => $receipt]);
    }

    /**
     * NAV-02: a refused billing action must land back on this billing page, not on whatever
     * full page the session last recorded (the app sends Referrer-Policy: no-referrer).
     */
    private function stayOnBilling(Visit $visit, \Closure $action): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('billing.show', $visit));
        }
    }
}
