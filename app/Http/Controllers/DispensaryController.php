<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Dispensary\Models\DispensaryServiceLine;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\RoomCallService;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Visit\Models\Visit;
use App\Http\Requests\AcknowledgeDispensaryPartialRequest;
use App\Http\Requests\AddDispensaryItemRequest;
use App\Http\Requests\AddDispensaryServiceLineRequest;
use App\Http\Requests\ConfirmOtcAllergyRequest;
use App\Http\Requests\DispensaryCaseActionRequest;
use App\Http\Requests\EditDispensaryItemRequest;
use App\Http\Requests\EditDispensaryServiceLineRequest;
use App\Http\Requests\OpenOtcDispensaryRequest;
use App\Http\Requests\RemoveDispensaryItemRequest;
use App\Http\Requests\RemoveDispensaryServiceLineRequest;
use App\Http\Requests\SearchDispensaryMedicinesRequest;
use App\Http\Requests\SearchDispensaryServicesRequest;
use App\Http\Requests\UpdateDispensaryItemRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DispensaryController extends Controller
{
    public function show(Request $request, DispensaryCase $dispensaryCase, DispensaryDirectoryService $directory, RoomCallService $calls): Response
    {
        $detail = $directory->detail($request->user(), $dispensaryCase);
        if ($detail['status'] !== DispensaryCase::STATUS_COMPLETED && $dispensaryCase->case_type !== DispensaryCase::TYPE_OTC) {
            $detail['tvCall'] = [
                'canCall' => $request->user()->can('dispensary.start.branch')
                    && in_array($detail['status'], [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING], true),
                'rooms' => $calls->rooms(Branch::query()->findOrFail($dispensaryCase->branch_id), BranchRoom::KIND_DISPENSARY),
            ];
        }
        if ($detail['status'] === DispensaryCase::STATUS_COMPLETED && $request->user()->can('billing.view.branch')) {
            $detail['billingUrl'] = route('billing.show', $dispensaryCase->visit);
        }

        return Inertia::render($detail['status'] === DispensaryCase::STATUS_COMPLETED ? 'Dispensary/Completed' : 'Dispensary/Show', ['dispensary' => $detail]);
    }

    public function openOtc(OpenOtcDispensaryRequest $request, Visit $visit, OtcDispensaryService $service): RedirectResponse
    {
        try {
            $case = $service->open($request->user(), $visit, $request->validated());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('registration.index'));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispensing started for this OTC visit.']);

        return to_route('dispensary.show', $case);
    }

    public function confirmOtcAllergy(ConfirmOtcAllergyRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->confirmOtcAllergy($request->user(), $dispensaryCase, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Allergy statement recorded.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function labels(Request $request, DispensaryCase $dispensaryCase, DispensaryDirectoryService $directory, ?string $itemPublicId = null): Response
    {
        return Inertia::render('Dispensary/Labels', ['labels' => $directory->labels($request->user(), $dispensaryCase, $itemPublicId)]);
    }

    public function start(DispensaryCaseActionRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->start($request->user(), $dispensaryCase, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispensing started.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function updateItem(UpdateDispensaryItemRequest $request, DispensaryCase $dispensaryCase, DispensaryItem $item, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->updateItem($request->user(), $dispensaryCase, $item, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fulfilment updated.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function addItem(AddDispensaryItemRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->addItem($request->user(), $dispensaryCase, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Medicine added to the list.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function editItem(EditDispensaryItemRequest $request, DispensaryCase $dispensaryCase, DispensaryItem $item, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->editItem($request->user(), $dispensaryCase, $item, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Medicine saved.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function removeItem(RemoveDispensaryItemRequest $request, DispensaryCase $dispensaryCase, DispensaryItem $item, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->removeItem($request->user(), $dispensaryCase, $item, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Medicine removed from the list.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function searchMedicines(SearchDispensaryMedicinesRequest $request, DispensaryCase $dispensaryCase, DispensaryDirectoryService $directory): JsonResponse
    {
        return response()->json(['data' => $directory->searchMedicines($request->user(), $dispensaryCase, $request->validated('query'))]);
    }

    public function addService(AddDispensaryServiceLineRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->addServiceLine($request->user(), $dispensaryCase, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Service added to the list.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function editService(EditDispensaryServiceLineRequest $request, DispensaryCase $dispensaryCase, DispensaryServiceLine $line, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->editServiceLine($request->user(), $dispensaryCase, $line, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Service saved.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function removeService(RemoveDispensaryServiceLineRequest $request, DispensaryCase $dispensaryCase, DispensaryServiceLine $line, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->removeServiceLine($request->user(), $dispensaryCase, $line, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Service removed from the list.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function searchServices(SearchDispensaryServicesRequest $request, DispensaryCase $dispensaryCase, DispensaryDirectoryService $directory): JsonResponse
    {
        return response()->json(['data' => $directory->searchServices($request->user(), $dispensaryCase, $request->validated('query'))]);
    }

    public function returnToDoctor(DispensaryCaseActionRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->returnToDoctor($request->user(), $dispensaryCase, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Case returned to the attending doctor.']);

        return to_route('registration.index');
    }

    public function complete(DispensaryCaseActionRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        $this->stayOnCase($dispensaryCase, fn () => $service->complete($request->user(), $dispensaryCase, $request->validated()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispensary completed.']);

        return $request->user()->can('billing.view.branch') ? to_route('billing.show', $dispensaryCase->visit) : to_route('registration.index');
    }

    public function acknowledge(AcknowledgeDispensaryPartialRequest $request, DispensaryItemException $exception, DispensaryService $service): RedirectResponse
    {
        $service->acknowledge($request->user(), $exception, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Partial fulfilment acknowledged.']);

        return back();
    }

    /**
     * NAV-02: a refused action must land back on this Dispensary case, not on whatever
     * full page the session last recorded (the app sends Referrer-Policy: no-referrer).
     */
    private function stayOnCase(DispensaryCase $dispensaryCase, \Closure $action): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('dispensary.show', $dispensaryCase));
        }
    }
}
