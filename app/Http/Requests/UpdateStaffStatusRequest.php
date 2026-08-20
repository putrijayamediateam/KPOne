<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageStatus', $this->route('staff'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
