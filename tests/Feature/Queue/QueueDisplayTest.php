<?php

namespace Tests\Feature\Queue;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Models\BranchDisplaySetting;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class QueueDisplayTest extends QueueTestCase
{
    private Branch $puchong;

    protected function setUp(): void
    {
        parent::setUp();
        $this->puchong = Branch::query()->where('organisation_id', $this->organisation->id)
            ->where('code', 'PUCHONG')->firstOrFail();
    }

    public function test_display_role_holds_only_display_permissions_and_no_patient_or_insights_access(): void
    {
        $roles = PermissionCatalogue::roles();

        $this->assertEqualsCanonicalizing([
            'dashboard.view.own',
            'profile.view.own',
            'staff.view.own',
            'branches.view.branch',
            'branch_context.switch.branch',
            'queue.display.branch',
        ], $roles['queue_display']);
        foreach ($roles['queue_display'] as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
        }

        $this->assertContains('queue_display.manage.organisation', $roles['director']);
        $this->assertContains('queue_display.manage.branch', $roles['ca_supervisor']);
        $this->assertContains('queue.room.select.own', $roles['resident_doctor']);
        foreach ($roles as $role => $permissions) {
            if (! in_array($role, ['director', 'ca_supervisor'], true)) {
                $this->assertNotContains('queue_display.manage.organisation', $permissions, $role);
                $this->assertNotContains('queue_display.manage.branch', $permissions, $role);
            }
            if ($role !== 'resident_doctor') {
                $this->assertNotContains('queue.room.select.own', $permissions, $role);
            }
            if ($role !== 'queue_display') {
                $this->assertNotContains('queue.display.branch', $permissions, $role);
            }
        }
    }

    public function test_display_account_lands_on_the_screen_and_is_refused_everywhere_patient_data_lives(): void
    {
        $display = $this->actor('queue_display');
        $this->selectBranch($display);

        $this->get(route('workspace'))->assertRedirect(route('queue-display.screen', absolute: false));
        $this->get(route('queue-display.screen'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('QueueDisplay/Screen')
                ->where('feed.branch.id', $this->branch->id));

        foreach (['queue.index', 'registration.index', 'insights.today', 'queue-display.settings'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('queue-display.screen', ['branch' => $this->puchong->id]))->assertForbidden();
        $this->get(route('queue-display.feed', ['branch' => $this->puchong->id]))->assertForbidden();
    }

    public function test_tv_accounts_are_always_remembered_so_the_screen_survives_session_expiry(): void
    {
        $recaller = Auth::guard('web')->getRecallerName();
        $ca = $this->actor('ca');
        $this->post(route('login.store'), ['email' => $ca->email, 'password' => 'password'])
            ->assertCookieMissing($recaller);
        $this->post(route('logout'));
        Auth::forgetGuards();
        $this->flushSession();

        $display = $this->actor('queue_display');
        $response = $this->post(route('login.store'), ['email' => $display->email, 'password' => 'password']);
        $response->assertCookie($recaller);
        $remembered = (string) $response->getCookie($recaller)?->getValue();

        // A new browser session (expired or lost) carrying only the remember cookie keeps the feed running.
        Auth::forgetGuards();
        $this->flushSession();
        $this->getJson(route('queue-display.feed'))->assertUnauthorized();
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($recaller, $remembered)
            ->getJson(route('queue-display.feed'))
            ->assertOk()
            ->assertJsonPath('branch.id', $this->branch->id);
    }

    public function test_called_number_and_doctor_room_reach_the_feed_without_patient_identity(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $room = app(QueueDisplayAdministrationService::class)->createRoom($supervisor, $this->branch, [
            'kind' => 'consultation', 'name' => 'Bilik Rawatan 2', 'sort_order' => 2,
        ]);
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);

        $this->selectBranch($doctor);
        $this->put(route('queue.room'), [
            'branch_room_id' => $room->id,
            'expected_branch_id' => $this->branch->id,
        ])->assertRedirect(route('queue.index'));
        $this->assertSame($room->id, app(DoctorRoomService::class)->choice($doctor)['currentRoomId']);

        $this->callIn($doctor, $visit, $entry->lock_version);

        $call = QueueCall::query()->sole();
        $this->assertSame($room->id, $call->branch_room_id);
        $this->assertSame('Bilik Rawatan 2', $call->room_name);
        $this->assertSame($entry->queue_number, $call->queue_number);

        $display = $this->actor('queue_display');
        $this->selectBranch($display);
        $response = $this->getJson(route('queue-display.feed'))->assertOk()
            ->assertJsonPath('calls.0.number', QueueNumberFormat::format($entry->queue_number))
            ->assertJsonPath('calls.0.room', 'Bilik Rawatan 2')
            ->assertJsonPath('branch.id', $this->branch->id);
        $this->assertSame(['id', 'number', 'room', 'service', 'calledAt', 'isRecall'], array_keys($response->json('calls.0')));
        $body = $response->getContent();
        $patient = $visit->patient()->firstOrFail();
        $this->assertStringNotContainsString($patient->full_name, $body);
        $this->assertStringNotContainsString($doctor->name, $body);
        $this->assertStringNotContainsString($visit->visit_number, $body);

        $this->assertTrue(AuditLog::query()->where('event', 'queue.room.selected')->exists());
        $audit = AuditLog::query()->where('event', 'queue.called')->firstOrFail();
        $this->assertSame($room->id, $audit->metadata['branch_room_id']);
    }

    public function test_call_without_a_chosen_room_still_succeeds_with_no_room(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);

        $this->callIn($doctor, $visit, $entry->lock_version);

        $call = QueueCall::query()->sole();
        $this->assertNull($call->branch_room_id);
        $this->assertNull($call->room_name);
    }

    public function test_call_in_is_blocked_until_the_doctor_chooses_a_room_once_the_branch_has_rooms(): void
    {
        $director = $this->actor('director');
        $room = app(QueueDisplayAdministrationService::class)->createRoom($director, $this->branch, [
            'kind' => 'consultation', 'name' => 'Consultation Room 2', 'sort_order' => 2,
        ]);
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);

        try {
            $this->callIn($doctor, $visit, $entry->lock_version);
            $this->fail('Call In without a room must be refused once the branch has rooms.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('doctor', $exception->errors());
        }
        $this->assertSame(QueueEntry::STATUS_WAITING, $entry->refresh()->status);
        $this->assertSame(0, QueueCall::query()->count());

        $this->selectBranch($doctor);
        app(DoctorRoomService::class)->select($doctor, $room->id, $this->branch->id);
        $this->callIn($doctor, $visit, $entry->lock_version);
        $this->assertSame('Consultation Room 2', QueueCall::query()->sole()->room_name);
    }

    public function test_a_refused_call_in_returns_to_the_page_it_was_pressed_on_not_the_last_full_page(): void
    {
        app(QueueDisplayAdministrationService::class)->createRoom($this->actor('director'), $this->branch, [
            'kind' => 'consultation', 'name' => 'Consultation Room 2', 'sort_order' => 2,
        ]);
        $ca = $this->actor('ca_supervisor');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $this->selectBranch($ca);
        $payload = fn (array $extra = []): array => [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $entry->refresh()->lock_version,
            ...$extra,
        ];

        // The session's last recorded full page is somewhere unrelated, as after visiting a Dispensary page.
        $this->withSession(['_previous' => ['url' => route('workspace')]]);

        $this->patch(route('queue.call', $visit->visit_number), $payload(['from' => 'registration']))
            ->assertRedirect(route('registration.index'))->assertSessionHasErrors('doctor');
        $this->patch(route('queue.call', $visit->visit_number), $payload())
            ->assertRedirect(route('queue.index'))->assertSessionHasErrors('doctor');
        $this->assertSame(QueueEntry::STATUS_WAITING, $entry->refresh()->status);
    }

    public function test_call_again_announces_the_serving_patient_once_more_without_changing_the_queue(): void
    {
        $director = $this->actor('director');
        $room = app(QueueDisplayAdministrationService::class)->createRoom($director, $this->branch, [
            'kind' => 'consultation', 'name' => 'Consultation Room 2', 'sort_order' => 2,
        ]);
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $this->selectBranch($doctor);
        app(DoctorRoomService::class)->select($doctor, $room->id, $this->branch->id);
        $this->callIn($doctor, $visit, $entry->lock_version);
        $serving = $entry->refresh();

        $this->patch(route('queue.recall', $visit->visit_number), [
            'expected_branch_id' => $this->branch->id,
            'queue_lock_version' => $serving->lock_version,
        ])->assertRedirect(route('queue.index'))->assertSessionHasErrors('queue');
        $this->assertSame(1, QueueCall::query()->count());

        $this->travel(QueueCall::RECALL_COOLDOWN_SECONDS + 1)->seconds();
        $this->patch(route('queue.recall', $visit->visit_number), [
            'expected_branch_id' => $this->branch->id,
            'queue_lock_version' => $serving->lock_version,
        ])->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();

        $calls = QueueCall::query()->orderBy('id')->get();
        $this->assertCount(2, $calls);
        $this->assertFalse($calls[0]->is_recall);
        $this->assertTrue($calls[1]->is_recall);
        $this->assertSame('Consultation Room 2', $calls[1]->room_name);
        $this->assertSame($serving->lock_version, $entry->refresh()->lock_version);
        $this->assertSame(QueueEntry::STATUS_SERVING, $entry->status);
        $this->assertTrue(AuditLog::query()->where('event', 'queue.recalled')->exists());

        $display = $this->actor('queue_display');
        $this->selectBranch($display);
        $this->getJson(route('queue-display.feed'))->assertOk()
            ->assertJsonCount(1, 'calls')
            ->assertJsonPath('calls.0.id', $calls[1]->id)
            ->assertJsonPath('calls.0.isRecall', true);

        $this->travel(QueueCall::RECALL_COOLDOWN_SECONDS + 1)->seconds();
        $other = $this->doctor();
        $this->selectBranch($other);
        $this->patch(route('queue.recall', $visit->visit_number), [
            'expected_branch_id' => $this->branch->id,
            'queue_lock_version' => $serving->lock_version,
        ])->assertForbidden();
        $this->selectBranch($ca);
        $this->patch(route('queue.recall', $visit->visit_number), [
            'expected_branch_id' => $this->branch->id,
            'queue_lock_version' => $serving->lock_version,
        ])->assertForbidden();
        $this->assertSame(2, QueueCall::query()->count());
    }

    public function test_only_a_patient_being_served_can_be_called_again(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $this->selectBranch($doctor);

        $this->expectException(ValidationException::class);
        app(QueueEntryService::class)->recall($doctor, $visit, [
            'expected_branch_id' => $this->branch->id,
            'queue_lock_version' => $entry->lock_version,
        ]);
    }

    public function test_feed_shows_only_the_display_branch_and_today(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $this->callIn($doctor, $visit, $entry->lock_version);

        $other = $this->actor('queue_display', $this->puchong);
        $this->selectBranch($other, $this->puchong);
        $this->getJson(route('queue-display.feed'))->assertOk()->assertJsonCount(0, 'calls');

        $this->travel(1)->days();
        $display = $this->actor('queue_display');
        $this->selectBranch($display);
        $this->getJson(route('queue-display.feed'))->assertOk()->assertJsonCount(0, 'calls');
    }

    public function test_doctor_may_only_choose_an_active_consultation_room_of_the_active_branch(): void
    {
        $director = $this->actor('director');
        $service = app(QueueDisplayAdministrationService::class);
        $dispensary = $service->createRoom($director, $this->branch, ['kind' => 'dispensary', 'name' => 'Farmasi', 'sort_order' => 1]);
        $elsewhere = $service->createRoom($director, $this->puchong, ['kind' => 'consultation', 'name' => 'Bilik 1', 'sort_order' => 1]);
        $inactive = $service->createRoom($director, $this->branch, ['kind' => 'consultation', 'name' => 'Bilik 3', 'sort_order' => 3]);
        $service->setRoomActive($director, $inactive, false, 1);
        $doctor = $this->doctor();
        $this->selectBranch($doctor);

        foreach ([$dispensary, $elsewhere, $inactive] as $room) {
            try {
                app(DoctorRoomService::class)->select($doctor, $room->id, $this->branch->id);
                $this->fail('Room '.$room->name.' should be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('branch_room_id', $exception->errors());
            }
        }

        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->put(route('queue.room'), ['branch_room_id' => null, 'expected_branch_id' => $this->branch->id])
            ->assertForbidden();
    }

    public function test_room_administration_is_branch_scoped_audited_and_versioned(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $service = app(QueueDisplayAdministrationService::class);
        $room = $service->createRoom($supervisor, $this->branch, ['kind' => 'consultation', 'name' => 'Bilik Rawatan 1', 'sort_order' => 1]);

        try {
            $service->createRoom($supervisor, $this->branch, ['kind' => 'consultation', 'name' => 'bilik  rawatan 1', 'sort_order' => 2]);
            $this->fail('Duplicate room names must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        try {
            $service->updateRoom($supervisor, $room, ['name' => 'Bilik A', 'sort_order' => 1, 'lock_version' => 9]);
            $this->fail('A stale lock version must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lock_version', $exception->errors());
        }

        $updated = $service->updateRoom($supervisor, $room, ['name' => 'Bilik A', 'sort_order' => 1, 'lock_version' => 1]);
        $this->assertSame(2, $updated->lock_version);
        $this->assertSame(
            ['queue_display.room.created', 'queue_display.room.updated'],
            AuditLog::query()->where('event', 'like', 'queue_display.%')->orderBy('id')->pluck('event')->all(),
        );

        $this->expectException(AuthorizationException::class);
        $service->createRoom($supervisor, $this->puchong, ['kind' => 'consultation', 'name' => 'Bilik 1', 'sort_order' => 1]);
    }

    public function test_settings_routes_require_manage_permission_and_respect_branch_scope(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->actingAs($supervisor);
        $this->get(route('queue-display.settings'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('QueueDisplay/Settings')
                ->has('branches', 1)
                ->where('branch.id', $this->branch->id));
        $this->post(route('queue-display.rooms.store', $this->puchong->id), [
            'kind' => 'consultation', 'name' => 'Bilik 1', 'sort_order' => 1,
        ])->assertForbidden();
        $this->post(route('queue-display.rooms.store', $this->branch->id), [
            'kind' => 'consultation', 'name' => 'Bilik 1', 'sort_order' => 1,
        ])->assertRedirect(route('queue-display.settings', ['branch' => $this->branch->id]));
        $this->post(route('queue-display.rooms.store', $this->branch->id), [
            'kind' => 'lobby', 'name' => 'Bilik 2', 'sort_order' => 1,
        ])->assertRedirect(route('queue-display.settings', ['branch' => $this->branch->id]))
            ->assertSessionHasErrors('kind');

        $director = $this->actor('director');
        $this->actingAs($director);
        $this->get(route('queue-display.settings', ['branch' => $this->puchong->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('branches', 3)->where('branch.id', $this->puchong->id));

        foreach (['ca', 'resident_doctor', 'technical_admin', 'queue_display'] as $role) {
            $this->actingAs($this->actor($role));
            $this->get(route('queue-display.settings'))->assertForbidden();
        }
    }

    public function test_youtube_links_are_reduced_to_a_video_id_and_other_links_are_refused(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
            'https://youtube.com/watch?v=dQw4w9WgXcQ&t=10s' => 'dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc' => 'dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ' => 'dQw4w9WgXcQ',
            '' => null,
        ] as $url => $expected) {
            $this->assertSame($expected, QueueDisplayAdministrationService::youtubeVideoId($url), $url);
        }

        foreach ([
            'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://evil.example/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=short',
            'javascript:alert(1)',
            'https://www.youtube.com/playlist?list=PL123',
        ] as $url) {
            try {
                QueueDisplayAdministrationService::youtubeVideoId($url);
                $this->fail($url.' must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('youtube_url', $exception->errors());
            }
        }
    }

    public function test_settings_save_with_optimistic_version_and_feed_exposes_them(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $service = app(QueueDisplayAdministrationService::class);
        $settings = $service->updateSettings($supervisor, $this->branch, [
            'ticker_text' => "  Waktu operasi:\n8 pagi - 10 malam  ",
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
            'poster_seconds' => 15,
            'lock_version' => 0,
        ]);
        $this->assertSame(1, $settings->lock_version);
        $this->assertSame('Waktu operasi: 8 pagi - 10 malam', $settings->ticker_text);

        try {
            $service->updateSettings($supervisor, $this->branch, [
                'ticker_text' => null, 'youtube_url' => null, 'poster_seconds' => 10, 'lock_version' => 0,
            ]);
            $this->fail('A stale settings version must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lock_version', $exception->errors());
        }

        $display = $this->actor('queue_display');
        $this->selectBranch($display);
        $this->getJson(route('queue-display.feed'))->assertOk()
            ->assertJsonPath('settings.tickerText', 'Waktu operasi: 8 pagi - 10 malam')
            ->assertJsonPath('settings.youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('settings.posterSeconds', 15);
    }

    public function test_posters_are_private_branch_scoped_and_removed_from_storage(): void
    {
        Storage::fake(QueueDisplayAdministrationService::POSTER_DISK);
        $supervisor = $this->actor('ca_supervisor');
        $this->actingAs($supervisor);
        $this->post(route('queue-display.posters.store', $this->branch->id), [
            'poster' => $this->png('promo.png'),
            'lock_version' => 0,
        ])->assertRedirect(route('queue-display.settings', ['branch' => $this->branch->id]))
            ->assertSessionHasNoErrors();
        $this->post(route('queue-display.posters.store', $this->branch->id), [
            'poster' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            'lock_version' => 1,
        ])->assertSessionHasErrors('poster');

        $settings = BranchDisplaySetting::query()->where('branch_id', $this->branch->id)->sole();
        $poster = $settings->posters[0];
        Storage::disk(QueueDisplayAdministrationService::POSTER_DISK)->assertExists($poster['path']);
        $url = route('queue-display.posters.show', [$this->branch->id, $poster['id']]);

        $display = $this->actor('queue_display');
        $this->selectBranch($display);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

        $other = $this->actor('queue_display', $this->puchong);
        $this->selectBranch($other, $this->puchong);
        $this->get($url)->assertForbidden();

        $this->actingAs($this->actor('ca'));
        $this->get($url)->assertForbidden();

        $this->actingAs($supervisor);
        $this->post(route('queue-display.posters.destroy', [$this->branch->id, $poster['id']]), ['lock_version' => 1])
            ->assertRedirect(route('queue-display.settings', ['branch' => $this->branch->id]));
        Storage::disk(QueueDisplayAdministrationService::POSTER_DISK)->assertMissing($poster['path']);
        $this->actingAs($display);
        $this->get($url)->assertNotFound();
        $this->assertSame(
            ['queue_display.poster.added', 'queue_display.poster.removed'],
            AuditLog::query()->where('event', 'like', 'queue_display.poster.%')->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_a_branch_holds_at_most_the_maximum_number_of_posters(): void
    {
        Storage::fake(QueueDisplayAdministrationService::POSTER_DISK);
        $supervisor = $this->actor('ca_supervisor');
        $service = app(QueueDisplayAdministrationService::class);
        for ($i = 0; $i < BranchDisplaySetting::MAX_POSTERS; $i++) {
            $service->addPoster($supervisor, $this->branch, $this->png("p{$i}.png"), $i);
        }

        try {
            $service->addPoster($supervisor, $this->branch, $this->png('extra.png'), BranchDisplaySetting::MAX_POSTERS);
            $this->fail('A ninth poster must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('poster', $exception->errors());
        }
        $this->assertCount(BranchDisplaySetting::MAX_POSTERS, Storage::disk(QueueDisplayAdministrationService::POSTER_DISK)
            ->allFiles("queue-display/{$this->branch->id}"));
    }

    public function test_queue_calls_are_immutable(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $this->callIn($doctor, $visit, $entry->lock_version);
        $call = QueueCall::query()->sole();

        $this->expectException(LogicException::class);
        $call->forceFill(['room_name' => 'Bilik Lain'])->save();
    }

    public function test_permission_migration_creates_the_display_role_and_grants_additively(): void
    {
        foreach (['director' => 'queue_display.manage.organisation', 'ca_supervisor' => 'queue_display.manage.branch', 'resident_doctor' => 'queue.room.select.own'] as $role => $permission) {
            Role::findByName($role)->revokePermissionTo($permission);
        }
        Role::findByName('queue_display')->delete();
        Permission::query()->where('name', 'queue.display.branch')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $directorCount = Role::findByName('director')->permissions()->count();

        $migration = require database_path('migrations/2026_10_06_000200_grant_queue_display_permissions_additively.php');
        $migration->up();
        $migration->up();
        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(Role::findByName('director')->hasPermissionTo('queue_display.manage.organisation'));
        $this->assertTrue(Role::findByName('ca_supervisor')->hasPermissionTo('queue_display.manage.branch'));
        $this->assertTrue(Role::findByName('resident_doctor')->hasPermissionTo('queue.room.select.own'));
        $this->assertSame($directorCount + 1, Role::findByName('director')->permissions()->count());
        $this->assertEqualsCanonicalizing(
            PermissionCatalogue::roles()['queue_display'],
            Role::findByName('queue_display')->permissions()->pluck('name')->all(),
        );
    }

    /** A real 1x1 PNG, so no GD extension is needed to build test images. */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        ));
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
