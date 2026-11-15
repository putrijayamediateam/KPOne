<?php

namespace App\Http\Controllers;

use App\Domain\Queue\Readiness\CallHoldProjection;
use App\Domain\Queue\Readiness\CallHoldService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class QueueReadinessController extends Controller
{
    public function store(Request $request, Visit $visit, CallHoldService $service): RedirectResponse
    {
        return $this->change($request, $visit, $service, false);
    }

    public function clear(Request $request, Visit $visit, CallHoldService $service): RedirectResponse
    {
        return $this->change($request, $visit, $service, true);
    }

    private function change(Request $request, Visit $visit, CallHoldService $service, bool $clear): RedirectResponse
    {
        $target = $request->input('from') === 'registration' ? route('registration.index') : route('queue.index');
        try {
            $service->change($request->user(), $visit, $request->all(), $clear);
        } catch (ValidationException $e) {
            throw $e->redirectTo($target);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $clear ? 'The not-ready flag has been cleared.' : 'The queue entry is marked not ready to call.']);

        return redirect($target);
    }

    public function updates(Request $request, CallHoldProjection $projection): JsonResponse
    {
        $data = $request->validate(['expected_branch_id' => ['required', 'integer', 'min:1'], 'cursor' => ['nullable', 'integer', 'min:0'], 'details' => ['sometimes', 'boolean']]);

        return response()->json($projection->updates($request->user(), (int) $data['expected_branch_id'], isset($data['cursor']) ? (int) $data['cursor'] : null, $request->boolean('details')))->header('Cache-Control', 'private, no-store');
    }
}
