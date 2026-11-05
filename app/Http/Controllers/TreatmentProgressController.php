<?php

namespace App\Http\Controllers;

use App\Domain\Queue\Treatment\TreatmentStepProjection;
use App\Domain\Queue\Treatment\TreatmentStepService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Q-2 treatment progress: start, done and cancel, plus the attending doctor's "done" signal. */
class TreatmentProgressController extends Controller
{
    public function store(Request $request, Visit $visit, TreatmentStepService $service): RedirectResponse
    {
        return $this->change($request, $visit, fn () => $service->start($request->user(), $visit, $request->all()), 'Treatment step started.');
    }

    public function done(Request $request, Visit $visit, TreatmentStepService $service): RedirectResponse
    {
        return $this->change($request, $visit, fn () => $service->complete($request->user(), $visit, $request->all()), 'Treatment step marked done. The doctor will be alerted.');
    }

    public function cancel(Request $request, Visit $visit, TreatmentStepService $service): RedirectResponse
    {
        return $this->change($request, $visit, fn () => $service->cancel($request->user(), $visit, $request->all()), 'Treatment step cancelled.');
    }

    public function updates(Request $request, TreatmentStepProjection $projection): JsonResponse
    {
        $data = $request->validate(['expected_branch_id' => ['required', 'integer', 'min:1'], 'cursor' => ['nullable', 'integer', 'min:0'], 'details' => ['sometimes', 'boolean']]);

        return response()->json($projection->updates($request->user(), (int) $data['expected_branch_id'], isset($data['cursor']) ? (int) $data['cursor'] : null, $request->boolean('details')))->header('Cache-Control', 'private, no-store');
    }

    private function change(Request $request, Visit $visit, callable $action, string $message): RedirectResponse
    {
        $target = $request->input('from') === 'consultation' ? route('encounters.show', $visit) : route('queue.index');
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($target);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($target);
    }
}
