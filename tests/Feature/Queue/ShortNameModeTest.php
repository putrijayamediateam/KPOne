<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Display\QueueDisplayFeedService;
use App\Domain\Queue\Display\ShortDisplayName;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Q-3 gentler TV name mode (owner decisions 2026-11-05). */
class ShortNameModeTest extends QueueTestCase
{
    /** @return array<string, array{string|null, string|null}> */
    public static function names(): array
    {
        return [
            'Malay with bin' => ['Ahmad bin Ismail', 'Ahmad Ismail'],
            'Malay with binti' => ['Siti Nur Aisyah binti Ahmad', 'Siti Nur'],
            'Chinese' => ['Tan Mei Ling', 'Tan Mei'],
            'Indian with a/l' => ['Kumar a/l Raju', 'Kumar Raju'],
            'a/p' => ['Devi a/p Muthu', 'Devi Muthu'],
            's/o' => ['Raju s/o Muthu', 'Raju Muthu'],
            'd/o' => ['Priya d/o Kumar', 'Priya Kumar'],
            'at sign alias' => ['Ali @ Abu bin Hassan', 'Ali Abu'],
            'case-insensitive and trailing dot' => ['Nur BINTI Bt. Aminah', 'Nur Aminah'],
            'bte' => ['Aminah bte Salleh', 'Aminah Salleh'],
            'registered casing kept' => ['AHMAD BIN ISMAIL', 'AHMAD ISMAIL'],
            'extra spaces' => ['  Ahmad    bin   Ismail  ', 'Ahmad Ismail'],
            'hyphenated word' => ['Nur-Aisyah binti Ahmad', 'Nur-Aisyah Ahmad'],
            'punctuation-only token' => ['Ahmad - Ismail', 'Ahmad Ismail'],
            'one word' => ['Kumar', 'Kumar'],
            'long pair keeps the first word only' => ['Muhammadhafizuddin Shaharuddin', 'Muhammadhafizuddin'],
            'exactly 24 characters keeps both' => ['Abcdefghijk Mnopqrstuvwx', 'Abcdefghijk Mnopqrstuvwx'],
            'a very long first word is never cut' => ['Abcdefghijklmnopqrstuvwxyzab Lee', 'Abcdefghijklmnopqrstuvwxyzab'],
            'only skipped words' => ['bin binti a/l', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('names')]
    public function test_short_name_rules(?string $full, ?string $expected): void
    {
        $this->assertSame($expected, ShortDisplayName::from($full));
    }

    public function test_short_mode_sends_only_the_short_name_and_number_mode_stays_unchanged(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor, []);
        $visit->patient->forceFill(['full_name' => 'Synthetic Siti Nur binti Contoh'])->save();
        $entry = $this->send($ca, $visit);
        $this->callIn($doctor, $visit, $entry->lock_version);
        $feed = app(QueueDisplayFeedService::class);

        app(QueueDisplayAdministrationService::class)->updateSettings($supervisor, $this->branch, [
            'ticker_text' => null, 'youtube_url' => null, 'poster_seconds' => 10, 'call_display_mode' => 'short_name', 'lock_version' => 0,
        ]);
        $short = $feed->feed($this->branch);
        $this->assertSame('short_name', $short['settings']['callDisplayMode']);
        $this->assertSame('Synthetic Siti', $short['calls'][0]['name']);
        $this->assertSame('A-001', $short['calls'][0]['number']);
        $json = json_encode($short, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Synthetic Siti Nur binti Contoh', $json, 'the full name never reaches the TV');
        $this->assertStringNotContainsString('Contoh', $json);
        $this->assertSame(['id', 'number', 'room', 'service', 'calledAt', 'isRecall', 'name'], array_keys($short['calls'][0]));

        $display = $this->actor('queue_display');
        $this->selectBranch($display);
        $this->getJson(route('queue-display.feed'))->assertOk()
            ->assertJsonPath('calls.0.name', 'Synthetic Siti')
            ->assertJsonPath('settings.callDisplayMode', 'short_name');

        app(QueueDisplayAdministrationService::class)->updateSettings($supervisor, $this->branch, [
            'ticker_text' => null, 'youtube_url' => null, 'poster_seconds' => 10, 'call_display_mode' => 'number', 'lock_version' => 1,
        ]);
        $this->assertArrayNotHasKey('name', $feed->feed($this->branch)['calls'][0]);
    }

    public function test_a_name_of_only_skipped_words_falls_back_to_the_number(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $visit->patient->forceFill(['full_name' => 'bin binti'])->save();
        $entry = $this->send($ca, $visit);
        $this->callIn($doctor, $visit, $entry->lock_version);
        app(QueueDisplayAdministrationService::class)->updateSettings($supervisor, $this->branch, [
            'ticker_text' => null, 'youtube_url' => null, 'poster_seconds' => 10, 'call_display_mode' => 'short_name', 'lock_version' => 0,
        ]);

        $call = app(QueueDisplayFeedService::class)->feed($this->branch)['calls'][0];
        $this->assertNull($call['name'] ?? null);
        $this->assertSame('A-001', $call['number']);
    }

    public function test_postgres_accepts_the_three_modes_and_refuses_others(): void
    {
        $this->actor('ca_supervisor');
        app(QueueDisplayFeedService::class)->feed($this->branch);
        $supervisor = $this->actor('ca_supervisor');
        app(QueueDisplayAdministrationService::class)->updateSettings($supervisor, $this->branch, [
            'ticker_text' => null, 'youtube_url' => null, 'poster_seconds' => 10, 'call_display_mode' => 'short_name', 'lock_version' => 0,
        ]);
        $this->assertSame('short_name', DB::table('branch_display_settings')->where('branch_id', $this->branch->id)->value('call_display_mode'));
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('branch_display_settings')->where('branch_id', $this->branch->id)->update(['call_display_mode' => 'initials']));
    }

    private function callIn(User $doctor, Visit $visit, int $queueLockVersion): void
    {
        $this->selectBranch($doctor);
        app(QueueEntryService::class)->call($doctor, $visit->refresh(), [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $queueLockVersion,
        ]);
    }
}
