<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Queue\Models\QueueEntry;
use Illuminate\Validation\ValidationException;

class ClinicalEncounterConcurrencyTest extends ClinicalTestCase
{
    public function test_two_tabs_cannot_silently_overwrite_clinical_content_or_diagnoses(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $tabVersion = $encounter->lock_version;
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
            'clinical_note' => 'Synthetic authoritative tab',
        ]));

        try {
            app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
                'lock_version' => $tabVersion,
                'clinical_note' => 'Synthetic stale tab',
                'diagnoses' => [[
                    'diagnosis_text' => 'Synthetic stale diagnosis',
                    'diagnosis_code' => null,
                    'code_system' => null,
                    'is_primary' => true,
                ]],
            ]));
            $this->fail('Stale aggregate save was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lock_version', $exception->errors());
        }

        $this->assertSame('Synthetic authoritative tab', $encounter->refresh()->clinical_note);
        $this->assertDatabaseMissing('encounter_diagnoses', ['diagnosis_text' => 'Synthetic stale diagnosis']);
    }

    public function test_queue_state_change_blocks_later_clinical_save(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $queue->forceFill([
            'status' => QueueEntry::STATUS_REMOVED,
            'removed_at' => now()->utc(),
            'lock_version' => $queue->lock_version + 1,
        ])->save();

        $this->expectException(ValidationException::class);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
    }
}
