<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchTreatmentCatalogueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('treatment_plans.view.own');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'query' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}
