<?php

namespace Tests\Feature\Compliance;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Domain\Organisation\Compliance\Services\AuditService;
use App\Domain\Organisation\Compliance\Services\CompliancePhotos;
use App\Domain\Organisation\Compliance\Services\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-11c monthly audits and supervisor tasks (docs/teams/hr/HR-11-DESIGN.md; owner, 2026-11-11: "that two works i approve use
 * ur recommendation"). Synthetic data only. The clock is frozen in Kuala Lumpur, so every month is branch-local. Photos are
 * added through the services with prepared image bytes, because decoding images needs the GD extension (CI only).
 */
class AuditTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CompliancePhotos::DISK);
        $this->travelTo(CarbonImmutable::parse('2027-03-08 09:00', $this->branch->timezone));
    }

    /** @return array{content:string, kind:string, bytes:int, sha256:string} */
    private function image(string $marker = 'a'): array
    {
        $content = "\x89PNG\r\n\x1a\nSYNTHETIC-PHOTO-{$marker}";

        return ['content' => $content, 'kind' => 'png', 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
    }

    /** @param list<array<string, mixed>> $items */
    private function checklist(?array $items = null): void
    {
        $this->selectBranch($this->actor('director'));
        $items ??= [['section' => 'Front desk', 'title' => 'Fire exit clear', 'help' => 'Walk the route', 'photo_required' => false], ['section' => 'Front desk', 'title' => 'Sharps bin closed', 'photo_required' => true],
            ['section' => 'Store', 'title' => 'Fridge log up to date', 'photo_required' => false]];
        $this->post(route('clinic-compliance.checklist.save'), ['items' => $items, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function start(string $month = 'current'): TestResponse
    {
        return $this->post(route('clinic-compliance.audits.start'), ['branch_id' => $this->branch->id, 'month' => $month, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @return array{audit: object, items: Collection<int, \stdClass>} */
    private function started(string $month = 'current'): array
    {
        $this->checklist();
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->start($month)->assertSessionHasNoErrors();
        $audit = DB::table('compliance_audits')->orderByDesc('id')->first();

        return ['audit' => $audit, 'items' => DB::table('compliance_checklist_items')->where('version_id', $audit->checklist_version_id)->orderBy('position')->get()];
    }

    /** @param list<string> $results */
    private function answer(object $audit, Collection $items, array $results, array $notes = []): TestResponse
    {
        $audit = DB::table('compliance_audits')->where('id', $audit->id)->first();

        return $this->post(route('clinic-compliance.audits.answers', $audit->public_id), ['expected_lock_version' => $audit->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'answers' => $items->values()->take(count($results))->map(fn ($i, $n): array => ['item_id' => $i->public_id, 'result' => $results[$n], 'note' => $notes[$n] ?? null])->all()]);
    }

    private function submit(object $audit): TestResponse
    {
        $audit = DB::table('compliance_audits')->where('id', $audit->id)->first();

        return $this->post(route('clinic-compliance.audits.submit', $audit->public_id), ['expected_lock_version' => $audit->lock_version, 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_grants_are_exact_and_other_roles_reach_nothing(): void
    {
        $holders = fn (string $p) => collect(PermissionCatalogue::roles())->filter(fn (array $perms) => in_array($p, $perms, true))->keys()->sort()->values()->all();
        $this->assertSame(['administrator', 'director'], $holders(CompliancePermissions::CHECKLISTS_MANAGE));
        $this->assertSame(['administrator', 'ca_supervisor', 'director'], $holders(CompliancePermissions::AUDITS_PERFORM));
        foreach ([CompliancePermissions::CHECKLISTS_MANAGE, CompliancePermissions::AUDITS_PERFORM] as $p) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($p));
        }
        foreach (['technical_admin', 'ca', 'resident_doctor', 'finance_officer', 'panel_officer', 'hr_manager', 'marketing', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->post(route('clinic-compliance.audits.start'), ['branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
            $this->post(route('clinic-compliance.checklist.save'), ['items' => [['section' => 'S1', 'title' => 'T1']], 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        }
        // The supervisor performs audits but cannot change the checklist or the settings.
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->post(route('clinic-compliance.checklist.save'), ['items' => [['section' => 'S1', 'title' => 'T1']], 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post(route('clinic-compliance.audit-settings.save'), ['verify_done_tasks' => true, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_the_checklist_is_the_clinics_own_and_each_save_is_a_new_version(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('clinic-compliance.checklist.save'), ['items' => [], 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('items');
        $this->post(route('clinic-compliance.checklist.save'), ['items' => [['section' => 'X', 'title' => 'Check the door']], 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('items.0.title');
        $this->assertSame(0, DB::table('compliance_checklist_versions')->count());
        $key = (string) Str::uuid();
        $payload = ['items' => [['section' => 'Front desk', 'title' => 'Check the door', 'help' => 'Lock too', 'photo_required' => true]], 'idempotency_key' => $key];
        $this->post(route('clinic-compliance.checklist.save'), $payload)->assertSessionHasNoErrors();
        $this->post(route('clinic-compliance.checklist.save'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('compliance_checklist_versions')->count());
        $this->post(route('clinic-compliance.checklist.save'), ['items' => [['section' => 'Front desk', 'title' => 'Check the door'], ['section' => 'Store', 'title' => 'Count the gloves']], 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('compliance_checklist_versions')->count());
        $this->assertSame(1, DB::table('compliance_checklist_items')->where('version_id', DB::table('compliance_checklist_versions')->where('version', 1)->value('id'))->count());
        $this->get(route('clinic-compliance.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page->where('compliance.settings.audit.version', 2)->has('compliance.settings.audit.items', 2));
    }

    public function test_an_audit_needs_a_checklist_one_per_branch_and_month_and_a_branch_assignment(): void
    {
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->start()->assertSessionHasErrors('compliance');
        $this->checklist();
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->start()->assertSessionHasNoErrors();
        $this->start()->assertSessionHasErrors('compliance');
        $this->start('previous')->assertSessionHasNoErrors();
        $months = DB::table('compliance_audits')->orderBy('month_start')->pluck('month_start')->map(fn ($m) => substr((string) $m, 0, 10))->all();
        $this->assertSame(['2027-02-01', '2027-03-01'], $months);
        $this->assertSame(2, DB::table('compliance_audits')->where('status', 'open')->count());

        $other = $this->branch->replicate();
        $other->forceFill(['code' => 'AUDIT_'.Str::upper(Str::random(5))]);
        if (array_key_exists('public_id', $other->getAttributes())) {
            $other->public_id = (string) Str::uuid();
        }
        $other->save();
        $this->post(route('clinic-compliance.audits.start'), ['branch_id' => $other->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->assertSame(2, DB::table('compliance_audits')->count());
    }

    public function test_answers_are_checked_saved_while_open_and_notes_are_encrypted(): void
    {
        ['audit' => $audit, 'items' => $items] = $this->started();
        $this->answer($audit, $items, ['pass', 'fail', 'na'], [null, null, null])->assertSessionHasErrors('answers.1.note');
        $this->assertSame(0, DB::table('compliance_audit_answers')->count());
        $this->answer($audit, $items, ['pass', 'fail', 'na'], [null, 'Bin lid broken', null])->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('compliance_audit_answers')->count());
        $this->assertNotSame('Bin lid broken', DB::table('compliance_audit_answers')->where('result', 'fail')->value('note_encrypted'));
        // Changing an answer while the audit is open is allowed; a stale page is not.
        $this->answer($audit, $items, ['pass', 'pass', 'na'])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('compliance_audit_answers')->where('result', 'fail')->count());
        $this->post(route('clinic-compliance.audits.answers', $audit->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid(),
            'answers' => [['item_id' => $items[0]->public_id, 'result' => 'pass']]])->assertSessionHasErrors('compliance');
        $this->post(route('clinic-compliance.audits.answers', $audit->public_id), ['expected_lock_version' => DB::table('compliance_audits')->value('lock_version'), 'idempotency_key' => (string) Str::uuid(),
            'answers' => [['item_id' => (string) Str::uuid(), 'result' => 'pass']]])->assertSessionHasErrors('answers');
        $this->assertStringNotContainsString('Bin lid', json_encode(AuditLog::query()->where('event', 'like', 'compliance.audit%')->get()->pluck('metadata')));
    }

    public function test_submitting_needs_every_answer_and_a_photo_where_required_then_is_final_and_makes_one_task_per_failed_item(): void
    {
        ['audit' => $audit, 'items' => $items] = $this->started();
        $this->answer($audit, $items, ['pass', 'fail'], [null, 'Lid broken'])->assertSessionHasNoErrors();
        $this->submit($audit)->assertSessionHasErrors('compliance');
        $this->answer($audit, $items, ['pass', 'fail', 'na'], [null, 'Lid broken', null])->assertSessionHasNoErrors();
        // The failed item needs a photo.
        $this->submit($audit)->assertSessionHasErrors('compliance');
        $this->assertSame(0, DB::table('compliance_tasks')->count());
        $service = app(AuditService::class);
        $service->addPhoto(User::query()->whereKey(auth()->id())->first(), $audit->public_id, $items[1]->public_id, $this->image());
        $this->submit($audit)->assertSessionHasNoErrors();
        $this->assertSame('submitted', DB::table('compliance_audits')->value('status'));
        $this->assertSame(1, DB::table('compliance_tasks')->count());
        $this->assertSame($items[1]->id, DB::table('compliance_tasks')->value('item_id'));
        $this->assertSame('open', DB::table('compliance_tasks')->value('status'));
        // Final: answers, photos and a second submit are refused.
        $this->answer($audit, $items, ['pass', 'pass', 'pass'])->assertSessionHasErrors('compliance');
        $this->submit($audit)->assertSessionHasErrors('compliance');
        $this->assertSame(1, DB::table('compliance_tasks')->count());
        $this->get(route('clinic-compliance.index', ['tab' => 'audits', 'audit' => $audit->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('compliance.audits.selected.status', 'submitted')->where('compliance.audits.selected.canEdit', false)->has('compliance.audits.tasks', 1));
    }

    public function test_photos_are_images_only_encrypted_at_most_three_and_removable_while_open(): void
    {
        ['audit' => $audit, 'items' => $items] = $this->started();
        $service = app(AuditService::class);
        $actor = User::query()->whereKey(auth()->id())->first();
        try {
            $service->addPhoto($actor, $audit->public_id, $items[0]->public_id, ['content' => '%PDF-1.4 x %%EOF', 'kind' => 'pdf', 'bytes' => 15, 'sha256' => hash('sha256', 'x')]);
            $this->fail('A PDF must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photo', $e->errors());
        }
        foreach (['a', 'b', 'c'] as $marker) {
            $service->addPhoto($actor, $audit->public_id, $items[0]->public_id, $this->image($marker));
        }
        $this->expectFourth($service, $actor, $audit, $items);
        $row = DB::table('compliance_audit_photos')->first();
        $stored = Storage::disk(CompliancePhotos::DISK)->get($row->stored_path);
        $this->assertStringNotContainsString('SYNTHETIC-PHOTO', $stored);
        $this->assertStringNotContainsString('.png', $row->stored_path);
        $this->assertSame(['Photo 1', 'Photo 2', 'Photo 3'], DB::table('compliance_audit_photos')->orderBy('id')->pluck('label')->all());
        // Removal deletes the file and keeps the row.
        $service->removePhoto($actor, (string) $row->public_id);
        $this->assertFalse(Storage::disk(CompliancePhotos::DISK)->exists($row->stored_path));
        $this->assertNotNull(DB::table('compliance_audit_photos')->where('id', $row->id)->value('purged_at'));
        $this->assertSame(3, DB::table('compliance_audit_photos')->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'compliance.audit_photo_added')->get()->filter(fn ($a) => str_contains(json_encode($a->metadata), 'SYNTHETIC'))->count());
    }

    /** @param Collection<int, \stdClass> $items */
    private function expectFourth(AuditService $service, User $actor, object $audit, $items): void
    {
        try {
            $service->addPhoto($actor, $audit->public_id, $items[0]->public_id, $this->image('d'));
            $this->fail('A fourth photo must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photo', $e->errors());
        }
    }

    private function failedTask(): object
    {
        ['audit' => $audit, 'items' => $items] = $this->started();
        $actor = User::query()->whereKey(auth()->id())->first();
        $this->answer($audit, $items, ['pass', 'fail', 'pass'], [null, 'Lid broken', null])->assertSessionHasNoErrors();
        app(AuditService::class)->addPhoto($actor, $audit->public_id, $items[1]->public_id, $this->image());
        $this->submit($audit)->assertSessionHasNoErrors();

        return DB::table('compliance_tasks')->first();
    }

    public function test_a_task_is_done_by_one_person_and_verified_by_another_when_the_rule_is_on(): void
    {
        $task = $this->failedTask();
        $this->assertTrue((bool) $task->verify_required);
        $this->post(route('clinic-compliance.tasks.verify', $task->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->post(route('clinic-compliance.tasks.done', $task->public_id), ['note' => 'Replaced the lid', 'expected_lock_version' => 9, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->post(route('clinic-compliance.tasks.done', $task->public_id), ['note' => 'Replaced the lid', 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertNotSame('Replaced the lid', DB::table('compliance_tasks')->value('done_note_encrypted'));
        // The same person cannot verify their own work.
        $this->post(route('clinic-compliance.tasks.verify', $task->public_id), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->assertSame('done', DB::table('compliance_tasks')->value('status'));
        // Another person with the permission verifies it.
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->post(route('clinic-compliance.tasks.verify', $task->public_id), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('verified', DB::table('compliance_tasks')->value('status'));
        $this->post(route('clinic-compliance.tasks.cancel', $task->public_id), ['reason' => 'duplicate', 'expected_lock_version' => 3, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->assertStringNotContainsString('Replaced', json_encode(AuditLog::query()->where('event', 'like', 'compliance.task%')->get()->pluck('metadata')));
    }

    public function test_with_the_rule_off_a_done_task_is_final_and_a_task_can_be_cancelled_with_a_reason(): void
    {
        $this->selectBranch($this->actor('director'));
        $this->post(route('clinic-compliance.audit-settings.save'), ['verify_done_tasks' => false, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $task = $this->failedTask();
        $this->assertFalse((bool) $task->verify_required);
        $this->post(route('clinic-compliance.tasks.done', $task->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->post(route('clinic-compliance.tasks.verify', $task->public_id), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');

        $second = $this->failedTaskTwo();
        $this->post(route('clinic-compliance.tasks.cancel', $second->public_id), ['reason' => 'nonsense', 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('reason');
        $this->post(route('clinic-compliance.tasks.cancel', $second->public_id), ['reason' => 'duplicate', 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', DB::table('compliance_tasks')->where('id', $second->id)->value('status'));
        $this->post(route('clinic-compliance.tasks.done', $second->public_id), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
    }

    private function failedTaskTwo(): object
    {
        // A second audit (last month) for the same branch, with its own failed item.
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->start('previous')->assertSessionHasNoErrors();
        $audit = DB::table('compliance_audits')->orderByDesc('id')->first();
        $items = DB::table('compliance_checklist_items')->where('version_id', $audit->checklist_version_id)->orderBy('position')->get();
        $this->answer($audit, $items, ['fail', 'pass', 'pass'], ['Exit blocked', null, null])->assertSessionHasNoErrors();
        $this->submit($audit)->assertSessionHasNoErrors();

        return DB::table('compliance_tasks')->where('audit_id', $audit->id)->first();
    }

    public function test_task_photos_are_images_only_at_most_three_and_only_on_open_or_done_tasks(): void
    {
        $task = $this->failedTask();
        $actor = User::query()->whereKey(auth()->id())->first();
        $tasks = app(TaskService::class);
        try {
            $tasks->addPhoto($actor, $task->public_id, ['content' => 'x', 'kind' => 'pdf', 'bytes' => 1, 'sha256' => hash('sha256', 'x')]);
            $this->fail('A PDF must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photo', $e->errors());
        }
        foreach (['a', 'b', 'c'] as $m) {
            $tasks->addPhoto($actor, $task->public_id, $this->image($m));
        }
        try {
            $tasks->addPhoto($actor, $task->public_id, $this->image('d'));
            $this->fail('A fourth photo must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photo', $e->errors());
        }
        $this->post(route('clinic-compliance.tasks.cancel', $task->public_id), ['reason' => 'other', 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        try {
            $tasks->addPhoto($actor, $task->public_id, $this->image('e'));
            $this->fail('A closed task takes no photo');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photo', $e->errors());
        }
    }

    public function test_photo_opens_are_audited_first_and_the_purge_follows_the_clinics_own_number_of_days(): void
    {
        ['audit' => $audit, 'items' => $items] = $this->started();
        $actor = User::query()->whereKey(auth()->id())->first();
        app(AuditService::class)->addPhoto($actor, $audit->public_id, $items[0]->public_id, $this->image());
        $photo = DB::table('compliance_audit_photos')->first();
        $this->get(route('clinic-compliance.audit-photos.open', $photo->public_id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(1, AuditLog::query()->where('event', 'compliance.audit_photo_opened')->count());
        $this->selectBranch($this->actor('ca'));
        $this->get(route('clinic-compliance.audit-photos.open', $photo->public_id))->assertForbidden();

        // Nothing is purged until a number is set, and an open audit keeps its photos.
        $this->artisan('compliance-photos:purge')->assertSuccessful();
        $this->assertNull(DB::table('compliance_audit_photos')->value('purged_at'));
        $this->selectBranch($this->actor('director'));
        $this->post(route('clinic-compliance.audit-settings.save'), ['verify_done_tasks' => true, 'photo_retention_days' => 10, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('photo_retention_days');
        $this->post(route('clinic-compliance.audit-settings.save'), ['verify_done_tasks' => true, 'photo_retention_days' => 30, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2027-05-08 09:00', $this->branch->timezone));
        $this->artisan('compliance-photos:purge')->assertSuccessful();
        $this->assertNull(DB::table('compliance_audit_photos')->value('purged_at'), 'an open audit keeps its photos');
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->answer($audit, $items, ['pass', 'pass', 'pass'])->assertSessionHasNoErrors();
        $this->submit($audit)->assertSessionHasNoErrors();
        // Submitted and older than the clinic's 30 days: the file goes and the row stays.
        $this->artisan('compliance-photos:purge')->assertSuccessful();
        $row = DB::table('compliance_audit_photos')->first();
        $this->assertNotNull($row->purged_at);
        $this->assertNull($row->stored_path);
        $this->assertSame(1, DB::table('compliance_audit_photos')->count());
    }

    public function test_the_page_shows_last_month_not_audited_and_only_a_persons_own_branches(): void
    {
        $this->checklist();
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->get(route('clinic-compliance.index', ['tab' => 'audits']))->assertInertia(fn (Assert $page) => $page
            ->where('compliance.tabs', ['fridge', 'audits'])->where('compliance.audits.checklistReady', true)->where('compliance.audits.missedLastMonth.0.monthLabel', 'February 2027')
            ->has('compliance.audits.startable', 1)->where('compliance.settings', null));
        $this->start('previous')->assertSessionHasNoErrors();
        $audit = DB::table('compliance_audits')->first();
        $items = DB::table('compliance_checklist_items')->where('version_id', $audit->checklist_version_id)->orderBy('position')->get();
        $this->answer($audit, $items, ['pass', 'na', 'na'])->assertSessionHasNoErrors();
        $this->submit($audit)->assertSessionHasNoErrors();
        $this->get(route('clinic-compliance.index', ['tab' => 'audits']))->assertInertia(fn (Assert $page) => $page->has('compliance.audits.missedLastMonth', 0)->has('compliance.audits.audits', 1));
    }

    public function test_postgresql_keeps_audits_and_enforces_its_own_rules(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The audit rules are PostgreSQL only.');
        }
        $task = $this->failedTask();
        $audit = DB::table('compliance_audits')->first();
        $answer = DB::table('compliance_audit_answers')->first();
        $photo = DB::table('compliance_audit_photos')->first();
        $version = DB::table('compliance_checklist_versions')->first();
        $item = DB::table('compliance_checklist_items')->first();
        $refused = function (callable $write, string $needle): void {
            DB::beginTransaction();
            try {
                $write();
                $this->fail('PostgreSQL should refuse this');
            } catch (QueryException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            } finally {
                DB::rollBack();
            }
        };
        $kept = 'Clinic compliance records are kept';
        $refused(fn () => DB::table('compliance_checklist_versions')->where('id', $version->id)->update(['version' => 9]), $kept);
        $refused(fn () => DB::table('compliance_checklist_items')->where('id', $item->id)->update(['title' => 'Changed']), $kept);
        $refused(fn () => DB::table('compliance_checklist_items')->where('id', $item->id)->delete(), $kept);
        $refused(fn () => DB::statement('TRUNCATE compliance_audits CASCADE'), $kept);
        $refused(fn () => DB::table('compliance_audits')->where('id', $audit->id)->delete(), $kept);
        $refused(fn () => DB::table('compliance_tasks')->where('id', $task->id)->delete(), $kept);
        $refused(fn () => DB::table('compliance_audit_photos')->where('id', $photo->id)->delete(), $kept);
        // A submitted audit is final; answers and photos cannot move after it.
        $refused(fn () => DB::table('compliance_audits')->where('id', $audit->id)->update(['status' => 'open', 'submitted_at' => null, 'submitted_by_user_id' => null]), 'final');
        $refused(fn () => DB::table('compliance_audit_answers')->where('id', $answer->id)->update(['result' => 'pass']), 'only while the audit is open');
        $refused(fn () => DB::table('compliance_audit_photos')->where('id', $photo->id)->update(['label' => 'Photo 2']), $kept);
        // A task exists only for a failed item, moves one way and is verified by someone else.
        $refused(fn () => DB::table('compliance_tasks')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $task->organisation_id, 'branch_id' => $task->branch_id, 'audit_id' => $task->audit_id,
            'item_id' => DB::table('compliance_checklist_items')->where('position', 1)->value('id'), 'status' => 'open', 'verify_required' => true, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]), 'failed item');
        $refused(fn () => DB::table('compliance_tasks')->where('id', $task->id)->update(['status' => 'verified', 'verified_by_user_id' => $audit->started_by_user_id, 'verified_at' => now(), 'done_by_user_id' => $audit->started_by_user_id, 'done_at' => now()]), 'moves one way');
        $refused(fn () => DB::table('compliance_tasks')->where('id', $task->id)->update(['branch_id' => $task->branch_id + 1000]), 'keeps its audit');
        $refused(fn () => DB::table('compliance_audit_answers')->insert(['organisation_id' => $audit->organisation_id, 'audit_id' => $audit->id, 'item_id' => DB::table('compliance_checklist_items')->where('position', 3)->value('id'), 'result' => 'fail', 'answered_at' => now()]), 'only while the audit is open');
        // A fail needs a note, and one audit per branch and month.
        $refused(fn () => DB::table('compliance_audits')->insert([...(array) $audit, 'id' => null, 'public_id' => (string) Str::uuid()]), 'compliance_audits');
    }
}
