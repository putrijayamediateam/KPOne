<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Credentials\CredentialPermissions;
use App\Domain\HR\Credentials\Models\CredentialRecord;
use App\Domain\HR\Credentials\Models\CredentialType;
use App\Domain\HR\Records\Models\Document;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\DocumentService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-5a credential register (owner, 2026-11-06: "approve the HR-5 note with the defaults"; HR-PLAN 12.3). Synthetic
 * data only. The clock is frozen in Kuala Lumpur so every status is branch-local.
 */
class CredentialsTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-APC-MARKER\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentService::DISK);
        $this->travelTo(CarbonImmutable::parse('2027-03-08 09:00', $this->branch->timezone));
    }

    public function test_grants_are_exact_the_tv_account_gets_nothing_and_neither_is_authority(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders(CredentialPermissions::MANAGE));
        $own = $holders(CredentialPermissions::VIEW_OWN);
        $this->assertCount(11, $own);
        $this->assertNotContains('queue_display', $own);
        foreach (array_keys(CredentialPermissions::grants()) as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
            $this->assertNotSame('clinician_credentials.manage.organisation', $permission);
        }
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.credentials.index'))->assertForbidden();
        $this->selectBranch($this->actor('ca'));
        $this->get(route('hr.credentials.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('credentials.tabs', ['my'])->where('credentials.register', null));
        $this->post(route('hr.credentials.types.save'), ['code' => 'BLS', 'name' => 'BLS', 'expires' => true, 'warn_from_days' => 60, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_kpone_ships_no_type_and_types_are_versioned_with_fixed_expiry_and_health_settings(): void
    {
        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        $this->get(route('hr.credentials.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page->where('credentials.setUp', false)->has('credentials.settings.types', 0));
        $this->assertSame(0, DB::table('hr_credential_types')->count());

        $this->saveType($hr, ['code' => 'HEPB', 'name' => 'Hepatitis B vaccination', 'expires' => true, 'health_item' => true])
            ->assertSessionHasErrors(['expires' => 'A health item records only Done or Not done and a date, so it does not expire.']);
        $this->saveType($hr, ['code' => 'x', 'name' => 'Bad'])->assertSessionHasErrors('code');
        $this->saveType($hr, ['code' => 'APC', 'name' => 'Annual Practising Certificate (APC)', 'roles' => ['tv_account']])->assertSessionHasErrors('roles');
        $apc = $this->type($hr, 'APC', 'Annual Practising Certificate (APC)', true, false, ['resident_doctor']);
        $this->saveType($hr, ['code' => 'APC2', 'name' => 'annual practising certificate (apc)'])->assertSessionHasErrors(['name' => 'Another credential type already has this name.']);
        $this->saveType($hr, ['code' => 'APC', 'name' => 'Something else'])->assertSessionHasErrors('code');

        // A new version keeps the old one; the code and the expiry setting do not move.
        $this->saveType($hr, ['type_id' => $apc->public_id, 'name' => 'APC (MMC)', 'warn_from_days' => 30, 'roles' => ['resident_doctor', 'director'],
            'expected_lock_version' => $apc->lock_version, 'expires' => false])->assertSessionHasNoErrors();
        $apc->refresh();
        $this->assertSame(2, $apc->current_version);
        $this->assertTrue($apc->expires);
        $this->assertSame(['APC (MMC)', 'Annual Practising Certificate (APC)'], DB::table('hr_credential_type_versions')->where('type_id', $apc->id)->orderByDesc('version')->pluck('name')->all());
        $this->get(route('hr.credentials.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page
            ->where('credentials.settings.types.0.name', 'APC (MMC)')->has('credentials.settings.types.0.versions', 2)
            ->where('credentials.settings.types.0.versions.0.roles', ['director', 'resident_doctor']));

        $this->assertRefused('P0001', fn () => DB::table('hr_credential_type_versions')->where('type_id', $apc->id)->update(['name' => 'Changed']));
        $this->assertRefused('P0001', fn () => DB::table('hr_credential_types')->where('id', $apc->id)->update(['expires' => false]));
        $this->assertRefused('P0001', fn () => DB::table('hr_credential_types')->where('id', $apc->id)->delete());
        $this->assertRefused('23514', fn () => DB::table('hr_credential_types')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id, 'code' => 'BAD',
            'expires' => true, 'health_item' => true, 'current_version' => 1, 'created_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]));

        // Archiving takes it off the register and refuses new records; it cannot come back.
        $this->post(route('hr.credentials.types.archive', $apc->public_id), ['expected_lock_version' => $apc->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertFalse($apc->refresh()->is_active);
        $this->assertRefused('P0001', fn () => DB::table('hr_credential_types')->where('id', $apc->id)->update(['is_active' => true]));
        $this->assertNoValuesInAudit();
    }

    public function test_a_record_waits_until_someone_else_accepts_it_and_the_earlier_version_stays_in_force(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $worker = $this->workerFor($hr, $doctor);
        $apc = $this->type($hr, 'APC', 'Annual Practising Certificate (APC)', true, false, ['resident_doctor']);

        // The doctor sends their own, with evidence: it goes into the HR-4b vault as a Certificate.
        $this->sendOwn($doctor, ['type_id' => $apc->public_id, 'issued_on' => '2027-03-09', 'expires_on' => '2027-12-31'])->assertSessionHasErrors(['issued_on' => 'Choose an issue date that is not in the future.']);
        $this->sendOwn($doctor, ['type_id' => $apc->public_id, 'issued_on' => '2027-01-01', 'expires_on' => '2027-01-01'])->assertSessionHasErrors(['expires_on' => 'Choose an expiry date after the issue date.']);
        $this->sendOwn($doctor, ['type_id' => $apc->public_id, 'issued_on' => '2027-01-01'])->assertSessionHasErrors(['expires_on' => 'Choose the expiry date.']);
        $this->sendOwn($doctor, ['type_id' => $apc->public_id, 'issued_on' => '2027-01-01', 'expires_on' => '2027-12-31', 'issuing_body' => 'Synthetic Council',
            'reference' => 'SYN-APC-4321', 'health' => null], $this->pdf())->assertSessionHasNoErrors();
        $v1 = CredentialRecord::query()->where('worker_id', $worker->id)->firstOrFail();
        $this->assertSame('waiting', $v1->status);
        $this->assertSame('4321', $v1->reference_last4);
        $this->assertStringNotContainsString('SYN-APC-4321', (string) DB::table('hr_credential_records')->where('id', $v1->id)->value('reference_encrypted'));
        $document = Document::query()->whereKey($v1->document_id)->firstOrFail();
        $this->assertSame(['certificate', $worker->id], [$document->category, $document->worker_id]);
        $this->sendOwn($doctor, ['type_id' => $apc->public_id, 'issued_on' => '2027-01-02', 'expires_on' => '2027-12-31'])
            ->assertSessionHasErrors(['credentials' => 'A record for this credential is already waiting for check.']);

        // The person whose record it is cannot decide it (no permission), and is told someone else checks it.
        $this->selectBranch($doctor);
        $this->post(route('hr.credentials.decide', [$v1->public_id, 'accept']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->get(route('hr.credentials.index'))->assertInertia(fn (Assert $page) => $page
            ->where('credentials.my.cards.0.status.code', 'not_recorded')->where('credentials.my.cards.0.waiting.referenceLast4', '4321')
            ->missing('credentials.my.cards.0.waiting.reference'));

        $this->decide($hr, $v1, 'accept')->assertSessionHasNoErrors();
        $this->assertSame(['accepted', $hr->id], [$v1->refresh()->status, $v1->decided_by_user_id]);

        // A manager enters the renewal for the doctor: another manager accepts it, never the one who entered it.
        $this->enterFor($hr, $worker, ['type_id' => $apc->public_id, 'issued_on' => '2027-03-01', 'expires_on' => '2028-12-31'])->assertSessionHasNoErrors();
        $v2 = CredentialRecord::query()->where('worker_id', $worker->id)->where('version', 2)->firstOrFail();
        $this->decide($hr, $v2, 'accept')->assertSessionHasErrors(['credentials' => 'Someone else checks this. You entered it.']);
        $this->selectBranch($doctor);
        $this->get(route('hr.credentials.index'))->assertInertia(fn (Assert $page) => $page
            ->where('credentials.my.cards.0.inForce.expiresOn', '2027-12-31')->where('credentials.my.cards.0.waiting.expiresOn', '2028-12-31'));
        $this->decide($director, $v2, 'reject', null)->assertSessionHasErrors(['reason' => 'Choose why it is rejected.']);
        $this->decide($director, $v2, 'reject', 'wrong_dates')->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'wrong_dates'], [$v2->refresh()->status, $v2->decision_reason]);
        $this->assertSame('accepted', $v1->refresh()->status);

        $this->enterFor($director, $worker, ['type_id' => $apc->public_id, 'issued_on' => '2027-03-02', 'expires_on' => '2028-12-31'])->assertSessionHasNoErrors();
        $v3 = CredentialRecord::query()->where('worker_id', $worker->id)->where('version', 3)->firstOrFail();
        $this->decide($hr, $v3, 'accept')->assertSessionHasNoErrors();
        $this->assertSame(['replaced', 'accepted'], [$v1->refresh()->status, $v3->refresh()->status]);
        $this->assertSame($hr->id, $v1->decided_by_user_id);

        // PostgreSQL: nobody checks their own, entered values never change, versions have no gaps, nothing is deleted.
        $this->assertRefused('23514', fn () => DB::table('hr_credential_records')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id,
            'worker_id' => $worker->id, 'type_id' => $apc->id, 'version' => 9, 'issued_on' => '2027-01-01', 'expires_on' => '2028-01-01', 'status' => 'waiting',
            'entered_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]));
        $this->enterFor($hr, $worker, ['type_id' => $apc->public_id, 'issued_on' => '2027-03-03', 'expires_on' => '2028-12-31'])->assertSessionHasNoErrors();
        $v4 = CredentialRecord::query()->where('worker_id', $worker->id)->where('version', 4)->firstOrFail();
        $this->assertRefused('23514', fn () => DB::table('hr_credential_records')->where('id', $v4->id)->update(['status' => 'accepted', 'decided_by_user_id' => $doctor->id, 'decided_at' => now()]));
        $this->assertRefused('23514', fn () => DB::table('hr_credential_records')->where('id', $v4->id)->update(['status' => 'accepted', 'decided_by_user_id' => $hr->id, 'decided_at' => now()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_credential_records')->where('id', $v3->id)->update(['expires_on' => '2030-01-01']));
        $this->assertRefused('P0001', fn () => DB::table('hr_credential_records')->where('id', $v2->id)->update(['status' => 'accepted', 'decision_reason' => null]));
        $this->assertRefused('P0001', fn () => DB::table('hr_credential_records')->where('id', $v1->id)->delete());

        // The change list names the area only.
        $this->assertContains('credential', DB::table('hr_record_changes')->where('worker_id', $worker->id)->pluck('area')->all());
        $this->assertNoValuesInAudit();
    }

    public function test_statuses_follow_the_branch_local_date_and_not_recorded_counts_only_for_roles_that_need_it(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $ca = $this->actor('ca');
        $doctorFile = $this->workerFor($hr, $doctor);
        $this->workerFor($hr, $ca);
        $apc = $this->type($hr, 'APC', 'Annual Practising Certificate (APC)', true, false, ['resident_doctor'], 60);
        $this->type($hr, 'RAD', 'Radiation safety', false, false, []);

        $this->enterFor($hr, $doctorFile, ['type_id' => $apc->public_id, 'issued_on' => '2026-05-07', 'expires_on' => '2027-05-07'])->assertSessionHasNoErrors();
        $this->decide($director, CredentialRecord::query()->firstOrFail(), 'accept')->assertSessionHasNoErrors();

        $status = function (string $day) use ($doctor): array {
            $this->travelTo(CarbonImmutable::parse($day.' 00:30', $this->branch->timezone));
            $this->selectBranch($doctor);
            $card = $this->get(route('hr.credentials.index'))->viewData('page')['props']['credentials']['my']['cards'][0];

            return [$card['status']['code'], $card['status']['words']];
        };
        $this->assertSame(['valid', 'Valid'], $status('2027-03-07'));
        $this->assertSame(['expiring', 'Expires in 60 days'], $status('2027-03-08'));
        $this->assertSame(['expiring', 'Expires tomorrow'], $status('2027-05-06'));
        $this->assertSame(['expiring', 'Expires today'], $status('2027-05-07'));
        $this->assertSame(['expired', 'Expired yesterday'], $status('2027-05-08'));
        $this->assertSame(['expired', 'Expired 3 days ago'], $status('2027-05-10'));

        // My roster and My attendance carry the line in words; nothing is sent anywhere.
        $this->get(route('hr.roster.index'))->assertInertia(fn (Assert $page) => $page->where('credentialReminders', ['Your Annual Practising Certificate (APC) expired 3 days ago.']));
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->has('credentialReminders', 1));

        // The CA has nothing their role needs; the register shows "—" there and counts nothing for them.
        $this->selectBranch($ca);
        $this->get(route('hr.credentials.index'))->assertInertia(fn (Assert $page) => $page->has('credentials.my.cards', 0)->where('credentials.my.mmc', null));
        $this->get(route('hr.roster.index'))->assertInertia(fn (Assert $page) => $page->where('credentialReminders', []));
        $this->selectBranch($hr);
        $register = $this->get(route('hr.credentials.index', ['tab' => 'register']))->viewData('page')['props']['credentials']['register'];
        $this->assertSame(['expiring' => 0, 'expired' => 1, 'not_recorded' => 0, 'waiting' => 0], $register['summary']);
        $this->assertSame(2, $register['total']);
        $rows = collect($register['rows'])->keyBy('id');
        $this->assertNull($rows[Worker::query()->where('user_id', $ca->id)->value('public_id')]['cells'][$apc->public_id]);
        $this->assertSame('expired', $rows[$doctorFile->public_id]['cells'][$apc->public_id]['status']['code']);
        $this->assertSame('none', $rows[$doctorFile->public_id]['mmc']);
        $this->get(route('hr.credentials.index', ['tab' => 'register', 'status' => 'expired']))->assertInertia(fn (Assert $page) => $page->has('credentials.register.rows', 1));
    }

    public function test_health_items_hold_only_done_or_not_done_and_a_date_and_references_are_masked_and_shown_audited(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $other = $this->actor('ca');
        $file = $this->workerFor($hr, $ca);
        $this->workerFor($hr, $other);
        $hepb = $this->type($hr, 'HEPB', 'Hepatitis B vaccination', false, true, ['ca']);
        $bls = $this->type($hr, 'BLS', 'Basic Life Support (BLS)', true, false, ['ca']);

        $this->sendOwn($ca, ['type_id' => $hepb->public_id, 'health' => 'done', 'issued_on' => '2026-01-10', 'reference' => 'TITRE-9999'])
            ->assertSessionHasErrors(['health' => 'A health item records only Done or Not done and the date.']);
        $this->sendOwn($ca, ['type_id' => $hepb->public_id, 'health' => 'done', 'issued_on' => '2026-01-10'], $this->pdf())
            ->assertSessionHasErrors(['health' => 'A health item records only Done or Not done and the date.']);
        $this->sendOwn($ca, ['type_id' => $hepb->public_id, 'health' => 'not_done', 'issued_on' => '2026-01-10'])->assertSessionHasErrors('issued_on');
        $this->sendOwn($ca, ['type_id' => $hepb->public_id, 'health' => 'done'])->assertSessionHasErrors(['issued_on' => 'Choose the date it was done.']);
        $this->sendOwn($ca, ['type_id' => $hepb->public_id, 'health' => 'done', 'issued_on' => '2026-01-10'])->assertSessionHasNoErrors();
        $this->sendOwn($ca, ['type_id' => $bls->public_id, 'health' => 'done', 'issued_on' => '2026-01-10', 'expires_on' => '2028-01-10'])->assertSessionHasErrors('health');
        $this->sendOwn($ca, ['type_id' => $bls->public_id, 'issued_on' => '2026-01-10', 'expires_on' => '2028-01-10', 'reference' => 'SYN-BLS-7788'])->assertSessionHasNoErrors();
        $this->assertRefused('23514', fn () => DB::table('hr_credential_records')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id,
            'worker_id' => $file->id, 'type_id' => $hepb->id, 'version' => 2, 'issued_on' => '2026-01-10', 'health_done' => true, 'issuing_body' => 'Lab',
            'status' => 'waiting', 'entered_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]));

        foreach (CredentialRecord::query()->where('worker_id', $file->id)->get() as $record) {
            $this->decide($director, $record, 'accept')->assertSessionHasNoErrors();
        }
        $this->selectBranch($ca);
        $this->get(route('hr.credentials.index'))->assertInertia(fn (Assert $page) => $page
            ->where('credentials.my.cards.0.code', 'BLS')->where('credentials.my.cards.0.inForce.referenceLast4', '7788')
            ->where('credentials.my.cards.1.status.code', 'done')->where('credentials.my.cards.1.inForce.issuedOn', '2026-01-10'));
        $this->assertStringNotContainsString('SYN-BLS-7788', (string) json_encode($this->get(route('hr.credentials.index'))->viewData('page')));

        // Show is audited before the value is returned: the person sees their own; another staff member gets nothing.
        $bl = CredentialRecord::query()->where('type_id', $bls->id)->firstOrFail();
        $this->postJson(route('hr.credentials.show-reference', $bl->public_id))->assertOk()->assertJsonPath('value', 'SYN-BLS-7788');
        $this->assertStringContainsString('no-store', (string) $this->postJson(route('hr.credentials.show-reference', $bl->public_id))->headers->get('Cache-Control'));
        $this->selectBranch($other);
        $this->postJson(route('hr.credentials.show-reference', $bl->public_id))->assertForbidden();
        $this->get(route('hr.credentials.index'))->assertInertia(fn (Assert $page) => $page->where('credentials.my.cards.0.status.code', 'not_recorded'));
        $this->selectBranch($hr);
        $this->postJson(route('hr.credentials.show-reference', $bl->public_id))->assertOk();
        $this->assertSame(3, DB::table('audit_logs')->where('event', 'hr.credentials.value_shown')->count());
        $sent = DB::table('audit_logs')->where('event', 'hr.credentials.record_sent')->get()->map(fn (object $r): array => json_decode((string) $r->metadata, true));
        $this->assertNull($sent->firstWhere('code', 'HEPB')['issued_on']);
        $this->assertNoValuesInAudit();
    }

    public function test_rule_3_other_organisations_clinical_credentials_untouched_and_nothing_clinical_reads_the_register(): void
    {
        $hr = $this->actor('hr_manager');
        $hr2 = $this->actor('hr_manager');
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $doctorFile = $this->workerFor($hr, $doctor);
        $hrFile = $this->workerFor($director, $hr2);
        $directorFile = $this->workerFor($this->actor('director'), $director);
        $bls = $this->type($hr, 'BLS', 'Basic Life Support (BLS)', true, false, ['resident_doctor', 'hr_manager', 'director']);
        $before = DB::table('clinician_professional_profiles')->orderBy('id')->get()->toArray();

        // Rule 3: not your own file; the Director's only by a Director; an HR Manager's only by the Director or an HR Manager.
        $this->enterFor($hr, $directorFile, ['type_id' => $bls->public_id, 'issued_on' => '2027-01-01', 'expires_on' => '2029-01-01'])
            ->assertSessionHasErrors(['credentials' => "Only a Director may change a Director's staff file."]);
        $this->selectBranch($hr2);
        $this->post(route('hr.credentials.workers.store', $hrFile->public_id), ['type_id' => $bls->public_id, 'issued_on' => '2027-01-01', 'expires_on' => '2029-01-01',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('credentials');
        $this->sendOwn($hr2, ['type_id' => $bls->public_id, 'issued_on' => '2027-01-01', 'expires_on' => '2029-01-01'])->assertSessionHasNoErrors();
        $own = CredentialRecord::query()->where('worker_id', $hrFile->id)->firstOrFail();
        $this->decide($hr2, $own, 'accept')->assertSessionHasErrors('credentials');
        $this->decide($hr, $own, 'accept')->assertSessionHasNoErrors();

        // Another organisation's ids are not found; the same key twice is one record.
        $this->selectBranch($hr);
        $this->post(route('hr.credentials.workers.store', (string) Str::uuid()), ['type_id' => $bls->public_id, 'issued_on' => '2027-01-01', 'expires_on' => '2029-01-01',
            'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $key = (string) Str::uuid();
        foreach ([1, 2] as $attempt) {
            $this->post(route('hr.credentials.workers.store', $doctorFile->public_id), ['type_id' => $bls->public_id, 'issued_on' => '2027-01-01', 'expires_on' => '2029-01-01',
                'idempotency_key' => $key])->assertSessionHasNoErrors();
        }
        $this->assertSame(1, CredentialRecord::query()->where('worker_id', $doctorFile->id)->count());

        // DOC-02a's clinician credentials are untouched, and no clinical code reads the HR register (decision 4).
        $this->assertSame($before, DB::table('clinician_professional_profiles')->orderBy('id')->get()->toArray());
        $readers = collect(File::allFiles(app_path()))->filter(fn ($f): bool => str_contains((string) file_get_contents($f->getPathname()), 'hr_credential')
            || str_contains((string) file_get_contents($f->getPathname()), 'CredentialRecord'))
            ->map(fn ($f): string => str_replace(app_path().'/', '', $f->getPathname()))->filter(fn (string $p): bool => ! str_starts_with($p, 'Domain/HR/Credentials/'))->values()->all();
        $this->assertSame([], $readers);
        $this->assertSame(1, CredentialType::query()->count());
    }

    private function workerFor(User $manager, User $subject): Worker
    {
        $this->selectBranch($manager);
        $this->post(route('hr.records.files.store'), ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return Worker::query()->where('user_id', $subject->id)->firstOrFail();
    }

    /** @param array<string, mixed> $input */
    private function saveType(User $actor, array $input): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.credentials.types.save'), ['warn_from_days' => 60, 'expires' => true, 'health_item' => false, 'roles' => [], ...$input, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param list<string> $roles */
    private function type(User $actor, string $code, string $name, bool $expires, bool $health, array $roles, int $warn = 60): CredentialType
    {
        $this->saveType($actor, ['code' => $code, 'name' => $name, 'expires' => $expires, 'health_item' => $health, 'roles' => $roles, 'warn_from_days' => $warn])->assertSessionHasNoErrors();

        return CredentialType::query()->where('code', $code)->firstOrFail();
    }

    /** @param array<string, mixed> $input */
    private function sendOwn(User $actor, array $input, ?UploadedFile $file = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.credentials.my.store'), [...$input, ...($file === null ? [] : ['file' => $file]), 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function enterFor(User $actor, Worker $worker, array $input): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.credentials.workers.store', $worker->public_id), [...$input, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function decide(User $actor, CredentialRecord $record, string $decision, ?string $reason = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.credentials.decide', [$record->public_id, $decision]), ['reason' => $reason,
            'expected_lock_version' => (int) DB::table('hr_credential_records')->where('id', $record->id)->value('lock_version'), 'idempotency_key' => (string) Str::uuid()]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('apc-certificate.pdf', self::PDF);
    }

    private function assertNoValuesInAudit(): void
    {
        $audit = (string) json_encode(DB::table('audit_logs')->where('event', 'like', 'hr.%')->pluck('metadata')->all());
        foreach (['SYN-APC-4321', 'SYN-BLS-7788', 'Synthetic Council', 'SYNTHETIC', 'apc-certificate.pdf', 'TITRE'] as $value) {
            $this->assertStringNotContainsString($value, $audit);
        }
    }

    private function assertRefused(string $state, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}
