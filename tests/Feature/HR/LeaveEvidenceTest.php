<?php

namespace Tests\Feature\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Leave\Models\LeaveEvidenceFile;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Models\LeaveType;
use App\Domain\HR\Leave\Services\LeaveEvidenceService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-2b leave evidence files (owner, 2026-11-06). Synthetic files only. The clock is frozen on a Monday morning in
 * Kuala Lumpur, so every date is branch-local and never the UTC clock.
 */
class LeaveEvidenceTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-MC-MARKER\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(LeaveEvidenceService::DISK);
        $this->travelTo(CarbonImmutable::parse('2027-03-01 09:00', $this->branch->timezone));
    }

    public function test_the_view_permission_is_director_and_hr_manager_only_and_not_authority(): void
    {
        $this->assertSame(['director', 'hr_manager'], LeavePermissions::grants()[LeavePermissions::EVIDENCE_VIEW]);
        $this->assertEqualsCanonicalizing(['director', 'hr_manager'], Role::query()->whereHas('permissions', fn ($q) => $q->where('name', LeavePermissions::EVIDENCE_VIEW))->pluck('name')->all());
    }

    public function test_files_are_off_until_hr_switches_them_on_with_a_retention(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->selectBranch($hr);
        $this->put(route('hr.leave.types.save'), $this->typeInput(['accepts_evidence_files' => true, 'evidence_retention_days' => '']))
            ->assertSessionHasErrors(['evidence_retention_days' => 'Enter how many days to keep files after the leave ends (1 to 3650).']);
        $type = $this->type($hr, ['accepts_evidence_files' => false]);
        $request = $this->applyFor($ca, $type);
        $this->addFile($ca, $request, $this->pdf())->assertSessionHasErrors(['file' => 'Sick leave does not take evidence files.']);
        $this->assertSame(0, LeaveEvidenceFile::query()->count());
    }

    public function test_an_applicant_adds_and_opens_their_own_pdf_which_is_encrypted_and_never_named(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr));
        $this->addFile($ca, $request, $this->pdf('Synthetic-MC-Aina.pdf'))->assertSessionHasNoErrors();

        $file = LeaveEvidenceFile::query()->sole();
        $this->assertSame(['pdf', false, 'active', $ca->id], [$file->kind, $file->on_behalf, $file->status, $file->uploaded_by_user_id]);
        $stored = Storage::disk(LeaveEvidenceService::DISK)->get($file->stored_path);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('SYNTHETIC-MC-MARKER', $stored);
        $this->assertStringNotContainsString('Synthetic-MC-Aina', json_encode([DB::table('hr_leave_evidence_files')->get(), DB::table('audit_logs')->get(), DB::table('hr_leave_evidence_events')->get()]) ?: '');

        $this->selectBranch($ca);
        $response = $this->get(route('hr.leave.evidence.show', $file->public_id))->assertOk();
        $this->assertSame(self::PDF, $response->getContent());
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(['added', 'opened'], DB::table('hr_leave_evidence_events')->orderBy('id')->pluck('action')->all());

        $this->get(route('hr.leave.index'))->assertInertia(fn (Assert $page) => $page
            ->where('leave.my.requests.0.evidence.files.0.label', 'PDF 1')
            ->where('leave.my.requests.0.evidence.files.0.canRemove', true)
            ->where('leave.my.requests.0.evidence.retentionDays', 365));
    }

    public function test_only_the_applicant_and_evidence_viewers_open_a_file_and_every_open_is_recorded(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr));
        $this->addFile($ca, $request, $this->pdf())->assertSessionHasNoErrors();
        $file = LeaveEvidenceFile::query()->sole();

        foreach (['ca', 'ca_supervisor', 'finance_officer', 'technical_admin'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('hr.leave.evidence.show', $file->public_id))->assertForbidden();
        }
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.leave.evidence.show', $file->public_id))->assertForbidden();
        $this->selectBranch($hr);
        $this->get(route('hr.leave.evidence.show', $file->public_id))->assertOk();
        $this->assertSame(1, DB::table('hr_leave_evidence_events')->where('action', 'opened')->where('actor_user_id', $hr->id)->count());
        $this->assertSame(1, DB::table('hr_leave_evidence_events')->where('action', 'opened')->count());

        // Approvers see the file on the Approvals tab; the team calendar and roster never carry a file.
        $this->get(route('hr.leave.index', ['tab' => 'approvals']))->assertInertia(fn (Assert $page) => $page
            ->where('leave.approvals.pending.0.evidence.files.0.publicId', $file->public_id));
        $calendar = $this->get(route('hr.leave.index', ['tab' => 'calendar', 'month' => '2027-03']))->viewData('page')['props']['leave'];
        $this->assertStringNotContainsString($file->public_id, (string) json_encode($calendar['calendar']));
    }

    public function test_content_is_checked_not_the_name_and_limits_hold(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr));
        $this->addFile($ca, $request, UploadedFile::fake()->createWithContent('mc.pdf', "<script>alert(1)</script>\n"))
            ->assertSessionHasErrors(['file' => 'Use a JPG, PNG or PDF file.']);
        $this->addFile($ca, $request, UploadedFile::fake()->createWithContent('mc.jpg', str_replace('%%EOF', '', self::PDF)))
            ->assertSessionHasErrors(['file' => 'Use a JPG, PNG or PDF file.']); // A PDF header without its end marker.
        $this->addFile($ca, $request, UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf'))
            ->assertSessionHasErrors(['file' => 'This file is over 5 MB.']);

        $this->addFile($ca, $request, $this->pdf())->assertSessionHasNoErrors();
        $this->addFile($ca, $request, $this->pdf())->assertSessionHasErrors(['file' => 'This file is already attached.']);
        $this->addFile($ca, $request, $this->pdf('two.pdf', 'TWO'))->assertSessionHasNoErrors();
        $this->addFile($ca, $request, $this->pdf('three.pdf', 'THREE'))->assertSessionHasNoErrors();
        $this->addFile($ca, $request, $this->pdf('four.pdf', 'FOUR'))->assertSessionHasErrors(['file' => 'This request already has 3 files.']);
        $this->assertSame(3, LeaveEvidenceFile::query()->where('status', 'active')->count());
        $this->assertCount(3, Storage::disk(LeaveEvidenceService::DISK)->allFiles());
    }

    public function test_removal_rules_for_the_applicant_and_for_managers(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr));
        $this->addFile($ca, $request, $this->pdf())->assertSessionHasNoErrors();
        $this->addFile($ca, $request, $this->pdf('b.pdf', 'B'))->assertSessionHasNoErrors();
        [$first, $second] = LeaveEvidenceFile::query()->orderBy('id')->get()->all();

        // Another CA can neither add to nor remove from someone else's request.
        $other = $this->actor('ca');
        $this->addFile($other, $request, $this->pdf('c.pdf', 'C'))->assertForbidden();
        $this->remove($other, $first)->assertForbidden();

        $this->remove($ca, $first)->assertSessionHasNoErrors();
        $this->assertSame(['removed', 'withdrawn'], [$first->refresh()->status, $first->removal_reason]);
        $this->assertFalse(Storage::disk(LeaveEvidenceService::DISK)->exists($first->stored_path));

        // After approval the applicant asks HR; a manager removes with a reason.
        $this->selectBranch($director);
        $this->post(route('hr.leave.requests.approve', $request->public_id), ['expected_lock_version' => $request->refresh()->lock_version, 'evidence_seen' => true,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->remove($ca, $second)->assertSessionHasErrors(['leave' => 'This request was decided. Ask HR to remove the file.']);
        $this->remove($hr, $second)->assertSessionHasErrors(['reason' => 'Choose a reason.']);
        $this->remove($hr, $second, ['reason' => 'not_readable'])->assertSessionHasNoErrors();
        $this->assertSame(['removed', 'not_readable', $hr->id], [$second->refresh()->status, $second->removal_reason, $second->removed_by_user_id]);
        $this->assertSame(2, LeaveEvidenceFile::query()->count()); // Rows stay.
    }

    public function test_hr_adds_on_behalf_and_the_applicant_window_closes_14_days_after_the_leave(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr), '2027-03-08');
        $this->selectBranch($director);
        $this->post(route('hr.leave.requests.approve', $request->public_id), ['expected_lock_version' => 1, 'evidence_seen' => true, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::parse('2027-03-22 23:50', $this->branch->timezone)); // 14 days after 8 Mar.
        $this->addFile($ca, $request, $this->pdf())->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2027-03-23 00:10', $this->branch->timezone)); // 15 days; still 22 Mar in UTC.
        $this->addFile($ca, $request, $this->pdf('late.pdf', 'LATE'))->assertSessionHasErrors(['file' => 'Files can no longer be added to this request.']);
        $this->addFile($hr, $request, $this->pdf('scan.pdf', 'SCAN'))->assertSessionHasNoErrors();
        $this->assertSame([false, true], LeaveEvidenceFile::query()->orderBy('id')->pluck('on_behalf')->all());
    }

    public function test_apply_with_files_checks_every_file_first_and_retries_attach_once(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->type($hr);
        $this->selectBranch($ca);
        $input = ['leave_type_id' => $type->id, 'start_date' => '2027-03-08', 'end_date' => '2027-03-08'];
        $this->post(route('hr.leave.requests.store'), [...$input, 'idempotency_key' => (string) Str::uuid(),
            'evidence_files' => [$this->pdf(), UploadedFile::fake()->createWithContent('bad.pdf', 'not a pdf')]])
            ->assertSessionHasErrors(['evidence_files' => 'Use a JPG, PNG or PDF file.']);
        $this->assertSame(0, LeaveRequest::query()->count());

        $key = (string) Str::uuid();
        foreach ([1, 2] as $attempt) {
            $this->post(route('hr.leave.requests.store'), [...$input, 'idempotency_key' => $key, 'evidence_files' => [$this->pdf()]])->assertSessionHasNoErrors();
        }
        $this->assertSame(1, LeaveRequest::query()->count());
        $this->assertSame(1, LeaveEvidenceFile::query()->count());
    }

    public function test_purge_deletes_files_after_retention_and_after_a_closed_request_keeping_rows(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->type($hr, ['evidence_retention_days' => 10]);
        $kept = $this->applyFor($ca, $type, '2027-03-08');
        $this->addFile($ca, $kept, $this->pdf())->assertSessionHasNoErrors();
        $closed = $this->applyFor($ca, $type, '2027-03-15');
        $this->addFile($ca, $closed, $this->pdf('c.pdf', 'C'))->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('hr.leave.requests.cancel', $closed->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        [$a, $b] = LeaveEvidenceFile::query()->orderBy('id')->get()->all();

        $this->travelTo(CarbonImmutable::parse('2027-03-18 09:00', $this->branch->timezone)); // 10 days after 8 Mar: still kept.
        $this->artisan('hr-leave-evidence:purge')->assertSuccessful();
        $this->assertSame(['active', 'active'], [$a->refresh()->status, $b->refresh()->status]);

        $this->travelTo(CarbonImmutable::parse('2027-03-19 09:00', $this->branch->timezone));
        $this->artisan('hr-leave-evidence:purge')->assertSuccessful();
        $this->assertSame(['purged', 'active'], [$a->refresh()->status, $b->refresh()->status]);
        $this->assertFalse(Storage::disk(LeaveEvidenceService::DISK)->exists($a->stored_path));

        $this->travelTo(CarbonImmutable::parse('2027-03-31 23:00', $this->branch->timezone)); // 30 whole days after 1 Mar: still kept.
        $this->artisan('hr-leave-evidence:purge')->assertSuccessful();
        $this->assertSame('active', $b->refresh()->status);
        $this->travelTo(CarbonImmutable::parse('2027-04-01 00:10', $this->branch->timezone)); // The day after, though still 31 Mar in UTC.
        $this->artisan('hr-leave-evidence:purge')->assertSuccessful();
        $this->assertSame('purged', $b->refresh()->status);
        $this->assertSame(2, LeaveEvidenceFile::query()->count());
        $this->assertSame(['added', 'added', 'purged', 'purged'], DB::table('hr_leave_evidence_events')->orderBy('id')->pluck('action')->all());
        $this->selectBranch($ca);
        $this->get(route('hr.leave.evidence.show', $a->public_id))->assertNotFound();
    }

    public function test_postgresql_keeps_the_limits_and_the_history_whatever_the_code_does(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr));
        foreach (['A', 'B', 'C'] as $mark) {
            $this->addFile($ca, $request, $this->pdf("{$mark}.pdf", $mark))->assertSessionHasNoErrors();
        }
        $file = LeaveEvidenceFile::query()->orderBy('id')->firstOrFail();
        $attempts = [
            fn () => DB::table('hr_leave_evidence_files')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'leave_request_id' => $request->id,
                'uploaded_by_user_id' => $ca->id, 'on_behalf' => false, 'kind' => 'pdf', 'bytes' => 10, 'sha256' => str_repeat('d', 64), 'stored_path' => 'x', 'created_at' => now(), 'updated_at' => now()]),
            fn () => DB::table('hr_leave_evidence_files')->where('id', $file->id)->update(['sha256' => str_repeat('e', 64)]),
            fn () => DB::table('hr_leave_evidence_files')->where('id', $file->id)->delete(),
            fn () => DB::table('hr_leave_evidence_events')->update(['action' => 'opened']),
            fn () => DB::statement('TRUNCATE hr_leave_evidence_events'),
        ];
        foreach ($attempts as $i => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("Allowed attempt {$i}");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/At most 3|only moves once|immutable|never deleted|append-only/', $e->getMessage());
            }
        }
    }

    public function test_other_organisations_files_and_requests_are_never_reached_and_a_retry_adds_one_file(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr));
        $key = (string) Str::uuid();
        $this->addFile($ca, $request, $this->pdf(), $key)->assertSessionHasNoErrors();
        $this->addFile($ca, $request, $this->pdf(), $key)->assertSessionHasNoErrors();
        $this->assertSame(1, LeaveEvidenceFile::query()->count());

        $this->selectBranch($hr);
        $this->get(route('hr.leave.evidence.show', (string) Str::uuid()))->assertNotFound();
        $this->post(route('hr.leave.evidence.store', (string) Str::uuid()), ['file' => $this->pdf('x.pdf', 'X'), 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_a_photo_is_re_encoded_without_its_exif_data(): void
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            $this->markTestSkipped('Needs the GD extension (runs in CI).');
        }
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr));
        $image = imagecreatetruecolor(3000, 2000);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        // A synthetic APP1 EXIF segment carrying a GPS marker, inserted after the JPEG start marker.
        $exif = "Exif\0\0".'GPSLatitude SYNTHETIC-GPS-MARKER';
        $withExif = "\xFF\xD8\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $this->addFile($ca, $request, UploadedFile::fake()->createWithContent('photo.jpg', $withExif))->assertSessionHasNoErrors();

        $file = LeaveEvidenceFile::query()->sole();
        $this->selectBranch($ca);
        $response = $this->get(route('hr.leave.evidence.show', $file->public_id))->assertOk();
        $content = (string) $response->getContent();
        $this->assertSame('jpeg', $file->kind);
        $this->assertStringNotContainsString('SYNTHETIC-GPS-MARKER', $content);
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $size = getimagesizefromstring($content);
        $this->assertSame([2400, 1600], [$size[0] ?? null, $size[1] ?? null]); // Scaled to 2400 px on the long edge.
    }

    public function test_no_file_is_added_to_a_rejected_or_cancelled_request_or_an_unknown_one(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->type($hr);
        $rejected = $this->applyFor($ca, $type, '2027-03-08');
        $this->selectBranch($hr);
        $this->post(route('hr.leave.requests.reject', $rejected->public_id), ['expected_lock_version' => 1, 'reason' => 'other', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $cancelled = $this->applyFor($ca, $type, '2027-03-09');
        $this->selectBranch($ca);
        $this->post(route('hr.leave.requests.cancel', $cancelled->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        foreach ([$rejected, $cancelled] as $closed) {
            foreach ([$ca, $hr] as $actor) {
                $this->addFile($actor, $closed, $this->pdf())->assertSessionHasErrors(['file' => 'Files can no longer be added to this request.']);
            }
        }
        $this->selectBranch($ca);
        $this->post(route('hr.leave.evidence.store', (string) Str::uuid()), ['file' => $this->pdf(), 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post(route('hr.leave.evidence.remove', (string) Str::uuid()), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(0, LeaveEvidenceFile::query()->count());
    }

    public function test_a_failed_open_record_sends_no_bytes(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr));
        $this->addFile($ca, $request, $this->pdf())->assertSessionHasNoErrors();
        $file = LeaveEvidenceFile::query()->sole();
        $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new \RuntimeException('Synthetic audit failure')));
        $this->withoutExceptionHandling();
        $this->selectBranch($hr);
        try {
            $this->get(route('hr.leave.evidence.show', $file->public_id));
            $this->fail('The open was not refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertSame(0, DB::table('hr_leave_evidence_events')->where('action', 'opened')->count());
    }

    public function test_apply_refuses_files_for_a_type_without_them_or_twice_the_same_file_before_creating_anything(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $off = $this->type($hr, ['accepts_evidence_files' => false, 'evidence_retention_days' => '']);
        $this->selectBranch($ca);
        $input = ['leave_type_id' => $off->id, 'start_date' => '2027-03-08', 'end_date' => '2027-03-08', 'idempotency_key' => (string) Str::uuid()];
        $this->post(route('hr.leave.requests.store'), [...$input, 'evidence_files' => [$this->pdf()]])
            ->assertSessionHasErrors(['evidence_files' => 'Sick leave does not take evidence files.']);
        $this->selectBranch($hr);
        $this->put(route('hr.leave.types.save'), ['leave_type_id' => $off->id, 'expected_lock_version' => $off->refresh()->lock_version, ...$this->typeInput()])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('hr.leave.requests.store'), [...$input, 'evidence_files' => [$this->pdf(), $this->pdf('copy.pdf')]])
            ->assertSessionHasErrors(['evidence_files' => 'This file is already attached.']);
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_a_type_switched_off_keeps_its_days_while_files_remain_and_they_are_still_purged(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->type($hr, ['evidence_retention_days' => 5]);
        $request = $this->applyFor($ca, $type, '2027-03-08');
        $this->addFile($ca, $request, $this->pdf())->assertSessionHasNoErrors();
        $this->selectBranch($hr);
        $this->put(route('hr.leave.types.save'), ['leave_type_id' => $type->id, 'expected_lock_version' => $type->refresh()->lock_version,
            ...$this->typeInput(['accepts_evidence_files' => false, 'evidence_retention_days' => ''])])
            ->assertSessionHasErrors(['evidence_retention_days' => 'This type still has evidence files. Keep a number of days so they are deleted on time.']);
        $this->put(route('hr.leave.types.save'), ['leave_type_id' => $type->id, 'expected_lock_version' => $type->refresh()->lock_version,
            ...$this->typeInput(['accepts_evidence_files' => false, 'evidence_retention_days' => 5])])->assertSessionHasNoErrors();
        $this->assertFalse($type->refresh()->accepts_evidence_files);
        $this->travelTo(CarbonImmutable::parse('2027-03-14 09:00', $this->branch->timezone));
        $this->artisan('hr-leave-evidence:purge')->assertSuccessful();
        $this->assertSame('purged', LeaveEvidenceFile::query()->sole()->status);
    }

    public function test_the_roster_never_carries_a_file(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr));
        $this->addFile($ca, $request, $this->pdf())->assertSessionHasNoErrors();
        $this->selectBranch($director);
        $this->post(route('hr.leave.requests.approve', $request->public_id), ['expected_lock_version' => 1, 'evidence_seen' => true, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $file = LeaveEvidenceFile::query()->sole();
        $this->selectBranch($hr);
        $props = $this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => '2027-03-08']))->viewData('page')['props'];
        $this->assertStringNotContainsString($file->public_id, (string) json_encode($props));
        $this->assertStringNotContainsString('evidence', (string) json_encode($props['roster']));
    }

    public function test_an_oversized_photo_is_refused_before_decoding(): void
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            $this->markTestSkipped('Needs the GD extension (runs in CI).');
        }
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $request = $this->applyFor($ca, $this->type($hr));
        // A small PNG whose header claims 6000 x 5000 pixels (30 MP): refused from its size alone.
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $png = substr($png, 0, 16).pack('NN', 6000, 5000).substr($png, 24);
        $this->addFile($ca, $request, UploadedFile::fake()->createWithContent('big.png', $png))->assertSessionHasErrors(['file' => 'Use a smaller photo or a PDF.']);
    }

    // ---- helpers ----

    /** @param array<string, mixed> $overrides */
    private function typeInput(array $overrides = []): array
    {
        return ['code' => 'MC', 'name' => 'Sick leave', 'is_paid' => true, 'uses_balance' => false, 'allow_half_days' => false, 'evidence_needed' => true,
            'accepts_evidence_files' => true, 'evidence_retention_days' => 365, ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function type(User $manager, array $overrides = []): LeaveType
    {
        $this->selectBranch($manager);
        $this->put(route('hr.leave.types.save'), $this->typeInput($overrides))->assertSessionHasNoErrors();
        $this->put(route('hr.leave.working-days'), ['branch_id' => $this->branch->id, 'weekdays' => [1, 2, 3, 4, 5],
            'expected_lock_version' => DB::table('hr_branch_working_days')->where('branch_id', $this->branch->id)->value('lock_version')])->assertSessionHasNoErrors();

        return LeaveType::query()->where('code', 'MC')->sole();
    }

    private function applyFor(User $person, LeaveType $type, string $date = '2027-03-08'): LeaveRequest
    {
        $this->selectBranch($person);
        $this->post(route('hr.leave.requests.store'), ['leave_type_id' => $type->id, 'start_date' => $date, 'end_date' => $date, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return LeaveRequest::query()->where('user_id', $person->id)->whereDate('start_date', $date)->sole();
    }

    private function pdf(string $name = 'mc.pdf', string $mark = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $mark === '' ? self::PDF : str_replace('%%EOF', "{$mark}\n%%EOF", self::PDF));
    }

    private function addFile(User $actor, LeaveRequest $request, UploadedFile $file, ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.leave.evidence.store', $request->public_id), ['file' => $file, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function remove(User $actor, LeaveEvidenceFile $file, array $input = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.leave.evidence.remove', $file->public_id), ['expected_lock_version' => $file->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(), ...$input]);
    }
}
