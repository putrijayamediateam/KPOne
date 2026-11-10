<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Memos\MemoPermissions;
use App\Domain\HR\Memos\Services\MemoAudience;
use App\Domain\HR\Sops\Services\SopService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\StaffBranchAssignmentBootstrapper;

/**
 * HR-8 announcements and memos (owner, via B.E.R.N.I.S.S, 2026-11-10). Synthetic data only.
 */
class MemosTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-MEMO-PDF\n%%EOF\n";

    private const TITLE = 'Synthetic clinic closing hours';

    private const BODY = "SYNTHETIC-MEMO-BODY\nThe clinic closes early on Friday.";

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(SopService::DISK);
        $this->other = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
    }

    public function test_grants_are_exact_the_tv_account_gets_nothing_and_neither_is_authority(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders(MemoPermissions::PUBLISH));
        $own = $holders(MemoPermissions::READ_OWN);
        $this->assertCount(11, $own);
        $this->assertNotContains('queue_display', $own);
        foreach (array_keys(MemoPermissions::grants()) as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
        }
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.memos.index'))->assertForbidden();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.memos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('memos.tabs', ['my'])->where('memos.manage', null)->where('memos.my', []));
        $this->publish($ca, [])->assertForbidden();
        $this->get(route('hr.memos.audience'))->assertForbidden();
        $this->assertSame(0, DB::table('hr_memos')->count());
    }

    public function test_the_audience_rules(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $caOther = $this->actor('ca', $this->other);
        $doctor = $this->actor('resident_doctor');
        $inactive = $this->actor('ca');
        $inactive->forceFill(['is_active' => false])->save();
        $tv = $this->actor('queue_display');
        $noBranch = User::factory()->create(['organisation_id' => $this->organisation->id, 'email' => 'nobranch.'.Str::lower(Str::random(6)).'@kpone.test']);
        $noBranch->assignRole('marketing');
        $audience = app(MemoAudience::class);
        $ids = fn (array $branches, array $roles): array => $audience->users($this->organisation->id, $branches, $roles)->pluck('id')->sort()->values()->all();
        $sorted = fn (User ...$users): array => collect($users)->pluck('id')->sort()->values()->all();

        // Nothing ticked: every active human account, with or without a branch; never the TV account or an inactive one.
        $this->assertSame($sorted($hr, $ca, $caOther, $doctor, $noBranch), $ids([], []));
        // Branch only: an effective assignment at that branch.
        $this->assertSame($sorted($hr, $ca, $doctor), $ids([$this->branch->id], []));
        // Role only, at any branch.
        $this->assertSame($sorted($ca, $caOther), $ids([], ['ca']));
        // Both: a ticked branch AND a ticked role.
        $this->assertSame($sorted($ca), $ids([$this->branch->id], ['ca']));
        $this->assertSame([], $ids([$this->branch->id], ['panel_officer']));
        // A person who works at two branches is expected for either.
        $profile = StaffProfile::query()->where('user_id', $ca->id)->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($profile, $this->other, ['is_primary' => false, 'assignment_type' => 'temporary',
            'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);
        $this->assertSame($sorted($ca, $caOther), $ids([$this->other->id], ['ca']));
        // An assignment that has ended, or has not started, does not count.
        $future = $this->actor('ca', $this->other);
        DB::table('staff_branch_assignments')->where('staff_profile_id', StaffProfile::query()->where('user_id', $future->id)->value('id'))
            ->update(['valid_from' => now()->addDays(3)->toDateString()]);
        $this->assertSame($sorted($ca, $caOther), $ids([$this->other->id], ['ca']));
        // A role change is followed live.
        $caOther->syncRoles(['panel_officer']);
        $this->assertSame($sorted($ca), $ids([$this->other->id], ['ca']));

        // The publish form's "Who will see this" count follows the same rules, and unknown choices are refused.
        $this->selectBranch($hr);
        $this->getJson(route('hr.memos.audience', ['branches' => [(string) $this->branch->id], 'roles' => ['ca']]))->assertOk()->assertJson(['count' => 1]);
        $this->getJson(route('hr.memos.audience'))->assertOk()->assertJson(['count' => count($ids([], []))]);
        $this->getJson(route('hr.memos.audience', ['roles' => ['queue_display']]))->assertStatus(422);
        $this->getJson(route('hr.memos.audience', ['branches' => ['999999']]))->assertStatus(422);
        // Nobody matching is refused at publish.
        $this->publish($hr, ['branches' => [(string) $this->branch->id], 'roles' => ['panel_officer']])->assertSessionHasErrors('audience');
        $this->assertSame(0, DB::table('hr_memos')->count());
    }

    public function test_a_memo_is_opened_before_it_is_read_and_a_pdf_is_opened_too(): void
    {
        $hr = $this->actor('hr_manager');
        $doctor = $this->actor('resident_doctor');
        $ca = $this->actor('ca');

        $this->publish($hr, ['title' => 'ab'])->assertSessionHasErrors('title');
        $this->publish($hr, ['body' => '   '])->assertSessionHasErrors('body');
        $this->publish($hr, ['body' => str_repeat('x', 4001)])->assertSessionHasErrors('body');
        $this->publish($hr, ['roles' => [['ca']]])->assertSessionHasErrors('audience');
        $this->publish($hr, [], UploadedFile::fake()->createWithContent('photo.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"))->assertSessionHasErrors(['file' => 'Use a PDF file.']);
        // One submit names everything to fix.
        $this->selectBranch($hr);
        $this->post(route('hr.memos.publish'), ['title' => '', 'body' => '', 'idempotency_key' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->createWithContent('photo.jpg', 'not a pdf')])->assertSessionHasErrors(['file', 'title', 'body']);
        $this->assertSame(0, DB::table('hr_memos')->count());
        $this->assertSame([], Storage::disk(SopService::DISK)->allFiles());

        // A retry with the same key publishes once. Text only first.
        $key = (string) Str::uuid();
        $this->publish($hr, ['roles' => ['resident_doctor', 'ca'], 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->publish($hr, ['roles' => ['resident_doctor', 'ca'], 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('hr_memos')->count());
        $memo = DB::table('hr_memos')->first();
        $this->assertNotNull($memo);
        // The text is encrypted at rest.
        $this->assertStringNotContainsString('SYNTHETIC-MEMO-BODY', (string) $memo->body);
        $this->assertSame(self::BODY, Crypt::decryptString((string) $memo->body));
        $this->assertNull($memo->pdf_path);

        $this->selectBranch($doctor);
        $this->get(route('hr.memos.index'))->assertInertia(fn (Assert $page) => $page->has('memos.my', 1)
            ->where('memos.my.0.title', self::TITLE)->where('memos.my.0.status', 'open')->where('memos.my.0.opened', false)
            ->where('memos.my.0.acknowledgedOn', null)->where('memos.my.0.publishedOn', '10 Nov 2026')->missing('memos.my.0.body'));
        $this->acknowledge($doctor, $memo->public_id)->assertSessionHasErrors(['memo' => 'Open the memo first.']);
        // The open is recorded first and returns the text; a second open adds no row but is audited.
        $this->postJson(route('hr.memos.open', $memo->public_id))->assertOk()->assertJson(['body' => self::BODY, 'hasPdf' => false]);
        $this->postJson(route('hr.memos.open', $memo->public_id))->assertOk();
        $this->assertSame(1, DB::table('hr_memo_opens')->where('user_id', $doctor->id)->count());
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'hr.memos.opened')->where('actor_user_id', $doctor->id)->count());
        $this->acknowledge($doctor, $memo->public_id)->assertSessionHasNoErrors();
        $this->acknowledge($doctor, $memo->public_id)->assertSessionHasErrors(['memo' => 'You already read this memo.']);
        $this->assertSame(1, DB::table('hr_memo_acknowledgements')->count());
        $this->get(route('hr.memos.pdf', $memo->public_id))->assertNotFound();

        // A memo with a PDF needs the PDF opened too.
        $this->publish($hr, ['roles' => ['ca'], 'title' => 'Synthetic memo with a PDF'], UploadedFile::fake()->createWithContent('memo.pdf', self::PDF))->assertSessionHasNoErrors();
        $withPdf = DB::table('hr_memos')->where('title', 'Synthetic memo with a PDF')->first();
        $this->assertNotNull($withPdf);
        $stored = Storage::disk(SopService::DISK)->get((string) $withPdf->pdf_path);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('SYNTHETIC-MEMO-PDF', $stored);
        $this->assertStringNotContainsString('memo.pdf', (string) $withPdf->pdf_path);
        $this->selectBranch($ca);
        $this->postJson(route('hr.memos.open', $withPdf->public_id))->assertOk()->assertJson(['hasPdf' => true]);
        $this->acknowledge($ca, $withPdf->public_id)->assertSessionHasErrors(['memo' => 'Open the PDF first.']);
        // PostgreSQL agrees: the memo alone is not enough when there is a PDF.
        $this->assertRefused('23514', fn () => DB::table('hr_memo_acknowledgements')->insert(['organisation_id' => $ca->organisation_id, 'memo_id' => $withPdf->id, 'user_id' => $ca->id, 'created_at' => now()]));
        $pdf = $this->get(route('hr.memos.pdf', $withPdf->public_id))->assertOk();
        $this->assertSame(self::PDF, $pdf->getContent());
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $pdf->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $pdf->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $pdf->headers->get('Cache-Control'));
        $this->acknowledge($ca, $withPdf->public_id)->assertSessionHasNoErrors();

        // The manager sees the counts, who has not read, and opens any memo (recorded).
        $this->selectBranch($hr);
        $this->get(route('hr.memos.index', ['tab' => 'manage']))->assertInertia(fn (Assert $page) => $page
            ->where('memos.manage.memos.1.read', 1)->where('memos.manage.memos.1.expected', 2)
            ->where('memos.manage.memos.1.audienceLabel', 'CA, Resident Doctor')
            ->has('memos.manage.memos.1.notYet', 1)->where('memos.manage.memos.1.notYet.0.name', $ca->name)
            ->where('memos.manage.memos.1.notYet.0.role', 'CA')->where('memos.manage.memos.1.notYet.0.publishedOn', '10 Nov 2026'));
        $this->postJson(route('hr.memos.open', $memo->public_id))->assertOk();
        // A person who joins later is expected for a memo that is still open.
        $late = $this->actor('ca');
        $this->selectBranch($hr);
        $this->get(route('hr.memos.index', ['tab' => 'manage']))->assertInertia(fn (Assert $page) => $page->where('memos.manage.memos.1.expected', 3));
        // My HR details says how many are still to read.
        $this->selectBranch($late);
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('memosToRead', 2));

        // Audit holds ids, counts and codes only, never the title, text or file name.
        $audit = (string) json_encode(DB::table('audit_logs')->where('event', 'like', 'hr.memos.%')->pluck('metadata')->all());
        $this->assertStringNotContainsString('closing', $audit);
        $this->assertStringNotContainsString('SYNTHETIC', $audit);
        $this->assertStringNotContainsString('memo.pdf', $audit);
    }

    public function test_a_person_outside_the_audience_cannot_open_or_read_it(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $caOther = $this->actor('ca', $this->other);
        $this->publish($hr, ['branches' => [(string) $this->other->id], 'roles' => ['ca']])->assertSessionHasNoErrors();
        $memo = DB::table('hr_memos')->first();
        $this->assertNotNull($memo);

        $this->selectBranch($ca);
        $this->get(route('hr.memos.index'))->assertInertia(fn (Assert $page) => $page->where('memos.my', []));
        $this->postJson(route('hr.memos.open', $memo->public_id))->assertForbidden();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'authorization.denied')->where('actor_user_id', $ca->id)->count());
        // The service refuses a read by someone outside the audience, even with opens on record.
        DB::table('hr_memo_opens')->insert(['organisation_id' => $ca->organisation_id, 'memo_id' => $memo->id, 'user_id' => $ca->id, 'kind' => 'memo', 'created_at' => now()]);
        $this->acknowledge($ca, $memo->public_id)->assertSessionHasErrors(['memo' => 'This memo is not for you.']);
        $this->assertSame(0, DB::table('hr_memo_acknowledgements')->count());

        $this->selectBranch($caOther);
        $this->postJson(route('hr.memos.open', $memo->public_id))->assertOk();
        $this->acknowledge($caOther, $memo->public_id)->assertSessionHasNoErrors();
        // A manager outside the audience may still open it.
        $this->selectBranch($hr);
        $this->postJson(route('hr.memos.open', $memo->public_id))->assertOk();
    }

    public function test_open_until_closes_the_memo_at_branch_local_midnight(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        // Not before today, and not more than a year ahead; a real date only.
        $this->publish($hr, ['open_until' => '2026-11-09'])->assertSessionHasErrors('open_until');
        $this->publish($hr, ['open_until' => '2027-11-11'])->assertSessionHasErrors('open_until');
        $this->publish($hr, ['open_until' => '2026-02-30'])->assertSessionHasErrors('open_until');
        $this->assertSame(0, DB::table('hr_memos')->count());
        $this->publish($hr, ['roles' => ['ca'], 'open_until' => '2026-11-10'])->assertSessionHasNoErrors();
        $memo = DB::table('hr_memos')->first();
        $this->assertNotNull($memo);
        // Stored once, in UTC: the end of 10 Nov in Kuala Lumpur is 16:00 UTC.
        $this->assertSame('2026-11-10 16:00:00', CarbonImmutable::parse((string) $memo->expires_at, 'UTC')->format('Y-m-d H:i:s'));

        // Just before the end of the day it is open and can be opened; the viewer sees the date in the branch's time.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:59:00', 'UTC'));
        $this->selectBranch($ca);
        $this->get(route('hr.memos.index'))->assertInertia(fn (Assert $page) => $page->where('memos.my.0.status', 'open')->where('memos.my.0.openUntil', '10 Nov 2026'));
        $this->postJson(route('hr.memos.open', $memo->public_id))->assertOk();
        // At 16:00 UTC it is 00:00 on 11 Nov in Kuala Lumpur: closed, no new reads, but still listed.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 16:00:00', 'UTC'));
        $this->acknowledge($ca, $memo->public_id)->assertSessionHasErrors(['memo' => 'This memo is closed.']);
        $this->get(route('hr.memos.index'))->assertInertia(fn (Assert $page) => $page->where('memos.my.0.status', 'closed'));
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('memosToRead', 0));
        $this->assertSame(0, DB::table('hr_memo_acknowledgements')->count());
        // PostgreSQL refuses it too once the real clock is past expiry.
        DB::table('hr_memos')->where('id', $memo->id)->update(['updated_at' => now()]);
        $past = DB::table('hr_memos')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'title' => 'Synthetic past memo',
            'body' => Crypt::encryptString('x'), 'published_by_user_id' => $hr->id, 'published_at' => '2020-01-01 00:00:00+00', 'expires_at' => '2020-01-02 00:00:00+00',
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_memo_opens')->insert(['organisation_id' => $ca->organisation_id, 'memo_id' => $past, 'user_id' => $ca->id, 'kind' => 'memo', 'created_at' => now()]);
        $this->assertRefused('23514', fn () => DB::table('hr_memo_acknowledgements')->insert(['organisation_id' => $ca->organisation_id, 'memo_id' => $past, 'user_id' => $ca->id, 'created_at' => now()]));
    }

    public function test_a_replaced_memo_takes_no_new_read_and_points_to_the_new_one(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $reader = $this->actor('ca');
        $this->publish($hr, ['roles' => ['ca'], 'title' => 'Synthetic first memo'])->assertSessionHasNoErrors();
        $old = DB::table('hr_memos')->first();
        $this->assertNotNull($old);
        $this->selectBranch($ca);
        $this->postJson(route('hr.memos.open', $old->public_id))->assertOk();
        $this->selectBranch($reader);
        $this->postJson(route('hr.memos.open', $old->public_id))->assertOk();
        $this->acknowledge($reader, $old->public_id)->assertSessionHasNoErrors();

        // Unknown, foreign or malformed targets are refused; a real one replaces.
        $this->publish($hr, ['roles' => ['ca'], 'replaces' => 'not-a-uuid'])->assertSessionHasErrors('replaces');
        $this->publish($hr, ['roles' => ['ca'], 'replaces' => (string) Str::uuid()])->assertSessionHasErrors('replaces');
        $this->publish($hr, ['roles' => ['ca'], 'title' => 'Synthetic corrected memo', 'replaces' => $old->public_id])->assertSessionHasNoErrors();
        $new = DB::table('hr_memos')->where('title', 'Synthetic corrected memo')->first();
        $this->assertNotNull($new);
        $this->assertSame((int) $old->id, (int) $new->replaces_memo_id);
        // Only once.
        $this->publish($hr, ['roles' => ['ca'], 'title' => 'Synthetic second correction', 'replaces' => $old->public_id])->assertSessionHasErrors(['replaces' => 'This memo was already replaced.']);

        // The old memo takes no new read from the person who opened it but had not read it.
        $this->selectBranch($ca);
        $this->acknowledge($ca, $old->public_id)->assertSessionHasErrors(['memo' => 'This memo was replaced by a newer memo. Read the newer one.']);
        $this->assertRefused('23514', fn () => DB::table('hr_memo_acknowledgements')->insert(['organisation_id' => $ca->organisation_id, 'memo_id' => $old->id, 'user_id' => $ca->id, 'created_at' => now()]));
        $this->get(route('hr.memos.index'))->assertInertia(fn (Assert $page) => $page->where('memos.my.0.title', 'Synthetic corrected memo')->where('memos.my.0.status', 'open')
            ->where('memos.my.1.status', 'replaced')->where('memos.my.1.replacedBy', $new->public_id));
        // The earlier read stays; the new memo is for every CA to read, and only it counts under My HR details.
        $this->assertSame(1, DB::table('hr_memo_acknowledgements')->where('memo_id', $old->id)->count());
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('memosToRead', 1));
        $this->selectBranch($hr);
        $this->get(route('hr.memos.index', ['tab' => 'manage']))->assertInertia(fn (Assert $page) => $page
            ->where('memos.manage.memos.1.status', 'replaced')->where('memos.manage.memos.1.replacedByTitle', 'Synthetic corrected memo')
            ->where('memos.manage.memos.1.read', 1)->has('memos.manage.memos.1.notYet', 1)
            ->where('memos.manage.memos.0.replacesTitle', 'Synthetic first memo'));
        // PostgreSQL: a replacement must point at an earlier memo that is not withdrawn.
        $this->assertRefused('23514', fn () => DB::table('hr_memos')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id, 'title' => 'Synthetic loop',
            'body' => Crypt::encryptString('x'), 'published_by_user_id' => $hr->id, 'published_at' => now(), 'replaces_memo_id' => $new->id + 1000, 'lock_version' => 1,
            'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_an_old_open_memo_stays_on_the_readers_list_behind_many_newer_memos_for_others(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->publish($hr, ['roles' => ['ca'], 'title' => 'Synthetic old memo for CAs'])->assertSessionHasNoErrors();
        $rows = [];
        for ($i = 0; $i < 105; $i++) {
            $rows[] = ['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id, 'title' => "Synthetic newer memo {$i}", 'body' => Crypt::encryptString('x'),
                'published_by_user_id' => $hr->id, 'published_at' => now()->addMinutes($i + 1), 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('hr_memos')->insert($rows);
        $memoIds = DB::table('hr_memos')->where('title', 'like', 'Synthetic newer memo %')->pluck('id');
        DB::table('hr_memo_roles')->insert($memoIds->map(fn ($id): array => ['memo_id' => $id, 'role' => 'panel_officer'])->all());
        $this->selectBranch($ca);
        $this->get(route('hr.memos.index'))->assertInertia(fn (Assert $page) => $page->has('memos.my', 1)->where('memos.my.0.title', 'Synthetic old memo for CAs'));
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('memosToRead', 1));
    }

    public function test_a_retry_of_a_replace_publish_returns_its_result_and_a_withdrawn_replacement_frees_the_old_memo(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->publish($hr, ['roles' => ['ca'], 'title' => 'Synthetic original memo'])->assertSessionHasNoErrors();
        $old = DB::table('hr_memos')->first();
        $this->assertNotNull($old);
        $key = (string) Str::uuid();
        $input = ['roles' => ['ca'], 'title' => 'Synthetic replacement memo', 'replaces' => $old->public_id, 'idempotency_key' => $key];
        $this->publish($hr, $input)->assertSessionHasNoErrors();
        $this->publish($hr, $input)->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('hr_memos')->count());
        $new = DB::table('hr_memos')->where('title', 'Synthetic replacement memo')->first();
        $this->assertNotNull($new);

        // Withdrawing the replacement frees the original: it takes reads again and can be replaced again.
        $this->selectBranch($hr);
        $this->withdraw($new, ['reason' => 'wrong_content'])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->postJson(route('hr.memos.open', $old->public_id))->assertOk();
        $this->acknowledge($ca, $old->public_id)->assertSessionHasNoErrors();
        $this->publish($hr, ['roles' => ['ca'], 'title' => 'Synthetic second replacement', 'replaces' => $old->public_id])->assertSessionHasNoErrors();
    }

    public function test_withdrawal_keeps_every_read_and_postgresql_refuses_changes(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->publish($hr, ['roles' => ['ca']])->assertSessionHasNoErrors();
        $memo = DB::table('hr_memos')->first();
        $this->assertNotNull($memo);

        // The database refuses a read without an open.
        $this->assertRefused('23514', fn () => DB::table('hr_memo_acknowledgements')->insert(['organisation_id' => $ca->organisation_id, 'memo_id' => $memo->id, 'user_id' => $ca->id, 'created_at' => now()]));
        $this->selectBranch($ca);
        $this->postJson(route('hr.memos.open', $memo->public_id))->assertOk();
        $this->acknowledge($ca, $memo->public_id)->assertSessionHasNoErrors();
        $ack = DB::table('hr_memo_acknowledgements')->first();
        $open = DB::table('hr_memo_opens')->first();
        $this->assertNotNull($ack);
        $this->assertNotNull($open);

        $this->assertRefused('P0001', fn () => DB::table('hr_memo_acknowledgements')->where('id', $ack->id)->update(['created_at' => now()->subDay()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_memo_acknowledgements')->where('id', $ack->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_memo_opens')->where('id', $open->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_memo_roles')->delete());
        $this->assertRefused('P0001', fn () => DB::statement('TRUNCATE hr_memo_acknowledgements'));
        $this->assertRefused('P0001', fn () => DB::statement('TRUNCATE hr_memos CASCADE'));
        $this->assertRefused('P0001', fn () => DB::table('hr_memos')->where('id', $memo->id)->update(['title' => 'Changed title']));
        $this->assertRefused('P0001', fn () => DB::table('hr_memos')->where('id', $memo->id)->update(['expires_at' => now()->addYear()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_memos')->where('id', $memo->id)->delete());

        // Withdraw: a reason from the list, the current lock version, once.
        $this->selectBranch($hr);
        $this->withdraw($memo, ['reason' => 'because'])->assertSessionHasErrors('reason');
        $this->withdraw($memo, ['reason' => 'wrong_content', 'expected_lock_version' => $memo->lock_version + 4])->assertSessionHasErrors('records');
        $this->withdraw($memo, ['reason' => 'wrong_content'])->assertSessionHasNoErrors();
        $this->withdraw($memo, ['reason' => 'wrong_content', 'expected_lock_version' => $memo->lock_version + 1])->assertSessionHasErrors(['memo' => 'This memo was already withdrawn. Reload the page.']);
        $this->assertSame(1, DB::table('hr_memo_acknowledgements')->count());
        $this->get(route('hr.memos.index', ['tab' => 'manage']))->assertInertia(fn (Assert $page) => $page->where('memos.manage.memos.0.status', 'withdrawn')
            ->where('memos.manage.memos.0.withdrawnOn', '10 Nov 2026')->where('memos.manage.memos.0.withdrawnReason', 'Wrong content')->where('memos.manage.memos.0.notYet', []));
        $this->assertRefused('P0001', fn () => DB::table('hr_memos')->where('id', $memo->id)->update(['withdrawn_at' => null, 'withdrawn_by_user_id' => null, 'withdrawn_reason' => null]));
        // A withdrawn memo cannot be replaced, opened by a reader, or read; PostgreSQL agrees.
        $this->publish($hr, ['roles' => ['ca'], 'replaces' => $memo->public_id])->assertSessionHasErrors(['replaces' => 'A withdrawn memo cannot be replaced.']);
        $other = $this->actor('ca');
        $this->selectBranch($other);
        $this->postJson(route('hr.memos.open', $memo->public_id))->assertStatus(422);
        $this->acknowledge($other, $memo->public_id)->assertSessionHasErrors(['memo' => 'This memo was withdrawn. Reload the page.']);
        DB::table('hr_memo_opens')->insert(['organisation_id' => $other->organisation_id, 'memo_id' => $memo->id, 'user_id' => $other->id, 'kind' => 'memo', 'created_at' => now()]);
        $this->assertRefused('23514', fn () => DB::table('hr_memo_acknowledgements')->insert(['organisation_id' => $other->organisation_id, 'memo_id' => $memo->id, 'user_id' => $other->id, 'created_at' => now()]));
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('memosToRead', 0));
    }

    public function test_another_organisations_memos_are_not_found(): void
    {
        $org = new Organisation;
        $org->forceFill(['code' => 'HR_OTHER_'.Str::upper(Str::random(6)), 'name' => 'Synthetic Other Organisation', 'is_active' => true])->save();
        $user = new User;
        $user->forceFill(['organisation_id' => $org->id, 'name' => 'Synthetic Other User', 'email' => 'other.'.Str::lower(Str::random(8)).'@kpone.test', 'is_active' => true])->save();
        $public = (string) Str::uuid();
        DB::table('hr_memos')->insert(['public_id' => $public, 'organisation_id' => $org->id, 'title' => 'Other memo', 'body' => Crypt::encryptString('x'),
            'published_by_user_id' => $user->id, 'published_at' => now(), 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->postJson(route('hr.memos.open', $public))->assertNotFound();
        $this->get(route('hr.memos.pdf', $public))->assertNotFound();
        $this->post(route('hr.memos.acknowledge', $public), ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post(route('hr.memos.withdraw', $public), ['reason' => 'other', 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->get(route('hr.memos.index', ['tab' => 'manage']))->assertInertia(fn (Assert $page) => $page->where('memos.manage.memos', []));
        $this->get(route('hr.memos.index'))->assertInertia(fn (Assert $page) => $page->where('memos.my', []));
    }

    /** @param array<string, mixed> $input */
    private function publish(User $actor, array $input, ?UploadedFile $file = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.memos.publish'), ['title' => self::TITLE, 'body' => self::BODY, 'idempotency_key' => (string) Str::uuid(), ...$input,
            ...($file === null ? [] : ['file' => $file])]);
    }

    /** @param array<string, mixed> $input */
    private function withdraw(object $memo, array $input): TestResponse
    {
        return $this->post(route('hr.memos.withdraw', $memo->public_id), ['expected_lock_version' => $memo->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    private function acknowledge(User $actor, string $memoId): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.memos.acknowledge', $memoId), ['idempotency_key' => (string) Str::uuid()]);
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
