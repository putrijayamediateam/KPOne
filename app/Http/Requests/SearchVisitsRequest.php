<?php

namespace App\Http\Requests;

use App\Domain\Visit\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;

class SearchVisitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Visit::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_query' => ['nullable', 'string', 'min:3', 'max:255'],
            'doctor_id' => ['nullable', 'integer'],
            'visit_type' => ['nullable', 'in:consultation,otc'],
            'priority' => ['nullable', 'in:normal,urgent'],
            'coverage_type' => ['nullable', 'in:self_pay,panel'],
            'status' => ['nullable', 'in:registered,cancelled'],
            'board_status' => ['nullable', 'in:all,waiting,serving,dispensary,completed,cancelled'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'patient_query.min' => 'Enter at least 3 characters to search Patients or Visits.',
            'date_from.date_format' => 'Choose a valid start date.',
            'date_to.date_format' => 'Choose a valid end date.',
        ];
    }
}
