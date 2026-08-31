<?php

namespace App\Http\Requests;

use App\Domain\Queue\Models\QueueEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchQueueEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', QueueEntry::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $query = trim((string) $this->input('query', ''));

        return [
            'query' => [
                'nullable', 'string', 'max:255',
                Rule::when($query !== '' && preg_match('/\A\d+\z/', $query) !== 1, ['min:3']),
            ],
            'doctor_id' => ['nullable', 'integer'],
            'priority' => ['nullable', 'in:normal,urgent'],
            'status' => ['nullable', 'in:waiting,serving,removed'],
            'page' => ['nullable', 'integer', 'min:1'],
            'carry_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['query.min' => 'Enter at least 3 characters to search Patients.'];
    }
}
