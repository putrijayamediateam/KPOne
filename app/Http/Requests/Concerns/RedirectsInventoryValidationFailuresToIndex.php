<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * NAV-01: Laravel's default failedValidation() redirects to url()->previous(),
 * which for an Inertia SPA - where almost all navigation is client-side XHR,
 * never a full page load - is very often not the page the request came from
 * (see InventoryReferenceController and OH-06d before it). Every request
 * using this trait is submitted only from the Inventory Stock Setup panel, so
 * the redirect target is explicit rather than inherited.
 */
trait RedirectsInventoryValidationFailuresToIndex
{
    /**
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): never
    {
        throw (new ValidationException($validator))->redirectTo(route('inventory.index'));
    }
}
