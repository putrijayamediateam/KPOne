<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * NAV-02: the app sends Referrer-Policy: no-referrer, so a bare failed-validation
 * redirect resolves to the last full page the session recorded (for a shared
 * browser, the patient's QR status page) instead of the Dispensary case the
 * form lives on. Requests using this trait are only submitted from that case
 * page, so the redirect target is explicit.
 */
trait RedirectsValidationFailuresToDispensaryCase
{
    /**
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): never
    {
        $exception = new ValidationException($validator);
        $case = $this->route('dispensaryCase');

        throw $case instanceof DispensaryCase ? $exception->redirectTo(route('dispensary.show', $case)) : $exception;
    }
}
