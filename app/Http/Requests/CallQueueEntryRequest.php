<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CallQueueEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('queue.call.branch') || $this->user()->can('queue.call.own');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'visit_lock_version' => ['required', 'integer', 'min:1'],
            'queue_lock_version' => ['required', 'integer', 'min:1'],
            'organisation_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'visit_id' => ['prohibited'],
            'queue_number' => ['prohibited'],
            'status' => ['prohibited'],
            'called_at' => ['prohibited'],
            'called_by_user_id' => ['prohibited'],
            'lock_version' => ['prohibited'],
        ];
    }
}
