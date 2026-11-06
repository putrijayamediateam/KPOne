<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Records\Models\BankDetail;
use App\Domain\HR\Records\Models\PersonalDetails;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-4a staff files (owner, 2026-11-06; HR-PLAN section 11). Synthetic `.test` identities and made-up numbers only.
 * The clock is frozen in Kuala Lumpur so every date is branch-local.
 */
class RecordsTest extends VisitTestCase
{
    private const IC = '900101145678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-03-08 09:00', $this->branch->timezone));
    }

    public function test_the_permissions_are_exact_and_each_role_sees_its_tabs(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders(RecordPermissions::VIEW_ORGANISATION));
        $this->assertSame(['director', 'hr_manager'], $holders(RecordPermissions::MANAGE));
        $this->assertNotContains('queue_display', $holders(RecordPermissions::VIEW_OWN));
        $this->assertCount(11, $holders(RecordPermissions::VIEW_OWN));
        foreach ([RecordPermissions::VIEW_ORGANISATION, RecordPermissions::MANAGE, RecordPermissions::VIEW_OWN] as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
        }
        $this->assertEqualsCanonicalizing(['director', 'hr_manager'], Role::query()->whereHas('permissions', fn ($q) => $q->where('name', RecordPermissions::MANAGE))->pluck('name')->all());

        $this->selectBranch($this->actor('hr_manager'));
        $this->get(route('hr.records.index', ['tab' => 'files']))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/Records')
            ->where('records.tabs', ['my', 'files'])->where('records.tab', 'files')->where('records.files.rows', [])->where('records.my', null));
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.records.index', ['tab' => 'files']))->assertInertia(fn (Assert $page) => $page->where('records.tabs', ['my'])->where('records.files', null));
        $this->post(route('hr.records.files.store'), ['user_id' => $ca->id, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->get(route('hr.records.show', (string) Str::uuid()))->assertForbidden();
        $this->post(route('hr.records.show-value', (string) Str::uuid()), ['field' => 'identity'])->assertForbidden();
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.records.index'))->assertForbidden();
        $this->post(route('hr.records.my.corrections.store'), ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_a_file_is_opened_once_per_account_and_hr_never_changes_the_account(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $tv = $this->actor('queue_display');
        $before = $this->untouched();

        $key = (string) Str::uuid();
        $this->open($hr, $ca, $key)->assertRedirect();
        $this->open($hr, $ca, $key)->assertSessionHasNoErrors(); // a retry opens nothing new
        $this->assertSame(1, Worker::query()->where('user_id', $ca->id)->count());
        $this->open($hr, $ca)->assertSessionHasErrors(['user_id' => "{$ca->name} already has a staff file."]);
        $this->open($hr, $tv)->assertSessionHasErrors(['user_id' => 'The TV account has no staff file.']);
        $this->selectBranch($hr);
        $this->post(route('hr.records.locums.store'), ['display_name' => 'Dr Locum Synthetic', 'phone' => '012-345 6789', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $locum = Worker::query()->where('kind', 'locum')->firstOrFail();
        $this->assertNull($locum->user_id);
        $this->assertSame('0123456789', $locum->locum_phone_encrypted);
        $this->assertStringNotContainsString('0123456789', (string) DB::table('hr_workers')->where('id', $locum->id)->value('locum_phone_encrypted'));

        $this->get(route('hr.records.index', ['tab' => 'files']))->assertInertia(fn (Assert $page) => $page->has('records.files.rows', 2)
            ->where('records.files.rows.0.name', 'Dr Locum Synthetic')->where('records.files.rows.0.role', 'Locum')->where('records.files.rows.0.status', 'none'));
        $this->assertRefused('23505', fn () => DB::table('hr_workers')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'user_id' => $ca->id,
            'kind' => 'staff', 'created_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]));
        $this->assertRefused('23514', fn () => DB::table('hr_workers')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'user_id' => null,
            'kind' => 'staff', 'created_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_workers')->where('id', $locum->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_workers')->where('id', $locum->id)->update(['kind' => 'staff']));
        $this->assertSame($before, $this->untouched());
    }

    public function test_nobody_manages_their_own_file_and_the_director_and_hr_files_are_protected(): void
    {
        $director = $this->actor('director');
        $director2 = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $hr2 = $this->actor('hr_manager');
        $hrWorker = $this->workerFor($director, $hr);
        $directorWorker = $this->workerFor($director2, $director);

        $this->open($hr, $hr2)->assertRedirect();
        $hr3 = $this->actor('hr_manager');
        $this->open($hr3, $hr3)->assertSessionHasErrors(['records' => 'You may not manage your own staff file. Ask someone else, or use My HR details.']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'authorization.denied')->where('actor_user_id', $hr3->id)->count());
        $this->assertSame(0, Worker::query()->where('user_id', $hr3->id)->count());
        $this->period($hr, $hrWorker, ['type' => 'permanent', 'starts_on' => '2026-01-01'])->assertSessionHasErrors(['records' => 'You may not manage your own staff file. Ask someone else, or use My HR details.']);
        $this->period($hr, $directorWorker, ['type' => 'permanent', 'starts_on' => '2026-01-01'])->assertSessionHasErrors(['records' => "Only a Director may change a Director's staff file."]);
        $this->period($hr2, $hrWorker, ['type' => 'permanent', 'starts_on' => '2026-01-01'])->assertSessionHasNoErrors();
        $this->period($director2, $directorWorker, ['type' => 'permanent', 'starts_on' => '2026-01-01'])->assertSessionHasNoErrors();

        // Rule 3 holds on every write, not only periods (Suresh, S7), and the page offers no control that would be refused.
        $this->personal($hr, $directorWorker, ['nationality' => 'Malaysian'])->assertSessionHasErrors(['records' => "Only a Director may change a Director's staff file."]);
        $this->bank($hr, $directorWorker, [])->assertSessionHasErrors(['records' => "Only a Director may change a Director's staff file."]);
        $this->selectBranch($hr);
        $this->post(route('hr.records.positions.store', $directorWorker->public_id), ['title' => 'Director', 'from_date' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['records' => "Only a Director may change a Director's staff file."]);
        $this->bank($director2, $directorWorker, [])->assertSessionHasNoErrors();
        $directorBank = BankDetail::query()->where('worker_id', $directorWorker->id)->firstOrFail();
        $this->decideBank($hr, $directorWorker, $directorBank, 'confirm')->assertSessionHasErrors(['records' => "Only a Director may change a Director's staff file."]);
        $this->get(route('hr.records.show', $directorWorker->public_id))->assertInertia(fn (Assert $page) => $page->where('file.canManage', false)
            ->where('file.manageRefusal', "Only a Director may change a Director's staff file."));
        $newDirector = $this->actor('director');
        $this->get(route('hr.records.index', ['tab' => 'files']))->assertInertia(fn (Assert $page) => $page
            ->where('records.files.withoutFile', fn ($list) => collect($list)->pluck('id')->intersect([$newDirector->id, $hr->id])->isEmpty() && collect($list)->pluck('id')->contains($hr3->id)));
        $this->assertSame(0, BankDetail::query()->where('worker_id', $directorWorker->id)->where('status', 'confirmed')->count());

        // The manager opening their own file sees it read only (no manage controls).
        $this->selectBranch($hr);
        $this->get(route('hr.records.show', $hrWorker->public_id))->assertInertia(fn (Assert $page) => $page->component('HR/StaffFile')
            ->where('file.isSelf', true)->where('file.canManage', false));
    }

    public function test_employment_periods_never_overlap_and_an_ended_period_changes_only_with_a_reason(): void
    {
        $hr = $this->actor('hr_manager');
        $worker = $this->workerFor($hr, $this->actor('ca'));
        $before = $this->untouched();

        $this->period($hr, $worker, ['type' => 'contract', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'])->assertSessionHasErrors(['end_reason' => 'Choose why the employment ended.']);
        $this->period($hr, $worker, ['type' => 'contract', 'starts_on' => '2026-01-01', 'probation_ends_on' => '2025-12-01'])->assertSessionHasErrors('probation_ends_on');
        $this->period($hr, $worker, ['type' => 'contract', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'end_reason' => 'other'])->assertSessionHasErrors(['note' => 'Add a short note for Other.']);
        $this->period($hr, $worker, ['type' => 'contract', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'end_reason' => 'contract_ended'])->assertSessionHasNoErrors();
        $this->period($hr, $worker, ['type' => 'permanent', 'starts_on' => '2026-12-01'])->assertSessionHasErrors(['starts_on' => 'This overlaps another employment period. End that one first.']);
        $this->period($hr, $worker, ['type' => 'permanent', 'starts_on' => '2027-01-01', 'probation_ends_on' => '2027-03-15'])->assertSessionHasNoErrors();
        $ended = DB::table('hr_employment_periods')->where('worker_id', $worker->id)->orderBy('starts_on')->first();
        $this->assertNotNull($ended);

        $this->selectBranch($hr);
        $update = fn (array $input) => $this->put(route('hr.records.periods.update', [$worker->public_id, $ended->public_id]),
            [...$input, 'expected_lock_version' => DB::table('hr_employment_periods')->where('id', $ended->id)->value('lock_version'), 'idempotency_key' => (string) Str::uuid()]);
        $update(['ends_on' => '2026-11-30'])->assertSessionHasErrors(['edit_reason' => 'Choose why this is being changed.']);
        $update(['ends_on' => '2027-01-15', 'edit_reason' => 'wrong_date'])->assertSessionHasErrors('starts_on');
        $update(['ends_on' => '2026-11-30', 'edit_reason' => 'wrong_date'])->assertSessionHasNoErrors();
        $this->assertSame(['ends_on'], json_decode((string) DB::table('hr_record_changes')->where('area', 'employment')->orderByDesc('id')->value('fields'), true));
        $this->assertSame('wrong_date', DB::table('hr_record_changes')->orderByDesc('id')->value('reason_code'));

        $this->get(route('hr.records.show', $worker->public_id))->assertInertia(fn (Assert $page) => $page->where('file.worker.status', 'probation')
            ->where('file.worker.probationEndsOn', '2027-03-15')->where('file.attention', ['Probation ends in 7 days'])->has('file.periods', 2));
        $this->assertRefused('23P01', fn () => DB::table('hr_employment_periods')->where('id', $ended->id)->update(['ends_on' => '2027-02-01']));
        $this->assertRefused('P0001', fn () => DB::table('hr_employment_periods')->where('id', $ended->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_record_changes')->delete());
        $this->assertSame($before, $this->untouched());
    }

    public function test_identity_numbers_are_encrypted_masked_unique_and_shown_only_with_an_audit_row(): void
    {
        $hr = $this->actor('hr_manager');
        $workerA = $this->workerFor($hr, $this->actor('ca'));
        $workerB = $this->workerFor($hr, $this->actor('ca'));

        $this->personal($hr, $workerA, ['identity_kind' => 'ic', 'identity_number' => '9001'])->assertSessionHasErrors(['identity_number' => 'Enter the 12-digit IC number.']);
        $this->personal($hr, $workerA, ['identity_kind' => 'passport', 'identity_number' => 'A1234567', 'passport_country' => 'Malaysia'])->assertSessionHasErrors('passport_country');
        $this->personal($hr, $workerA, ['legal_name' => 'Synthetic Legal Name', 'identity_kind' => 'ic', 'identity_number' => '900101-14-5678',
            'date_of_birth' => '1990-01-01', 'personal_phone' => '+60 12-111 2222', 'address' => ['line1' => '1 Jalan Sintetik', 'city' => 'Cheras', 'postcode' => '56000']])->assertSessionHasNoErrors();
        $this->personal($hr, $workerB, ['identity_kind' => 'ic', 'identity_number' => self::IC], 0)->assertSessionHasErrors(['identity_number' => 'Another staff file already has this identity number.']);
        $this->personal($hr, $workerA, ['legal_name' => 'Other'], 0)->assertSessionHasErrors(['records' => 'This was changed by someone else. Reload to see the latest version.']);

        // A refused save never flashes the values back into the session (Suresh, S1).
        $this->personal($hr, $workerB, ['identity_kind' => 'ic', 'identity_number' => self::IC, 'legal_name' => 'Synthetic Flash Name', 'personal_phone' => '0129998888'], 0)
            ->assertSessionHasErrors('identity_number');
        $this->assertSame(['expected_lock_version'], array_keys((array) session('_old_input')));
        // The retry hash is keyed, so a plain digest of the request does not match it (Suresh, B1).
        $parts = [$hr->id, 'records_personal', $workerA->public_id, json_encode(['identity' => ['kind' => 'ic', 'number' => self::IC, 'country' => null]])];
        $this->assertNotSame(hash('sha256', (string) json_encode($parts)), app(RecordSupport::class)->hash($parts));
        $this->assertSame(hash_hmac('sha256', (string) json_encode($parts), 'kpone-hr-retry|'.config('app.key')), app(RecordSupport::class)->hash($parts));
        $this->assertStringNotContainsString(self::IC, (string) json_encode(DB::table('hr_record_retries')->get()->toArray()));
        $raw = (array) DB::table('hr_personal_details')->where('worker_id', $workerA->id)->first();
        foreach ([self::IC, 'Synthetic Legal Name', '1990-01-01', '1 Jalan Sintetik', '60121112222'] as $plain) {
            $this->assertStringNotContainsString($plain, (string) json_encode($raw));
        }
        $this->assertSame('5678', $raw['identity_last4']);
        $this->assertNoValuesInEvidence([self::IC, 'Synthetic Legal Name', '1990-01-01', 'Jalan Sintetik', '60121112222']);

        $this->selectBranch($hr);
        $this->get(route('hr.records.show', [$workerA->public_id, 'tab' => 'personal']))->assertInertia(fn (Assert $page) => $page
            ->where('file.personal.identity.mask', 'IC •••• •••• 5678')->where('file.personal.legalName', true)->where('file.personal.address', true));
        $page = $this->get(route('hr.records.show', [$workerA->public_id, 'tab' => 'personal']))->getContent();
        $this->assertStringNotContainsString(self::IC, (string) $page);
        $this->assertStringNotContainsString('Synthetic Legal Name', (string) $page);

        $audits = DB::table('audit_logs')->where('event', 'hr.records.value_shown')->count();
        $this->postJson(route('hr.records.show-value', $workerA->public_id), ['field' => 'identity'])->assertOk()->assertJsonPath('value', self::IC);
        $this->assertStringContainsString('no-store', (string) $this->postJson(route('hr.records.show-value', $workerA->public_id), ['field' => 'identity'])->headers->get('Cache-Control'));
        $this->postJson(route('hr.records.show-value', $workerA->public_id), ['field' => 'address'])->assertJsonPath('value', '1 Jalan Sintetik, Cheras, 56000');
        $this->postJson(route('hr.records.show-value', $workerB->public_id), ['field' => 'identity'])->assertStatus(422);
        $this->assertSame($audits + 3, DB::table('audit_logs')->where('event', 'hr.records.value_shown')->count());
        $this->assertSame(['field' => 'identity'], array_intersect_key(json_decode((string) DB::table('audit_logs')->where('event', 'hr.records.value_shown')->orderBy('id')->value('metadata'), true), ['field' => 1]));
    }

    public function test_bank_details_need_two_people_and_the_confirmed_account_stays_until_then(): void
    {
        $director = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $worker = $this->workerFor($hr, $ca);

        $this->bank($hr, $worker, ['account' => '12'])->assertSessionHasErrors('account');
        $this->bank($hr, $worker, [])->assertSessionHasNoErrors();
        $first = BankDetail::query()->where('worker_id', $worker->id)->firstOrFail();
        $this->assertSame('waiting', $first->status);
        $this->bank($director, $worker, ['account' => '5555 6666 7777'])->assertSessionHasErrors(['bank' => 'A bank change is already waiting. Confirm, reject or withdraw it first.']);
        $this->decideBank($hr, $worker, $first, 'confirm')->assertSessionHasErrors(['records' => 'Someone else confirms this. You entered it.']);
        $this->decideBank($director, $worker, $first, 'reject')->assertSessionHasErrors(['reason' => 'Choose why it is rejected.']);
        $this->decideBank($director, $worker, $first, 'confirm', null, false)->assertSessionHasErrors(['bank' => 'Show the new account number to check it before you confirm.']);
        $this->decideBank($director, $worker, $first, 'confirm')->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $first->fresh()?->status);

        $this->bank($director, $worker, ['bank_name' => 'Synthetic Bank Two', 'account' => '5555-6666-7777'])->assertSessionHasNoErrors();
        $second = BankDetail::query()->where('worker_id', $worker->id)->where('status', 'waiting')->firstOrFail();
        $this->selectBranch($director);
        $this->get(route('hr.records.show', [$worker->public_id, 'tab' => 'bank']))->assertInertia(fn (Assert $page) => $page
            ->where('file.bank.confirmed.mask', 'Account •••• 3456')->where('file.bank.waiting.mask', 'Account •••• 7777')->where('file.bank.waiting.enteredByMe', true)
            ->where('file.attention', ['Bank change waiting']));
        $this->decideBank($director, $worker, $second, 'confirm')->assertSessionHasErrors(['records' => 'Someone else confirms this. You entered it.']);
        $this->decideBank($hr, $worker, $second, 'reject', 'wrong_account')->assertSessionHasNoErrors();
        $this->assertSame(['confirmed', 'rejected'], BankDetail::query()->where('worker_id', $worker->id)->orderBy('id')->pluck('status')->all());

        $this->bank($director, $worker, ['account' => '5555666677770'])->assertSessionHasNoErrors();
        $third = BankDetail::query()->where('worker_id', $worker->id)->where('status', 'waiting')->firstOrFail();
        $this->selectBranch($hr);
        $this->post(route('hr.records.bank.withdraw', [$worker->public_id, $third->public_id]), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['records' => 'Only the person who entered this change may withdraw it.']);
        $this->decideBank($hr, $worker, $third, 'confirm')->assertSessionHasNoErrors();
        $this->assertSame(['replaced', 'rejected', 'confirmed'], BankDetail::query()->where('worker_id', $worker->id)->orderBy('id')->pluck('status')->all());
        $this->assertNoValuesInEvidence(['123456789012', '5555666677770', 'Synthetic Bank']);

        // PostgreSQL: the decider is never the person who entered it nor the worker; decided rows never move back.
        $fourth = $this->insertBank($worker, $hr);
        $this->assertRefused('23514', fn () => DB::table('hr_bank_details')->where('id', $fourth)->update(['status' => 'rejected', 'decided_by_user_id' => $hr->id, 'decided_at' => now(), 'decision_reason' => 'other']));
        $this->assertRefused('23514', fn () => DB::table('hr_bank_details')->where('id', $fourth)->update(['status' => 'rejected', 'decided_by_user_id' => $ca->id, 'decided_at' => now(), 'decision_reason' => 'other']));
        $this->assertRefused('P0001', fn () => DB::table('hr_bank_details')->where('id', $first->id)->update(['status' => 'confirmed']));
        $this->assertRefused('P0001', fn () => DB::table('hr_bank_details')->where('id', $third->id)->update(['account_last4' => '0000']));
        $this->assertRefused('23514', fn () => DB::table('hr_bank_details')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id,
            'bank_name_encrypted' => 'x', 'holder_encrypted' => 'x', 'account_encrypted' => 'x', 'account_last4' => '1234', 'status' => 'confirmed', 'entered_by_user_id' => $hr->id,
            'decided_by_user_id' => $director->id, 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_staff_see_their_own_values_edit_their_contact_and_ask_for_corrections(): void
    {
        $director = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $worker = $this->workerFor($hr, $ca);
        $this->personal($hr, $worker, ['legal_name' => 'Synthetic Old Name', 'identity_kind' => 'ic', 'identity_number' => self::IC, 'nationality' => 'Malaysian'])->assertSessionHasNoErrors();

        $this->selectBranch($ca);
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('records.my.hasFile', true)
            ->where('records.my.personal.legalName', 'Synthetic Old Name')->where('records.my.personal.identity.number', self::IC)->where('records.my.personal.personalPhone', null));
        $lock = (int) PersonalDetails::query()->where('worker_id', $worker->id)->value('lock_version');
        $this->put(route('hr.records.my.contact'), ['legal_name' => 'Sneaky', 'personal_phone' => '0123334444', 'expected_lock_version' => $lock, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $details = PersonalDetails::query()->where('worker_id', $worker->id)->firstOrFail();
        $this->assertSame('Synthetic Old Name', $details->legal_name_encrypted); // legal name is never self-edited
        $this->assertSame('0123334444', $details->personal_phone_encrypted);

        $ask = fn (array $input) => $this->post(route('hr.records.my.corrections.store'), [...$input, 'idempotency_key' => (string) Str::uuid()]);
        $ask(['kind' => 'legal_name', 'value' => 'Synthetic New Name'])->assertSessionHasErrors(['reason' => 'Choose a reason.']);
        $ask(['kind' => 'legal_name', 'value' => 'Synthetic New Name', 'reason' => 'other'])->assertSessionHasErrors(['note' => 'Add a short note for Other.']);
        $ask(['kind' => 'legal_name', 'value' => 'Synthetic New Name', 'reason' => 'changed'])->assertSessionHasNoErrors();
        $ask(['kind' => 'legal_name', 'value' => 'Again', 'reason' => 'changed'])->assertSessionHasErrors('kind');
        $ask(['kind' => 'bank', 'bank_name' => 'Synthetic Bank', 'holder' => 'Synthetic Holder', 'account' => '99887766554', 'reason' => 'changed'])->assertSessionHasNoErrors();
        $ask(['kind' => 'nationality', 'value' => 'Singaporean', 'reason' => 'wrong'])->assertSessionHasNoErrors();
        $this->assertNoValuesInEvidence(['Synthetic New Name', '99887766554', 'Singaporean']);

        $corrections = DB::table('hr_record_corrections')->where('worker_id', $worker->id)->orderBy('id')->get();
        $this->post(route('hr.records.corrections.decide', [$worker->public_id, $corrections[0]->public_id, 'apply']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->selectBranch($ca);
        $this->post(route('hr.records.my.corrections.withdraw', $corrections[2]->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect(route('hr.records.index', ['tab' => 'my']));

        $this->selectBranch($hr);
        $this->get(route('hr.records.show', [$worker->public_id, 'tab' => 'personal']))->assertInertia(fn (Assert $page) => $page->has('file.corrections', 2)
            ->where('file.corrections.1.recorded', 'Not given')->where('file.corrections.1.new', 'Synthetic Bank •••• 6554')
            ->where('file.attention', ['Correction waiting']));
        $this->postJson(route('hr.records.show-value', $worker->public_id), ['field' => 'correction', 'correction_id' => $corrections[0]->public_id])->assertJsonPath('value', 'Synthetic New Name');
        $decide = fn (User $actor, int $i, string $decision, array $input = []) => $this->actingAs($actor)->post(route('hr.records.corrections.decide', [$worker->public_id, $corrections[$i]->public_id, $decision]),
            [...$input, 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $decide($hr, 0, 'reject')->assertSessionHasErrors('reason');
        $decide($hr, 0, 'apply')->assertSessionHasNoErrors();
        $this->assertSame('Synthetic New Name', PersonalDetails::query()->where('worker_id', $worker->id)->value('legal_name_encrypted'));
        // An applied bank correction becomes a waiting change entered by the applier, so a third person confirms it.
        $decide($hr, 1, 'apply')->assertSessionHasNoErrors();
        $bank = BankDetail::query()->where('worker_id', $worker->id)->firstOrFail();
        $this->assertSame(['waiting', $hr->id], [$bank->status, $bank->entered_by_user_id]);
        $this->decideBank($hr, $worker, $bank, 'confirm')->assertSessionHasErrors(['records' => 'Someone else confirms this. You entered it.']);
        $this->decideBank($director, $worker, $bank, 'confirm')->assertSessionHasNoErrors();
        $this->assertSame(['applied', 'applied', 'withdrawn'], DB::table('hr_record_corrections')->where('worker_id', $worker->id)->orderBy('id')->pluck('status')->all());
        $this->assertRefused('P0001', fn () => DB::table('hr_record_corrections')->where('id', $corrections[0]->id)->update(['status' => 'rejected', 'decision_reason' => 'other']));

        $this->selectBranch($ca);
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('records.my.bank.account', '99887766554')
            ->where('records.my.corrections.0.status', 'withdrawn')->where('records.my.personal.legalName', 'Synthetic New Name'));
    }

    public function test_retries_replay_and_another_organisations_ids_are_not_found(): void
    {
        $hr = $this->actor('hr_manager');
        $worker = $this->workerFor($hr, $this->actor('ca'));
        $key = (string) Str::uuid();
        $this->bank($hr, $worker, [], $key)->assertSessionHasNoErrors();
        $this->bank($hr, $worker, [], $key)->assertSessionHasNoErrors();
        $this->assertSame(1, BankDetail::query()->where('worker_id', $worker->id)->count());
        $this->bank($hr, $worker, ['account' => '111122223333'], $key)->assertSessionHasErrors(['records' => 'This request was already used for a different change. Reload and try again.']);
        $this->selectBranch($hr);
        $this->post(route('hr.records.periods.store', (string) Str::uuid()), ['type' => 'permanent', 'starts_on' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->get(route('hr.records.show', (string) Str::uuid()))->assertNotFound();
        $this->post(route('hr.records.bank.decide', [$worker->public_id, (string) Str::uuid(), 'confirm']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_a_failed_audit_releases_nothing_and_other_organisations_and_lists_never_carry_values(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $worker = $this->workerFor($hr, $ca);
        $this->personal($hr, $worker, ['identity_kind' => 'ic', 'identity_number' => self::IC, 'legal_name' => 'Synthetic Legal Name'])->assertSessionHasNoErrors();

        // The Staff files list and My HR details of someone else never carry the values.
        $this->selectBranch($hr);
        $list = (string) $this->get(route('hr.records.index', ['tab' => 'files']))->getContent();
        $this->assertStringNotContainsString(self::IC, $list);
        $this->assertStringNotContainsString('Synthetic Legal Name', $list);
        $this->assertStringNotContainsString(self::IC, (string) $this->get(route('hr.records.index', ['tab' => 'my']))->getContent());

        // A real worker in another organisation is not found by any route.
        $other = $this->otherOrganisationWorker();
        $this->get(route('hr.records.show', $other->public_id))->assertNotFound();
        $this->postJson(route('hr.records.show-value', $other->public_id), ['field' => 'identity'])->assertNotFound();
        $this->post(route('hr.records.periods.store', $other->public_id), ['type' => 'permanent', 'starts_on' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post(route('hr.records.bank.store', $other->public_id), ['bank_name' => 'Synthetic Bank', 'holder' => 'Synthetic Holder', 'account' => '123456', 'idempotency_key' => (string) Str::uuid()])->assertNotFound();

        // If the audit row cannot be written, Show releases nothing (HR-PLAN 11.3 rule 7).
        $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable')));
        $response = $this->postJson(route('hr.records.show-value', $worker->public_id), ['field' => 'identity']);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString(self::IC, (string) $response->getContent());
    }

    private function otherOrganisationWorker(): Worker
    {
        $org = new Organisation;
        $org->forceFill(['code' => 'HR_OTHER_'.Str::upper(Str::random(6)), 'name' => 'Synthetic Other Organisation', 'is_active' => true])->save();
        $user = new User;
        $user->forceFill(['organisation_id' => $org->id, 'name' => 'Synthetic Other User', 'email' => 'other.'.Str::lower(Str::random(8)).'@kpone.test', 'is_active' => true])->save();
        $worker = new Worker;
        $worker->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $org->id, 'user_id' => $user->id, 'kind' => 'staff', 'created_by_user_id' => $user->id])->save();

        return $worker;
    }

    private function open(User $actor, User $subject, ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.records.files.store'), ['user_id' => $subject->id, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function workerFor(User $manager, User $subject): Worker
    {
        $this->open($manager, $subject)->assertSessionHasNoErrors();

        return Worker::query()->where('user_id', $subject->id)->firstOrFail();
    }

    /** @param array<string, mixed> $input */
    private function period(User $actor, Worker $worker, array $input): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.records.periods.store', $worker->public_id), [...$input, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function personal(User $actor, Worker $worker, array $input, ?int $lock = null): TestResponse
    {
        $this->selectBranch($actor);
        $lock ??= (int) (PersonalDetails::query()->where('worker_id', $worker->id)->value('lock_version') ?? 0);

        return $this->put(route('hr.records.personal', $worker->public_id), [...$input, 'expected_lock_version' => $lock, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function bank(User $actor, Worker $worker, array $input, ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.records.bank.store', $worker->public_id), [...['bank_name' => 'Synthetic Bank', 'holder' => 'Synthetic Holder', 'account' => '1234 5678 9012 3456'],
            ...$input, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function decideBank(User $actor, Worker $worker, BankDetail $bank, string $decision, ?string $reason = null, bool $show = true): TestResponse
    {
        $this->selectBranch($actor);
        if ($decision === 'confirm' && $show) {
            $this->postJson(route('hr.records.show-value', $worker->public_id), ['field' => 'bank_account', 'bank_id' => $bank->public_id]);
        }

        return $this->post(route('hr.records.bank.decide', [$worker->public_id, $bank->public_id, $decision]), ['reason' => $reason,
            'expected_lock_version' => (int) DB::table('hr_bank_details')->where('id', $bank->id)->value('lock_version'), 'idempotency_key' => (string) Str::uuid()]);
    }

    private function insertBank(Worker $worker, User $enteredBy): int
    {
        return (int) DB::table('hr_bank_details')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id,
            'bank_name_encrypted' => 'x', 'holder_encrypted' => 'x', 'account_encrypted' => 'x', 'account_last4' => '1234', 'status' => 'waiting', 'entered_by_user_id' => $enteredBy->id,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param list<string> $values */
    private function assertNoValuesInEvidence(array $values): void
    {
        $evidence = json_encode([DB::table('audit_logs')->where('event', 'like', 'hr.records.%')->get()->toArray(), DB::table('hr_record_changes')->get()->toArray()]);
        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, (string) $evidence);
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

    /** @return array<string, mixed> */
    private function untouched(): array
    {
        return json_decode((string) json_encode([
            'users' => DB::table('users')->orderBy('id')->get(['id', 'is_active', 'organisation_id', 'name'])->toArray(),
            'roles' => DB::table('model_has_roles')->orderBy('model_id')->orderBy('role_id')->get()->toArray(),
            'profiles' => DB::table('staff_profiles')->orderBy('id')->get()->toArray(),
            'assignments' => DB::table('staff_branch_assignments')->orderBy('id')->get()->toArray(),
            'shifts' => DB::table('hr_roster_shifts')->count(),
            'visits' => DB::table('visits')->count(),
        ]), true);
    }
}
