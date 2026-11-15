<?php

namespace App\Http\Controllers\HR;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Claims\Services\ClaimService;
use App\Domain\HR\Claims\Services\ClaimsPageService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-6 staff claims. Thin: every rule lives in app/Domain/HR/Claims. */
class ClaimsController extends Controller
{
    public function index(Request $request, ClaimsPageService $pages, BranchAccessService $branches): Response
    {
        return Inertia::render('HR/Claims', ['claims' => $pages->page($request->user(), $branches->activeBranch($request->user()), $request->only(['tab', 'month']))]);
    }

    public function send(Request $request, ClaimService $claims, BranchAccessService $branches): RedirectResponse
    {
        $back = route('hr.claims.index', ['tab' => 'my']);
        $receipts = $this->receipts($request, $back);

        return $this->run($request, $back, 'Claim sent. Someone else approves it.', fn () => $claims->send($request->user(), $branches->activeBranch($request->user()), $receipts,
            $request->only(['category_id', 'spent_on', 'amount', 'purpose', 'copy_from', 'idempotency_key'])));
    }

    public function withdraw(Request $request, ClaimService $claims, string $claim): RedirectResponse
    {
        return $this->run($request, route('hr.claims.index', ['tab' => 'my']), 'Claim withdrawn.',
            fn () => $claims->withdraw($request->user(), $claim, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function decide(Request $request, ClaimService $claims, string $claim, string $decision): RedirectResponse
    {
        return $this->run($request, route('hr.claims.index', ['tab' => 'approvals']), $decision === 'approve' ? 'Claim approved.' : 'Claim rejected.',
            fn () => $claims->decide($request->user(), $claim, $decision, $request->only(['reason', 'note', 'expected_lock_version', 'idempotency_key'])));
    }

    public function pay(Request $request, ClaimService $claims, string $claim): RedirectResponse
    {
        return $this->run($request, route('hr.claims.index', ['tab' => 'pay']), 'Payment recorded. KPOne pays nothing; this records that it was paid.',
            fn () => $claims->pay($request->user(), $claim, $request->only(['method', 'paid_on', 'reference', 'expected_lock_version', 'idempotency_key'])));
    }

    public function reverse(Request $request, ClaimService $claims, string $claim): RedirectResponse
    {
        return $this->run($request, route('hr.claims.index', ['tab' => 'pay']), 'Payment reversed. The claim is back to Approved.',
            fn () => $claims->reversePayment($request->user(), $claim, $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    public function saveSettings(Request $request, ClaimService $claims): RedirectResponse
    {
        return $this->run($request, route('hr.claims.index', ['tab' => 'settings']), 'Settings saved.',
            fn () => $claims->saveSettings($request->user(), $request->only(['director_level', 'days_back', 'expected_version', 'idempotency_key'])));
    }

    public function saveCategory(Request $request, ClaimService $claims): RedirectResponse
    {
        return $this->run($request, route('hr.claims.index', ['tab' => 'settings']), 'Category saved.', fn () => $claims->saveCategory($request->user(),
            $request->only(['category_id', 'name', 'needs_receipt', 'per_claim_limit', 'monthly_limit', 'is_active', 'expected_lock_version', 'idempotency_key'])));
    }

    /** A photo opens in the browser and a PDF downloads; the open is recorded first. */
    public function openReceipt(Request $request, ClaimService $claims, string $receipt): HttpResponse
    {
        try {
            $opened = $claims->openReceipt($request->user(), $receipt);
        } catch (AuthorizationException) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            abort(403);
        }

        return response($opened['content'], 200, [
            'Content-Type' => $opened['mime'],
            'Content-Disposition' => ($opened['inline'] ? 'inline' : 'attachment').'; filename="'.$opened['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Checks every receipt by content before anything is written; one submit names every field to fix.
     *
     * @return list<array{content:string, kind:string, bytes:int, sha256:string}>
     */
    private function receipts(Request $request, string $back): array
    {
        $files = $request->file('receipts', []);
        $files = is_array($files) ? array_values($files) : [$files];
        $messages = [];
        $prepared = [];
        if (count($files) > ClaimService::MAX_RECEIPTS) {
            $messages['receipts'] = ['Up to 5 receipts.'];
        } else {
            foreach ($files as $i => $file) {
                try {
                    $prepared[] = ClaimService::prepare($file, "receipts.{$i}");
                } catch (ValidationException $e) {
                    $messages = [...$messages, ...$e->errors()];
                }
            }
        }
        if ($messages !== []) {
            try {
                ClaimService::validated($request->only(['category_id', 'spent_on', 'amount', 'purpose']));
            } catch (ValidationException $other) {
                $messages = [...$other->errors(), ...$messages];
            }
            throw ValidationException::withMessages($messages)->redirectTo($back);
        }

        return $prepared;
    }

    private function run(Request $request, string $back, string $message, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['claims' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}
