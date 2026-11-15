<?php

namespace App\Http\Requests;

use App\Domain\Visit\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;

class StoreVisitReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Visit::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120']];
    }
}
