<?php

namespace Tests\Feature\Compliance;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Domain\Organisation\Compliance\Services\ComplianceStatus;
use App\Domain\Organisation\Compliance\Services\LicenceService;
use App\Domain\Organisation\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-11a clinic licences (docs/teams/hr/HR-11-DESIGN.md; owner, 2026-11-10: "yes, order is fine, go ahead"). Synthetic
 * data only. The clock is frozen in Kuala Lumpur so every status is branch-local.
 */
class LicencesTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-LICENCE-MARKER\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(LicenceService::DISK);
        $this->travelTo(CarbonImmutable::parse('2027-03-08 09:00', $this->branch->timezone));
    }

    /** @param array<string, mixed> $extra */
    private function addType(array $extra = []): string
    {
        $this->post(route('clinic-compliance.types.save'), [...['name' => 'X-ray licence', 'expires' => true, 'warn_from_days' => 60, 'idempotency_key' => (string) Str::uuid()], ...$extra])->assertSessionHasNoErrors();

        return (string) DB::table('compliance_licence_types')->where('name', $extra['name'] ?? 'X-ray licence')->value('public_id');
    }

    /** @param array<string, mixed> $extra */
    private function record(string $type, array $extra = [], ?UploadedFile $file = null): TestResponse
    {
        $data = [...['type_id' => $type, 'branch_id' => $this->branch->id, 'issued_on' => '2026-06-01', 'expires_on' => '2027-06-01', 'reference' => 'LIC-SYNTH-001', 'note' => 'Synthetic note', 'idempotency_key' => (string) Str::uuid()], ...$extra];
        if ($file !== null) {
            $data['files'] = [$file];
        }

        return $this->post(route('clinic-compliance.licences.store'), $data);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('licence.pdf', self::PDF);
    }

    private function director(): void
    {
        $this->selectBranch($this->actor('director'));
    }

    public function test_grants_are_exact_and_nobody_else_reaches_the_page(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array(CompliancePermissions::LICENCES_MANAGE, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['administrator', 'director', 'hr_manager'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(CompliancePermissions::LICENCES_MANAGE));
        foreach (['technical_admin', 'resident_doctor', 'finance_officer', 'panel_officer', 'marketing', 'business_development', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('clinic-compliance.index'))->assertForbidden();
            $this->post(route('clinic-compliance.types.save'), ['name' => 'X', 'expires' => true, 'warn_from_days' => 60, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        }
        // The CA and CA Supervisor reach the page for the fridge log only, never the licences.
        foreach (['ca', 'ca_supervisor'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('clinic-compliance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('compliance.tabs', ['fridge']));
            $this->post(route('clinic-compliance.types.save'), ['name' => 'X', 'expires' => true, 'warn_from_days' => 60, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        }
        foreach (['director' => ['overview', 'licences', 'fridge', 'settings'], 'administrator' => ['overview', 'licences', 'fridge', 'settings'], 'hr_manager' => ['overview', 'licences', 'settings']] as $role => $tabs) {
            $this->selectBranch($this->actor($role));
            $this->get(route('clinic-compliance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('compliance.tabs', $tabs));
        }
    }

    public function test_kpone_ships_no_licence_type_and_a_type_is_added_changed_and_archived_with_its_rules(): void
    {
        $this->director();
        $this->get(route('clinic-compliance.index'))->assertInertia(fn (Assert $page) => $page->where('compliance.setUp', false));
        $type = $this->addType();
        $row = DB::table('compliance_licence_types')->where('public_id', $type)->first();
        $this->assertTrue((bool) $row->expires);
        $this->assertSame(60, (int) $row->warn_from_days);

        // Names are unique among active types, ignoring case.
        $this->post(route('clinic-compliance.types.save'), ['name' => 'x-RAY licence', 'expires' => false, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('name');
        // A type that expires needs warning days.
        $this->post(route('clinic-compliance.types.save'), ['name' => 'Waste', 'expires' => true, 'warn_from_days' => '', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('warn_from_days');
        $this->post(route('clinic-compliance.types.save'), ['name' => 'Waste', 'expires' => true, 'warn_from_days' => 400, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('warn_from_days');
        // A change needs the current version, and keeps whether it expires.
        $this->post(route('clinic-compliance.types.save'), ['type_id' => $type, 'name' => 'X-ray licence (new)', 'warn_from_days' => 30, 'expected_lock_version' => 99, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->post(route('clinic-compliance.types.save'), ['type_id' => $type, 'name' => 'X-ray licence (new)', 'warn_from_days' => 30, 'expected_lock_version' => 1, 'expires' => false, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $row = DB::table('compliance_licence_types')->where('public_id', $type)->first();
        $this->assertSame('X-ray licence (new)', $row->name);
        $this->assertTrue((bool) $row->expires);
        $this->assertSame(30, (int) $row->warn_from_days);
        $this->assertSame(2, (int) $row->lock_version);

        // A type that does not expire holds no warning days.
        $this->post(route('clinic-compliance.types.save'), ['name' => 'Insurance', 'expires' => false, 'warn_from_days' => 60, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('compliance_licence_types')->where('name', 'Insurance')->value('warn_from_days'));

        $this->post(route('clinic-compliance.types.archive', $type), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertFalse((bool) DB::table('compliance_licence_types')->where('public_id', $type)->value('is_active'));
        $this->record($type)->assertSessionHasErrors('type_id');
        $this->assertSame(0, DB::table('compliance_licence_types')->where('name', 'Waste')->count());
    }

    public function test_a_retry_with_the_same_key_replays_and_a_different_payload_with_that_key_is_refused(): void
    {
        $this->director();
        $key = (string) Str::uuid();
        $this->post(route('clinic-compliance.types.save'), ['name' => 'Calibration', 'expires' => true, 'warn_from_days' => 60, 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->post(route('clinic-compliance.types.save'), ['name' => 'Calibration', 'expires' => true, 'warn_from_days' => 60, 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('compliance_licence_types')->where('name', 'Calibration')->count());
        $this->post(route('clinic-compliance.types.save'), ['name' => 'Different', 'expires' => true, 'warn_from_days' => 60, 'idempotency_key' => $key])->assertSessionHasErrors('compliance');
        $this->assertSame(0, DB::table('compliance_licence_types')->where('name', 'Different')->count());
    }

    public function test_recording_a_licence_keeps_a_scan_encrypted_audits_ids_only_and_a_renewal_needs_the_current_version(): void
    {
        $this->director();
        $type = $this->addType();
        $this->record($type, [], $this->pdf())->assertSessionHasNoErrors();

        $series = DB::table('compliance_licences')->first();
        $this->assertSame(1, (int) $series->current_version);
        $this->assertSame($this->branch->id, (int) $series->branch_id);
        $version = DB::table('compliance_licence_versions')->first();
        $this->assertNotSame('LIC-SYNTH-001', $version->reference_encrypted);
        $file = DB::table('compliance_licence_files')->first();
        $this->assertSame('Scan 1', $file->label);
        $this->assertSame('pdf', $file->kind);
        $stored = Storage::disk(LicenceService::DISK)->get($file->stored_path);
        $this->assertStringNotContainsString('SYNTHETIC-LICENCE-MARKER', $stored);
        $this->assertStringNotContainsString('licence.pdf', $file->stored_path);

        $audit = AuditLog::query()->where('event', 'compliance.licence_recorded')->sole();
        $encoded = json_encode($audit->metadata);
        $this->assertStringNotContainsString('LIC-SYNTH-001', (string) $encoded);
        $this->assertStringNotContainsString('Synthetic note', (string) $encoded);
        $this->assertStringNotContainsString('licence.pdf', (string) $encoded);

        // The same type and branch cannot start a second series.
        $this->record($type)->assertSessionHasErrors('type_id');
        // A renewal sends the licence and its version; a stale one is refused.
        $this->record($type, ['licence_id' => $series->public_id, 'expected_lock_version' => 9, 'expires_on' => '2028-06-01'])->assertSessionHasErrors('compliance');
        $this->record($type, ['licence_id' => $series->public_id, 'expected_lock_version' => $series->lock_version, 'issued_on' => '2027-05-30', 'expires_on' => '2028-06-01'])->assertSessionHasNoErrors();
        $this->assertSame(2, (int) DB::table('compliance_licences')->value('current_version'));
        $this->assertSame(2, DB::table('compliance_licence_versions')->count());
    }

    public function test_dates_expiry_rules_files_and_branches_are_checked(): void
    {
        $this->director();
        $type = $this->addType();
        $this->record($type, ['expires_on' => ''])->assertSessionHasErrors('expires_on');
        $this->record($type, ['issued_on' => '2027-01-01', 'expires_on' => '2027-01-01'])->assertSessionHasErrors('expires_on');
        $this->record($type, ['expires_on' => '2027-02-30'])->assertSessionHasErrors('expires_on');
        $this->record($type, ['branch_id' => 999999])->assertSessionHasErrors('branch_id');
        $this->record($type, ['reference' => str_repeat('x', 121)])->assertSessionHasErrors('reference');
        $this->record($type, [], UploadedFile::fake()->createWithContent('licence.pdf', 'not a pdf at all'))->assertSessionHasErrors('files');
        $this->assertSame(0, DB::table('compliance_licences')->count());
        // Four files are refused before any is read.
        $this->post(route('clinic-compliance.licences.store'), ['type_id' => $type, 'branch_id' => $this->branch->id, 'issued_on' => '2026-06-01', 'expires_on' => '2027-06-01', 'idempotency_key' => (string) Str::uuid(),
            'files' => [$this->pdf(), $this->pdf(), $this->pdf(), $this->pdf()]])->assertSessionHasErrors('files');
        $this->assertSame(0, DB::table('compliance_licences')->count());

        // A type that does not expire takes no expiry date.
        $this->post(route('clinic-compliance.types.save'), ['name' => 'Insurance', 'expires' => false, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $insurance = (string) DB::table('compliance_licence_types')->where('name', 'Insurance')->value('public_id');
        $this->record($insurance, ['branch_id' => '', 'expires_on' => '2028-01-01'])->assertSessionHasErrors('expires_on');
        $this->record($insurance, ['branch_id' => '', 'expires_on' => ''])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('compliance_licences')->value('branch_id'));

        // A branch of another organisation is refused (and so is a deactivated one).
        $org = new Organisation;
        $org->forceFill(['code' => 'COMP_OTHER_'.Str::upper(Str::random(6)), 'name' => 'Synthetic Other Organisation', 'is_active' => true])->save();
        $other = $this->branch->replicate();
        $other->forceFill(['organisation_id' => $org->id, 'code' => 'OTHER_'.Str::upper(Str::random(5))]);
        if (array_key_exists('public_id', $other->getAttributes())) {
            $other->public_id = (string) Str::uuid();
        }
        $other->save();
        $this->record($type, ['branch_id' => $other->id])->assertSessionHasErrors('branch_id');
        $this->branch->forceFill(['is_active' => false])->save();
        $this->record($type)->assertSessionHasErrors('branch_id');
    }

    public function test_an_earlier_expiry_than_the_one_in_force_is_refused_and_marking_in_error_brings_the_older_version_back(): void
    {
        $this->director();
        $type = $this->addType();
        $this->record($type)->assertSessionHasNoErrors();
        $series = DB::table('compliance_licences')->first();
        $this->record($type, ['licence_id' => $series->public_id, 'expected_lock_version' => $series->lock_version, 'expires_on' => '2027-05-01'])->assertSessionHasErrors('expires_on');
        $this->record($type, ['licence_id' => $series->public_id, 'expected_lock_version' => $series->lock_version, 'expires_on' => '2028-06-01'])->assertSessionHasNoErrors();

        $series = DB::table('compliance_licences')->first();
        $second = DB::table('compliance_licence_versions')->where('version', 2)->first();
        $this->post(route('clinic-compliance.versions.error', $second->public_id), ['reason' => 'nonsense', 'expected_lock_version' => $series->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('reason');
        $this->post(route('clinic-compliance.versions.error', $second->public_id), ['reason' => 'wrong_dates', 'expected_lock_version' => 99, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->post(route('clinic-compliance.versions.error', $second->public_id), ['reason' => 'wrong_dates', 'expected_lock_version' => $series->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        // Nothing is deleted: the entry is still there, marked, and the first version is in force again.
        $this->assertSame(2, DB::table('compliance_licence_versions')->count());
        $this->assertSame(1, DB::table('compliance_licence_corrections')->count());
        $this->get(route('clinic-compliance.index', ['tab' => 'licences']))->assertInertia(fn (Assert $page) => $page
            ->where('compliance.licences.rows.0.expiresOn', '2027-06-01')
            ->where('compliance.licences.rows.0.history.0.errorReason', 'Wrong dates')
            ->where('compliance.licences.rows.0.history.1.inForce', true));
        // A second mark on the same entry is refused.
        $series = DB::table('compliance_licences')->first();
        $this->post(route('clinic-compliance.versions.error', $second->public_id), ['reason' => 'duplicate', 'expected_lock_version' => $series->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->assertSame(1, AuditLog::query()->where('event', 'compliance.licence_marked_in_error')->count());
    }

    public function test_opening_a_file_is_audited_first_sent_safely_and_never_crosses_organisations(): void
    {
        $this->director();
        $type = $this->addType();
        $this->record($type, [], $this->pdf())->assertSessionHasNoErrors();
        $file = DB::table('compliance_licence_files')->first();

        $response = $this->get(route('clinic-compliance.files.open', $file->public_id))->assertOk();
        $this->assertSame(self::PDF, $response->getContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('nosniff', (string) $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('licence.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(1, AuditLog::query()->where('event', 'compliance.licence_file_opened')->count());

        $this->get(route('clinic-compliance.files.open', (string) Str::uuid()))->assertNotFound();
        $this->selectBranch($this->actor('ca'));
        $this->get(route('clinic-compliance.files.open', $file->public_id))->assertForbidden();
        $this->assertSame(1, AuditLog::query()->where('event', 'compliance.licence_file_opened')->count());
    }

    public function test_a_failed_save_removes_the_file_it_wrote(): void
    {
        $this->director();
        $type = $this->addType();
        // The branch row is checked after the type is read; an unknown branch fails before any file is written, and
        // nothing is left on the disk.
        $this->record($type, ['branch_id' => 999999], $this->pdf())->assertSessionHasErrors('branch_id');
        $this->assertSame([], Storage::disk(LicenceService::DISK)->allFiles('compliance'));
        $this->assertSame(0, DB::table('compliance_licence_files')->count());
    }

    public function test_the_page_works_out_status_from_the_branch_local_day_and_never_stores_it(): void
    {
        $this->director();
        $type = $this->addType(['warn_from_days' => 30]);
        $this->record($type, ['expires_on' => '2027-03-20'])->assertSessionHasNoErrors();
        $columns = DB::getSchemaBuilder()->getColumnListing('compliance_licences') + DB::getSchemaBuilder()->getColumnListing('compliance_licence_versions');
        $this->assertEmpty(array_filter($columns, fn (string $c): bool => str_contains($c, 'status')));

        $this->get(route('clinic-compliance.index', ['tab' => 'licences']))->assertInertia(fn (Assert $page) => $page
            ->where('compliance.today', '2027-03-08')->where('compliance.licences.rows.0.status.code', 'expiring')->where('compliance.licences.rows.0.status.words', 'Expires in 12 days'));
        $this->travelTo(CarbonImmutable::parse('2027-03-21 00:30', $this->branch->timezone));
        $this->get(route('clinic-compliance.index', ['tab' => 'overview']))->assertInertia(fn (Assert $page) => $page
            ->where('compliance.overview.counts.expired', 1)->where('compliance.overview.attention.0.status.words', 'Expired yesterday'));
    }

    public function test_status_words_cover_every_boundary(): void
    {
        $in = fn (string $day): object => (object) ['expires_on' => $day];
        $this->assertSame('not_recorded', ComplianceStatus::licence(true, 60, null, '2027-03-08')['code']);
        $this->assertSame('no_expiry', ComplianceStatus::licence(false, null, $in('2027-03-08'), '2027-03-08')['code']);
        $this->assertSame('valid', ComplianceStatus::licence(true, 60, $in('2027-05-08'), '2027-03-08')['code']);
        $this->assertSame('Expires in 60 days', ComplianceStatus::licence(true, 60, $in('2027-05-07'), '2027-03-08')['words']);
        $this->assertSame('Expires tomorrow', ComplianceStatus::licence(true, 60, $in('2027-03-09'), '2027-03-08')['words']);
        $this->assertSame('Expires today', ComplianceStatus::licence(true, 60, $in('2027-03-08'), '2027-03-08')['words']);
        $this->assertSame('Expired yesterday', ComplianceStatus::licence(true, 60, $in('2027-03-07'), '2027-03-08')['words']);
        $this->assertSame('Expired 3 days ago', ComplianceStatus::licence(true, 60, $in('2027-03-05'), '2027-03-08')['words']);
    }

    public function test_the_overview_lists_what_needs_attention_and_types_with_no_licence(): void
    {
        $this->director();
        $xray = $this->addType();
        $this->addType(['name' => 'Clinical waste']);
        $this->record($xray, ['expires_on' => '2027-04-01'])->assertSessionHasNoErrors();
        $this->get(route('clinic-compliance.index'))->assertInertia(fn (Assert $page) => $page
            ->where('compliance.tab', 'overview')->where('compliance.overview.counts.expiring', 1)
            ->where('compliance.overview.attention.0.typeName', 'X-ray licence')->where('compliance.overview.typesWithoutRecord', ['Clinical waste']));
    }

    public function test_the_hub_card_shows_for_holders_only(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->get('/clinic-management')->assertInertia(fn (Assert $page) => $page->where('groups', fn ($groups): bool => str_contains(json_encode($groups) ?: '', '/clinic-compliance')));
        $this->selectBranch($this->actor('resident_doctor'));
        $this->get('/clinic-management')->assertInertia(fn (Assert $page) => $page->where('groups', fn ($groups): bool => ! str_contains(json_encode($groups) ?: '', '/clinic-compliance')));
    }

    public function test_postgresql_keeps_records_and_enforces_its_own_rules(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The compliance rules are PostgreSQL only.');
        }
        $this->director();
        $type = $this->addType();
        $this->record($type, [], $this->pdf())->assertSessionHasNoErrors();
        $version = DB::table('compliance_licence_versions')->first();
        $file = DB::table('compliance_licence_files')->first();
        $series = DB::table('compliance_licences')->first();
        $typeRow = DB::table('compliance_licence_types')->first();

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
        // Marking an entry in error is itself kept: it cannot be un-marked.
        $this->post(route('clinic-compliance.versions.error', $version->public_id), ['reason' => 'wrong_dates', 'expected_lock_version' => $series->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $correction = DB::table('compliance_licence_corrections')->first();
        $refused(fn () => DB::table('compliance_licence_corrections')->where('id', $correction->id)->delete(), $kept);
        $refused(fn () => DB::table('compliance_licence_corrections')->where('id', $correction->id)->update(['reason_code' => 'other']), $kept);
        $refused(fn () => DB::table('compliance_licence_versions')->where('id', $version->id)->update(['expires_on' => '2030-01-01']), $kept);
        $refused(fn () => DB::table('compliance_licence_versions')->where('id', $version->id)->delete(), $kept);
        $refused(fn () => DB::table('compliance_licence_files')->where('id', $file->id)->delete(), $kept);
        $refused(fn () => DB::statement('TRUNCATE compliance_licence_versions CASCADE'), $kept);
        $refused(fn () => DB::table('compliance_licences')->where('id', $series->id)->delete(), $kept);
        $refused(fn () => DB::table('compliance_licence_types')->where('id', $typeRow->id)->delete(), $kept);
        // A series keeps its type and branch and moves up by one.
        $refused(fn () => DB::table('compliance_licences')->where('id', $series->id)->update(['branch_id' => null]), 'keeps its type and branch');
        $refused(fn () => DB::table('compliance_licences')->where('id', $series->id)->update(['current_version' => 5]), 'moves up by one');
        // A type keeps whether it expires and stays archived.
        $refused(fn () => DB::table('compliance_licence_types')->where('id', $typeRow->id)->update(['expires' => false, 'warn_from_days' => null]), 'keeps whether it expires');
        // Versions are numbered without gaps and match the type's expiry rule.
        $base = ['public_id' => (string) Str::uuid(), 'organisation_id' => $series->organisation_id, 'licence_id' => $series->id, 'entered_by_user_id' => $series->created_by_user_id, 'created_at' => now()];
        $refused(fn () => DB::table('compliance_licence_versions')->insert([...$base, 'version' => 3, 'expires_on' => '2028-01-01']), 'without gaps');
        $refused(fn () => DB::table('compliance_licence_versions')->insert([...$base, 'version' => 2, 'expires_on' => null]), 'exactly when its type expires');
        $refused(fn () => DB::table('compliance_licence_versions')->insert([...$base, 'version' => 2, 'issued_on' => '2027-01-01', 'expires_on' => '2026-01-01']), 'compliance_licence_versions_shape');
        // At most 3 files per version.
        $more = fn (int $n): array => ['public_id' => (string) Str::uuid(), 'organisation_id' => $series->organisation_id, 'version_id' => $version->id, 'label' => "Scan {$n}", 'kind' => 'pdf', 'bytes' => 10,
            'sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'added_by_user_id' => $series->created_by_user_id, 'created_at' => now()];
        DB::table('compliance_licence_files')->insert($more(2));
        DB::table('compliance_licence_files')->insert($more(3));
        $refused(fn () => DB::table('compliance_licence_files')->insert([...$more(3), 'label' => 'Photo 3']), 'at most 3 files');
        // One series per type and branch (and per type for the whole clinic).
        $dup = ['public_id' => (string) Str::uuid(), 'organisation_id' => $series->organisation_id, 'type_id' => $series->type_id, 'branch_id' => $series->branch_id, 'current_version' => 1, 'created_by_user_id' => $series->created_by_user_id, 'created_at' => now(), 'updated_at' => now()];
        $refused(fn () => DB::table('compliance_licences')->insert($dup), 'compliance_licences_one_series');
    }
}
