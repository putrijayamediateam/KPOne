<?php

namespace App\Http\Requests;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Visit\Models\Visit;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class AcknowledgeDispensaryPartialRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): never
    {
        $exception = $this->route('exception');
        $visit = $exception instanceof DispensaryItemException
            ? Visit::query()->whereKey(DispensaryCase::query()->whereKey($exception->dispensary_case_id)->where('organisation_id', $this->user()->organisation_id)->value('visit_id'))->first()
            : null;
        throw (new ValidationException($validator))->redirectTo($visit ? route('encounters.show', $visit) : route('queue.index'));
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['case_lock_version' => ['required', 'integer', 'min:1'], 'item_lock_version' => ['required', 'integer', 'min:1']];
    }
}
