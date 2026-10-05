<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PaymentMethodAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PaymentMethodController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('PaymentMethods/Index', [
            'methods' => PaymentMethod::query()
                ->where('organisation_id', $request->user()->organisation_id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (PaymentMethod $method): array => [
                    'id' => $method->id,
                    'code' => $method->code,
                    'name' => $method->name,
                    'description' => $method->description,
                    'requiresReference' => $method->requires_reference,
                    'sortOrder' => $method->sort_order,
                    'isActive' => $method->is_active,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request, PaymentMethodAdministrationService $service): RedirectResponse
    {
        try {
            $service->create($request->user(), $this->validated($request, creating: true));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('payment-methods.index'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment Method created as inactive.')]);

        return to_route('payment-methods.index');
    }

    public function update(Request $request, PaymentMethod $method, PaymentMethodAdministrationService $service): RedirectResponse
    {
        try {
            $service->update($request->user(), $method, $this->validated($request, creating: false));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('payment-methods.index'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment Method updated.')]);

        return to_route('payment-methods.index');
    }

    public function publish(Request $request, PaymentMethod $method, PaymentMethodAdministrationService $service): RedirectResponse
    {
        $service->publish($request->user(), $method);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment Method published.')]);

        return to_route('payment-methods.index');
    }

    public function deactivate(Request $request, PaymentMethod $method, PaymentMethodAdministrationService $service): RedirectResponse
    {
        $service->deactivate($request->user(), $method);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment Method deactivated.')]);

        return to_route('payment-methods.index');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'requires_reference' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:32767'],
        ];

        if ($creating) {
            $rules['code'] = ['required', 'string', 'max:40', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/'];
        } else {
            $rules['code'] = ['prohibited'];
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo(route('payment-methods.index'));
        }

        $attributes = $validator->validated();
        $attributes['description'] = filled($attributes['description'] ?? null)
            ? trim($attributes['description'])
            : null;

        return $attributes;
    }
}
