<?php

namespace App\Http\Requests;

use App\Domain\Queue\Models\QueueEntry;
use Illuminate\Foundation\Http\FormRequest;

class SendToWaitingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', QueueEntry::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'visit_lock_version' => ['required', 'integer', 'min:1'],
            'organisation_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'visit_id' => ['prohibited'],
            'queue_number' => ['prohibited'],
            'status' => ['prohibited'],
            'queued_at' => ['prohibited'],
            'called_at' => ['prohibited'],
            'removed_at' => ['prohibited'],
        ];
    }
}
