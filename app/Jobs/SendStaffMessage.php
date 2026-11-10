<?php

namespace App\Jobs;

use App\Domain\HR\Messages\Services\MessageDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** HR-14: one staff message, sent after the HR change has committed. Holds ids and a fixed template only. */
class SendStaffMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** One try: a failed message is recorded, never retried into a duplicate. */
    public int $tries = 1;

    /**
     * @param  array<string, string>  $params
     * @param  array<string, int>  $subjectIds
     */
    public function __construct(
        public readonly int $organisationId,
        public readonly int $userId,
        public readonly string $template,
        public readonly array $params,
        public readonly array $subjectIds,
    ) {
        $this->afterCommit();
    }

    public function handle(MessageDelivery $delivery): void
    {
        $delivery->deliver($this->organisationId, $this->userId, $this->template, $this->params, $this->subjectIds);
    }
}
