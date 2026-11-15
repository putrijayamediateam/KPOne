<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Support\Facades\Date;

/**
 * Q-1: the boards colour a waiting patient's time amber from 30 minutes and
 * red from 60 minutes in the browser. The colour is computed from timing
 * fields both payloads already carried (Q-1 adds no backend field); this
 * pins those fields so a later payload change cannot silently switch the
 * warning off.
 */
class WaitingTimeBoardTest extends QueueTestCase
{
    public function test_queue_board_row_carries_the_queued_timestamp_and_waiting_minutes(): void
    {
        try {
            Date::setTestNow('2026-11-05 03:00:00 UTC');
            $ca = $this->actor('ca');
            $entry = $this->send($ca, $this->consultationVisit($ca, $this->doctor()));
            $entry->forceFill(['queued_at' => now()->subMinutes(61)])->save();

            $snapshot = app(QueueDirectoryService::class)->snapshot($ca);
            $row = collect($snapshot['waiting']['data'])->firstWhere('visitNumber', $entry->visit->visit_number);

            $this->assertSame('waiting', $row['status']);
            $this->assertSame(now()->subMinutes(61)->toIso8601String(), $row['queuedAt']);
            $this->assertSame(61, $row['waitingMinutes']);
            $this->assertSame(now()->toIso8601String(), $snapshot['serverNow']);
            $this->assertFalse($row['isHeld']);
        } finally {
            Date::setTestNow();
        }
    }

    public function test_registration_waiting_row_carries_status_and_minutes_since_joining_the_queue(): void
    {
        try {
            Date::setTestNow('2026-11-05 03:00:00 UTC');
            $ca = $this->actor('ca');
            $entry = $this->send($ca, $this->consultationVisit($ca, $this->doctor()));
            $entry->forceFill(['queued_at' => now()->subMinutes(31)])->save();

            $row = app(VisitDirectoryService::class)->search($ca, [])['data']
                ->firstWhere('visitNumber', $entry->visit->visit_number);

            $this->assertSame('waiting', $row['queueStatus']);
            $this->assertSame(31, $row['durationMinutes']);
            $this->assertFalse($row['isHeld']);
        } finally {
            Date::setTestNow();
        }
    }

    public function test_a_serving_entry_has_no_waiting_minutes_so_it_is_never_coloured(): void
    {
        try {
            Date::setTestNow('2026-11-05 03:00:00 UTC');
            $ca = $this->actor('ca_supervisor');
            $visit = $this->consultationVisit($ca, $this->doctor());
            $entry = $this->send($ca, $visit);
            Date::setTestNow('2026-11-05 04:30:00 UTC');
            app(QueueEntryService::class)->call($ca, $visit, [
                'expected_branch_id' => $this->branch->id,
                'visit_lock_version' => $visit->lock_version,
                'queue_lock_version' => $entry->lock_version,
            ]);
            Date::setTestNow('2026-11-05 04:35:00 UTC');

            $snapshot = app(QueueDirectoryService::class)->snapshot($ca);
            $row = collect($snapshot['serving'])->firstWhere('visitNumber', $visit->visit_number);

            $this->assertNotNull($row);
            $this->assertSame(QueueEntry::STATUS_SERVING, $row['status']);
            $this->assertNull($row['waitingMinutes']);

            $registrationRow = app(VisitDirectoryService::class)->search($ca, [])['data']
                ->firstWhere('visitNumber', $visit->visit_number);
            $this->assertSame(QueueEntry::STATUS_SERVING, $registrationRow['queueStatus']);
            // Minutes since Call In, not since joining the queue (90 minutes ago).
            $this->assertSame(5, $registrationRow['durationMinutes']);
        } finally {
            Date::setTestNow();
        }
    }
}
