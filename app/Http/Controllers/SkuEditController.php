<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\PackConversionService;
use App\Domain\Organisation\Inventory\Services\SkuEditDirectoryService;
use App\Domain\Organisation\Inventory\Services\SkuEditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-14: the general SKU edit screen. Business rules live in the domain services. */
final class SkuEditController extends Controller
{
    public function index(Request $request, SkuEditDirectoryService $directory): Response
    {
        return Inertia::render('Inventory/Skus', ['skus' => $directory->page($request->user(), $request->query())]);
    }

    public function update(Request $request, string $sku, SkuEditService $service): RedirectResponse
    {
        try {
            $service->save($request->user(), $sku, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.skus'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'SKU saved.']);

        return to_route('inventory.skus');
    }

    /** STK-52: propose a pack conversion version (or to stop using one). */
    public function proposeConversion(Request $request, string $sku, PackConversionService $service): RedirectResponse
    {
        try {
            $service->propose($request->user(), $sku, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo($this->backToList($request));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Proposed. Someone else must check it before Dispensary uses it.']);

        return redirect($this->backToList($request));
    }

    /** STK-52: the blind check. A wrong count is recorded as refused and never repeats the proposed number. */
    public function checkConversion(Request $request, string $conversion, PackConversionService $service): RedirectResponse
    {
        try {
            $result = $service->check($request->user(), $conversion, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo($this->backToList($request));
        }
        if ($result === 'refused') {
            return redirect($this->backToList($request))->withErrors(['checked_base_per_pack' => PackConversionService::MISMATCH_MESSAGE]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Checked. Dispensary can now use this pack conversion.']);

        return redirect($this->backToList($request));
    }

    public function refuseConversion(Request $request, string $conversion, PackConversionService $service): RedirectResponse
    {
        try {
            $service->refuse($request->user(), $conversion, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo($this->backToList($request));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Refused. The pack conversion in use is unchanged.']);

        return redirect($this->backToList($request));
    }

    public function withdrawConversion(Request $request, string $conversion, PackConversionService $service): RedirectResponse
    {
        try {
            $service->withdraw($request->user(), $conversion, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo($this->backToList($request));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Withdrawn.']);

        return redirect($this->backToList($request));
    }

    /** STK-52: back to the same filtered list, so the SKU (and its message) stays on screen. */
    private function backToList(Request $request): string
    {
        $q = mb_substr(trim((string) $request->input('return_q', '')), 0, 100);
        $status = in_array($request->input('return_status'), ['active', 'inactive', 'all'], true) ? (string) $request->input('return_status') : null;

        return route('inventory.skus', array_filter(['q' => $q === '' ? null : $q, 'status' => $status]));
    }
}
