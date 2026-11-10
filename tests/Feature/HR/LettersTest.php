<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Letters\LetterPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-10a letters (HR-PLAN 20.3; owner, 2026-11-10). Synthetic data only.
 */
class LettersTest extends VisitTestCase
{
    private const EN = "Dear {name},\n\nWe confirm your appointment as {position} at {branch} from {start_date}.\n\nReference {reference}, dated {date}. SYNTHETIC-LETTER-TEXT";

    private const BM = "Kepada {name},\n\nKami mengesahkan pelantikan anda sebagai {position} di {branch} mulai {start_date}.\n\nRujukan {reference}, bertarikh {date}.";

    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_the_grant_is_exact_and_staff_see_only_their_own_letters(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array(LetterPermissions::ISSUE, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(LetterPermissions::ISSUE));
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.letters.index'))->assertForbidden();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.letters.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/Letters')
            ->where('letters.tabs', ['mine'])->where('letters.canIssue', false)->where('letters.issued', [])->where('letters.templates', []));
        $this->post(route('hr.letters.templates.store'), $this->templateInput())->assertForbidden();
        $this->post(route('hr.letters.issue'), [])->assertForbidden();
        $this->post(route('hr.letters.preview'), [])->assertForbidden();
        $this->assertSame(0, DB::table('hr_letter_templates')->count());
    }

    public function test_templates_take_only_known_fields_and_each_save_is_a_new_version(): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.letters.templates.store'), $this->templateInput(['body_en' => 'Dear {name}, your {salary} is changed from today.']))
            ->assertSessionHasErrors('body_en');
        $this->post(route('hr.letters.templates.store'), $this->templateInput(['body_bm' => 'Kepada {name}, {details} sila ambil perhatian.']))
            ->assertSessionHasErrors('body_bm'); // {details} is for warning and show-cause letters only
        $this->post(route('hr.letters.templates.store'), $this->templateInput(['body_en' => 'Dear {name}, <b>welcome</b> to the clinic team.']))
            ->assertSessionHasErrors('body_en');
        $this->post(route('hr.letters.templates.store'), $this->templateInput(['body_en' => 'Dear {name}, a stray { brace is not a field here.']))
            ->assertSessionHasErrors('body_en');
        $this->post(route('hr.letters.templates.store'), $this->templateInput(['type' => 'offer']))->assertSessionHasErrors('type');
        $this->assertSame(0, DB::table('hr_letter_templates')->count());

        $template = $this->template();
        $row = DB::table('hr_letter_templates')->where('public_id', $template)->sole();
        $this->assertSame(1, (int) $row->current_version);
        $this->post(route('hr.letters.templates.revise', $template), [...$this->templateInput(), 'expected_lock_version' => 1])
            ->assertSessionHasErrors('template'); // nothing changed
        $this->post(route('hr.letters.templates.revise', $template), [...$this->templateInput(['title' => 'Confirmation of appointment v2']), 'expected_lock_version' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame([1, 2], DB::table('hr_letter_template_versions')->where('template_id', $row->id)->orderBy('version')->pluck('version')->map(fn ($v) => (int) $v)->all());
        $this->post(route('hr.letters.templates.revise', $template), [...$this->templateInput(['title' => 'Stale']), 'expected_lock_version' => 1])
            ->assertSessionHasErrors('records');
        $this->post(route('hr.letters.templates.retire', $template), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.letters.templates.revise', $template), [...$this->templateInput(['title' => 'After retiring']), 'expected_lock_version' => 3])
            ->assertSessionHasErrors('template');
        $this->assertSame(['hr.letters.template_created', 'hr.letters.template_revised', 'hr.letters.template_retired'],
            DB::table('audit_logs')->where('event', 'like', 'hr.letters.%')->orderBy('id')->pluck('event')->all());
    }

    public function test_hr_issues_a_numbered_letter_from_a_frozen_snapshot_and_staff_open_their_own(): void
    {
        $this->businessDetails();
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        $template = $this->template();

        $this->selectBranch($this->hr);
        $preview = $this->postJson(route('hr.letters.preview'), ['worker_id' => $worker->public_id, 'template_id' => $template, 'language' => 'bm'])->assertOk()->json();
        $this->assertStringContainsString('Kepada '.$staff->name, $preview['body']);
        $this->assertStringContainsString('sebagai Clinic Assistant di Cheras mulai 1 Mac 2026', $preview['body']);
        $this->assertStringContainsString('Rujukan HRL/2026/……, bertarikh 10 November 2026', $preview['body']);
        $this->assertSame(0, DB::table('hr_letters')->count());

        $first = $this->issue($worker, $template)->assertSessionHasNoErrors();
        $second = $this->issue($worker, $template, ['language' => 'bm'])->assertSessionHasNoErrors();
        $letters = DB::table('hr_letters')->orderBy('id')->get();
        $this->assertSame(['HRL/2026/000001', 'HRL/2026/000002'], $letters->pluck('reference')->all());
        $this->assertSame(2, (int) DB::table('hr_letter_counters')->where('year', 2026)->value('last_number'));
        // The snapshot is encrypted: no name or letter text in the table, and nothing in audit.
        $this->assertStringNotContainsString($staff->name, (string) $letters[0]->snapshot_encrypted);
        $this->assertStringNotContainsString('SYNTHETIC-LETTER-TEXT', DB::table('audit_logs')->get()->toJson());
        $snapshot = json_decode(Crypt::decryptString((string) $letters[0]->snapshot_encrypted), true);
        $this->assertSame('Synthetic Letter Clinic', $snapshot['letterhead']['clinicName']);
        $this->assertSame('Tel: 03-2000 0001 · letters@clinic.test', $snapshot['letterhead']['contact']);
        $this->assertStringContainsString('as Clinic Assistant at Cheras from 1 March 2026', $snapshot['body']);
        $this->assertStringContainsString('Reference HRL/2026/000001, dated 10 November 2026', $snapshot['body']);

        // A later template change or a new position never reaches an issued letter.
        app(StaffFileService::class)->addPosition($this->hr, $worker->public_id, ['title' => 'Senior Clinic Assistant', 'from_date' => '2026-11-01', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($staff);
        $this->get(route('hr.letters.show', $letters[0]->public_id))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/Letter')
            ->where('letter.own', true)->where('letter.reference', 'HRL/2026/000001')->where('letter.status', 'current')
            ->where('letter.body', fn (string $body) => str_contains($body, 'as Clinic Assistant at')));
        $this->assertSame(1, DB::table('hr_letter_opens')->where('opened_by_user_id', $staff->id)->where('own', true)->count());
        $this->get(route('hr.letters.index'))->assertInertia(fn (Assert $page) => $page->has('letters.mine', 2));
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('lettersMine', 2));
        // Someone else's letter is not found for anyone without the issue permission.
        $this->selectBranch($this->actor('ca'));
        $this->get(route('hr.letters.show', $letters[0]->public_id))->assertNotFound();
        $this->assertSame(1, DB::table('hr_letter_opens')->count());
        $this->assertNotNull($first);
        $this->assertNotNull($second);
    }

    public function test_replace_void_and_given_keep_every_letter(): void
    {
        $this->businessDetails();
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        $template = $this->template();
        $this->selectBranch($this->hr);
        $this->issue($worker, $template)->assertSessionHasNoErrors();
        $original = DB::table('hr_letters')->sole();

        $this->issue($worker, $template, ['replaces' => $original->public_id])->assertSessionHasNoErrors();
        $original = DB::table('hr_letters')->where('id', $original->id)->sole();
        $this->assertSame('replaced', $original->void_reason);
        $replacement = DB::table('hr_letters')->where('replaces_letter_id', $original->id)->sole();
        $this->assertSame('HRL/2026/000002', $replacement->reference);
        $this->issue($worker, $template, ['replaces' => $original->public_id])->assertSessionHasErrors('replaces'); // void cannot be replaced

        $given = fn (string $date, int $version) => $this->post(route('hr.letters.given', $replacement->public_id),
            ['given_on' => $date, 'expected_lock_version' => $version, 'idempotency_key' => (string) Str::uuid()]);
        $given('2026-11-11', 1)->assertSessionHasErrors('given_on'); // tomorrow
        $given('2026-11-09', 1)->assertSessionHasErrors('given_on'); // before it was issued
        $given('2026-11-10', 1)->assertSessionHasNoErrors();
        $given('2026-11-10', 2)->assertSessionHasErrors('letter');

        $void = fn (string $reason, int $version) => $this->post(route('hr.letters.void', $replacement->public_id),
            ['reason' => $reason, 'expected_lock_version' => $version, 'idempotency_key' => (string) Str::uuid()]);
        $void('replaced', 2)->assertSessionHasErrors('reason'); // "replaced" is set only by a replacement
        $void('wrong_content', 1)->assertSessionHasErrors('records');
        $void('wrong_content', 2)->assertSessionHasNoErrors();
        $void('other', 3)->assertSessionHasErrors('letter');
        $this->assertSame(2, DB::table('hr_letters')->count());
        $this->get(route('hr.letters.show', $replacement->public_id))->assertInertia(fn (Assert $page) => $page->where('letter.status', 'void')->where('letter.own', false));
        $this->selectBranch($staff);
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('lettersMine', 0));
    }

    public function test_who_may_issue_to_whom(): void
    {
        $this->businessDetails();
        $template = $this->template();
        $otherHr = $this->actor('hr_manager');
        $hrFile = $this->file($otherHr, $this->director);
        $directorFile = $this->file($this->actor('director'), $this->director);
        $ownFile = $this->file($this->hr, $this->director);

        $this->selectBranch($this->hr);
        $this->issue($ownFile, $template)->assertSessionHasErrors('letter');
        $this->issue($hrFile, $template)->assertSessionHasErrors('letter'); // an HR Manager's letters only by a Director
        $this->issue($directorFile, $template)->assertSessionHasErrors('letter');
        $this->assertSame(0, DB::table('hr_letters')->count());
        $this->assertSame(3, DB::table('audit_logs')->where('event', 'authorization.denied')->count());

        $this->selectBranch($this->director);
        $this->issue($hrFile, $template)->assertSessionHasNoErrors();
        // A locum's file has no account and can take a letter.
        $locum = app(StaffFileService::class)->addLocum($this->hr, ['display_name' => 'Synthetic Locum', 'phone' => '012-345 6789', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($this->hr);
        $this->issue($locum, $template)->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('hr_letters')->count());
        $this->get(route('hr.letters.index', ['tab' => 'issued']))->assertInertia(fn (Assert $page) => $page
            ->where('letters.workers', fn ($workers) => collect($workers)->firstWhere('id', $ownFile->public_id)['refusal'] !== null
                && collect($workers)->firstWhere('id', $locum->public_id)['refusal'] === null));
    }

    public function test_issue_refuses_a_stale_preview_a_missing_paragraph_and_an_incomplete_letterhead(): void
    {
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        $template = $this->template();
        $this->selectBranch($this->hr);
        $this->issue($worker, $template)->assertSessionHasErrors('letterhead');
        $this->businessDetails();
        $this->issue($worker, $template, ['expected_template_version' => 2])->assertSessionHasErrors('template_id');
        $this->issue($worker, $template, ['details' => 'Not a paragraph template'])->assertSessionHasErrors('details');
        $this->issue($worker, $template, ['language' => 'fr'])->assertSessionHasErrors('language');

        $warning = $this->template(['type' => 'warning', 'title' => 'Written warning',
            'body_en' => "Dear {name},\n\n{details}\n\nReference {reference}.", 'body_bm' => "Kepada {name},\n\n{details}\n\nRujukan {reference}."]);
        $this->issue($worker, $warning)->assertSessionHasErrors('details');
        $this->issue($worker, $warning, ['details' => 'You were late on three synthetic days in October.'])->assertSessionHasNoErrors();
        // A replacement is the same kind of letter.
        $this->issue($worker, $template, ['replaces' => DB::table('hr_letters')->value('public_id')])->assertSessionHasErrors('replaces');
        $this->assertSame(1, DB::table('hr_letters')->count());
        $this->assertSame(1, (int) DB::table('hr_letter_counters')->value('last_number'));
    }

    public function test_postgresql_keeps_letters_numbers_and_templates(): void
    {
        $this->businessDetails();
        $worker = $this->file($this->actor('ca'));
        $template = $this->template();
        $this->selectBranch($this->hr);
        $this->issue($worker, $template)->assertSessionHasNoErrors();
        $letter = DB::table('hr_letters')->sole();
        $version = DB::table('hr_letter_template_versions')->sole();

        $this->assertRefused('P0001', fn () => DB::table('hr_letters')->where('id', $letter->id)->update(['snapshot_hash' => str_repeat('a', 64), 'lock_version' => 2]));
        $this->assertRefused('P0001', fn () => DB::table('hr_letters')->where('id', $letter->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_letter_counters')->update(['last_number' => 0]));
        $this->assertRefused('P0001', fn () => DB::table('hr_letter_counters')->update(['last_number' => 5]));
        $this->assertRefused('P0001', fn () => DB::table('hr_letter_template_versions')->where('id', $version->id)->update(['title' => 'Changed']));
        $this->assertRefused('P0001', fn () => DB::statement('TRUNCATE hr_letters CASCADE'));
        $this->assertRefused('23514', fn () => DB::table('hr_letters')->insert([...(array) $letter, 'id' => null, 'public_id' => (string) Str::uuid(),
            'number' => 7, 'reference' => 'HRL/2026/000007', 'replaces_letter_id' => null]));
        $this->assertRefused('23514', fn () => DB::table('hr_letters')->where('id', $letter->id)->update(['void_reason' => 'nonsense', 'voided_at' => now(), 'voided_by_user_id' => $this->hr->id, 'lock_version' => 2]));
        DB::table('hr_letters')->where('id', $letter->id)->update(['voided_at' => now(), 'voided_by_user_id' => $this->hr->id, 'void_reason' => 'other', 'lock_version' => 2]);
        $this->assertRefused('P0001', fn () => DB::table('hr_letters')->where('id', $letter->id)->update(['voided_at' => null, 'voided_by_user_id' => null, 'void_reason' => null, 'lock_version' => 3]));
    }

    public function test_the_legal_name_is_only_in_the_letter_and_its_audited_preview(): void
    {
        $this->businessDetails();
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        app(StaffFileService::class)->savePersonal($this->hr, $worker->public_id, ['legal_name' => 'Synthetic Legal Name Binti Contoh',
            'expected_lock_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $template = $this->template();
        $this->selectBranch($this->hr);
        $this->issue($worker, $template)->assertSessionHasNoErrors();

        // The lists show the account name, as Staff files does; the legal name stays behind HR-4a's audited Show.
        foreach (['issued', 'templates'] as $tab) {
            $props = (string) json_encode($this->get(route('hr.letters.index', ['tab' => $tab]))->assertOk()->viewData('page')['props']);
            $this->assertStringNotContainsString('Synthetic Legal Name', $props);
            $this->assertStringContainsString($staff->name, $props);
        }
        $shown = fn () => DB::table('audit_logs')->where('event', 'hr.records.value_shown')->where('actor_user_id', $this->hr->id)->count();
        $this->assertSame(0, $shown());
        $preview = $this->postJson(route('hr.letters.preview'), ['worker_id' => $worker->public_id, 'template_id' => $template, 'language' => 'en'])->assertOk()->json();
        $this->assertStringContainsString('Dear Synthetic Legal Name Binti Contoh', $preview['body']);
        $this->assertSame(1, $shown());
        $snapshot = json_decode(Crypt::decryptString((string) DB::table('hr_letters')->value('snapshot_encrypted')), true);
        $this->assertSame('Synthetic Legal Name Binti Contoh', $snapshot['recipient']);
        // The disciplinary paragraph is never flashed into the session.
        $this->assertContains('details', (fn () => $this->dontFlash)->call(app(ExceptionHandler::class)));
    }

    public function test_an_inactive_account_takes_no_new_letter_and_postgresql_ties_replaced_and_templates(): void
    {
        $this->businessDetails();
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        $template = $this->template();
        $this->selectBranch($this->hr);
        $this->issue($worker, $template)->assertSessionHasNoErrors();
        $letter = DB::table('hr_letters')->sole();
        // "Replaced" needs a replacement pointing at the letter.
        $this->assertRefused('P0001', fn () => DB::table('hr_letters')->where('id', $letter->id)->update(['voided_at' => now(), 'voided_by_user_id' => $this->hr->id,
            'void_reason' => 'replaced', 'lock_version' => 2]));
        // A letter comes only from the current version of a template that is not retired.
        $row = DB::table('hr_letter_templates')->where('public_id', $template)->sole();
        $this->post(route('hr.letters.templates.retire', $template), ['expected_lock_version' => $row->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        DB::table('hr_letter_counters')->update(['last_number' => 2]);
        $this->assertRefused('23514', fn () => DB::table('hr_letters')->insert([...(array) $letter, 'id' => null, 'public_id' => (string) Str::uuid(),
            'number' => 2, 'reference' => 'HRL/2026/000002', 'replaces_letter_id' => null]));

        $other = $this->template(['title' => 'Second confirmation']);
        $this->selectBranch($this->hr);
        $staff->forceFill(['is_active' => false])->save();
        $this->issue($worker, $other)->assertSessionHasErrors('worker_id');
        $this->assertSame(1, DB::table('hr_letters')->count());
    }

    // ---- fixture ----

    /** @param array<string, mixed> $overrides */
    private function templateInput(array $overrides = []): array
    {
        return ['type' => 'confirmation', 'title' => 'Confirmation of appointment', 'body_en' => self::EN, 'body_bm' => self::BM,
            'idempotency_key' => (string) Str::uuid(), ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function template(array $overrides = []): string
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.letters.templates.store'), $this->templateInput($overrides))->assertSessionHasNoErrors();

        return (string) DB::table('hr_letter_templates')->orderByDesc('id')->value('public_id');
    }

    private function file(User $subject, ?User $manager = null): Worker
    {
        $manager ??= $this->hr;
        $files = app(StaffFileService::class);
        $worker = $files->openFile($manager, ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()]);
        $files->addPeriod($manager, $worker->public_id, ['type' => 'permanent', 'starts_on' => '2026-03-01', 'idempotency_key' => (string) Str::uuid()]);
        $files->addPosition($manager, $worker->public_id, ['title' => 'Clinic Assistant', 'from_date' => '2026-03-01', 'idempotency_key' => (string) Str::uuid()]);

        return $worker->fresh() ?? $worker;
    }

    /** @param array<string, mixed> $overrides */
    private function issue(Worker $worker, string $template, array $overrides = []): TestResponse
    {
        $version = (int) DB::table('hr_letter_templates')->where('public_id', $template)->value('current_version');

        return $this->post(route('hr.letters.issue'), ['worker_id' => $worker->public_id, 'template_id' => $template, 'language' => 'en',
            'expected_template_version' => $version, 'idempotency_key' => (string) Str::uuid(), ...$overrides]);
    }

    private function businessDetails(): void
    {
        app(BusinessDetailsService::class)->save($this->director, [
            'expected_branch_id' => $this->branch->id, 'organisation_lock_version' => (int) OrganisationBusinessProfile::query()->value('lock_version'),
            'branch_lock_version' => (int) DB::table('branch_business_profiles')->where('branch_id', $this->branch->id)->value('lock_version'),
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Group', 'clinic_name' => 'Synthetic Letter Clinic',
            'address_line_1' => '1 Example Street', 'postcode' => '50000', 'city' => 'Example City', 'state' => 'Example State',
            'country' => 'MY', 'phone' => '03-2000 0001', 'email' => 'letters@clinic.test', 'registration_type' => 'BRN', 'registration_number' => 'SYN-1',
        ], null);
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
