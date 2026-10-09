<?php

namespace App\Domain\Queue\Display;

use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Models\OtcQueueEntry;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Writes the immutable TV call record shared by consultation, dispensary and treatment-room calls. */
class QueueCallRecorder
{
    public function record(
        QueueEntry|OtcQueueEntry $entry,
        string $service,
        ?BranchRoom $room,
        User $actor,
        string $operationalDate,
        DateTimeInterface $calledAt,
        bool $isRecall,
    ): QueueCall {
        $call = new QueueCall;
        $call->forceFill([
            'organisation_id' => $entry->organisation_id,
            'branch_id' => $entry->branch_id,
            'queue_entry_id' => $entry instanceof QueueEntry ? $entry->id : null,
            'otc_queue_entry_id' => $entry instanceof OtcQueueEntry ? $entry->id : null,
            'queue_series' => $entry instanceof OtcQueueEntry ? QueueNumberFormat::OTC : QueueNumberFormat::CONSULTATION,
            'service' => $service,
            'is_recall' => $isRecall,
            'branch_room_id' => $room?->id,
            'room_name' => $room?->name,
            'queue_number' => $entry->queue_number,
            'operational_date' => $operationalDate,
            'called_by_user_id' => $actor->id,
            'called_at' => $calledAt,
        ])->save();

        return $call;
    }

    public function hasBeenCalled(QueueEntry|OtcQueueEntry $entry, string $service): bool
    {
        return QueueCall::query()
            ->where($this->column($entry), $entry->id)
            ->where('service', $service)
            ->exists();
    }

    /** The same patient is called to the same place at most once per cooldown. */
    public function assertCooldown(QueueEntry|OtcQueueEntry $entry, string $service): void
    {
        $lastCalledAt = QueueCall::query()
            ->where($this->column($entry), $entry->id)
            ->where('service', $service)
            ->max('called_at');
        if ($lastCalledAt !== null
            && now()->utc()->lt(Carbon::parse($lastCalledAt, 'UTC')->addSeconds(QueueCall::RECALL_COOLDOWN_SECONDS))) {
            throw ValidationException::withMessages([
                'queue' => 'This patient was just called. Wait a few seconds before calling again.',
            ]);
        }
    }

    private function column(QueueEntry|OtcQueueEntry $entry): string
    {
        return $entry instanceof OtcQueueEntry ? 'otc_queue_entry_id' : 'queue_entry_id';
    }
}
