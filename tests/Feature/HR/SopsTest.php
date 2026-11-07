<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Sops\Services\SopService;
use App\Domain\HR\Sops\SopPermissions;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
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
 * HR-5c SOPs (owner, 2026-11-06: "approve the HR-5 note with the defaults"; HR-PLAN 12.3 rule 8). Synthetic data only.
 */
class SopsTest extends VisitTestCase
{
    private const PDF_V1 = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-SOP-ONE\n%%EOF\n";

    private const PDF_V2 = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-SOP-TWO\n%%EOF\n";

    private const TITLE = 'Synthetic hand hygiene SOP';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(SopService::DISK);
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00', $this->branch->timezone));
    }

    public function test_grants_are_exact_the_tv_account_gets_nothing_and_neither_is_authority(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders(SopPermissions::MANAGE));
        $own = $holders(SopPermissions::ACKNOWLEDGE_OWN);
        $this->assertCount(11, $own);
        $this->assertNotContains('queue_display', $own);
        foreach (array_keys(SopPermissions::grants()) as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
        }
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.sops.index'))->assertForbidden();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.sops.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('sops.tabs', ['my'])->where('sops.manage', null)->where('sops.my', []));
        $this->publish($ca, ['roles' => ['ca']])->assertForbidden();
        $this->assertSame(0, DB::table('hr_sops')->count());
    }

    public function test_an_sop_is_opened_before_it_is_acknowledged_and_a_new_version_needs_a_new_acknowledgement(): void
    {
        $hr = $this->actor('hr_manager');
        $doctor = $this->actor('resident_doctor');
        $ca = $this->actor('ca');
        $panel = $this->actor('panel_officer');

        // Only a PDF, with a title and at least one role.
        $this->publish($hr, ['roles' => ['ca']], UploadedFile::fake()->createWithContent('photo.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"))->assertSessionHasErrors(['file' => 'Use a PDF file.']);
        $this->publish($hr, ['roles' => []])->assertSessionHasErrors('roles');
        $this->selectBranch($hr);
        $this->post(route('hr.sops.publish'), ['title' => self::TITLE, 'roles' => ['ca'], 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['file' => 'Use a PDF file.']);
        // One submit names everything to fix.
        $this->post(route('hr.sops.publish'), ['title' => '', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['file', 'title', 'roles']);
        $this->publish($hr, ['roles' => ['queue_display']])->assertSessionHasErrors('roles');
        $this->publish($hr, ['roles' => [['ca']]])->assertSessionHasErrors('roles');
        $this->publish($hr, ['title' => 'ab', 'roles' => ['ca']])->assertSessionHasErrors('title');
        $this->assertSame(0, DB::table('hr_sops')->count());
        $this->assertSame([], Storage::disk(SopService::DISK)->allFiles());

        // A retry with the same key publishes once.
        $key = (string) Str::uuid();
        $this->publish($hr, ['roles' => ['resident_doctor', 'ca'], 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->publish($hr, ['roles' => ['resident_doctor', 'ca'], 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('hr_sops')->count());
        $v1 = DB::table('hr_sop_versions')->first();
        $this->assertNotNull($v1);
        // Encrypted on the private disk under a generated name.
        $stored = Storage::disk(SopService::DISK)->get($v1->stored_path);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('SYNTHETIC-SOP-ONE', $stored);
        $this->assertStringNotContainsString('hand', $v1->stored_path);

        // The doctor sees it, cannot acknowledge before opening, opens it (recorded first) and acknowledges once.
        $this->selectBranch($doctor);
        $this->get(route('hr.sops.index'))->assertInertia(fn (Assert $page) => $page->has('sops.my', 1)
            ->where('sops.my.0.title', self::TITLE)->where('sops.my.0.version', 1)->where('sops.my.0.opened', false)
            ->where('sops.my.0.acknowledgedOn', null)->where('sops.my.0.publishedOn', '6 Nov 2026'));
        $this->acknowledge($doctor, $v1->public_id)->assertSessionHasErrors(['sop' => 'Open the SOP first.']);
        $opened = $this->get(route('hr.sops.open', $v1->public_id))->assertOk();
        $this->assertSame(self::PDF_V1, $opened->getContent());
        $this->assertSame('application/pdf', $opened->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $opened->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $opened->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $opened->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $opened->headers->get('Cache-Control'));
        $this->assertSame(1, DB::table('hr_sop_opens')->where('user_id', $doctor->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.sops.opened')->where('actor_user_id', $doctor->id)->count());
        $this->acknowledge($doctor, $v1->public_id)->assertSessionHasNoErrors();
        $this->acknowledge($doctor, $v1->public_id)->assertSessionHasErrors(['sop' => 'You already acknowledged this version.']);
        $this->assertSame(1, DB::table('hr_sop_acknowledgements')->count());

        // Not for the Panel Officer's role: no open, no acknowledgement, and nothing on their list.
        $this->selectBranch($panel);
        $this->get(route('hr.sops.index'))->assertInertia(fn (Assert $page) => $page->where('sops.my', []));
        $this->get(route('hr.sops.open', $v1->public_id))->assertForbidden();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'authorization.denied')->where('actor_user_id', $panel->id)->count());
        $this->acknowledge($panel, $v1->public_id)->assertSessionHasErrors(['sop' => 'This SOP is not published to your role.']);
        // PostgreSQL refuses a reader outside the audience too, even with an open on record.
        DB::table('hr_sop_opens')->insert(['organisation_id' => $panel->organisation_id, 'sop_version_id' => $v1->id, 'user_id' => $panel->id, 'created_at' => now()]);
        $this->assertRefused('23514', fn () => DB::table('hr_sop_acknowledgements')->insert(['organisation_id' => $panel->organisation_id, 'sop_version_id' => $v1->id,
            'user_id' => $panel->id, 'created_at' => now()]));

        // The manager sees 1 of 2 and who has not, as plain text.
        $this->selectBranch($hr);
        $this->get(route('hr.sops.index', ['tab' => 'manage']))->assertInertia(fn (Assert $page) => $page
            ->where('sops.manage.sops.0.acknowledged', 1)->where('sops.manage.sops.0.audience', 2)
            ->where('sops.manage.sops.0.roles', ['CA', 'Resident Doctor'])
            ->has('sops.manage.sops.0.notYet', 1)->where('sops.manage.sops.0.notYet.0.name', $ca->name)
            ->where('sops.manage.sops.0.notYet.0.role', 'CA')->where('sops.manage.sops.0.notYet.0.publishedOn', '6 Nov 2026'));
        // My HR details says how many are still to read.
        $this->selectBranch($ca);
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('sopsToRead', 1));

        // A new version: the same file is refused; a new file asks everyone again and keeps the earlier acknowledgement.
        $sop = DB::table('hr_sops')->first();
        $this->assertNotNull($sop);
        $this->replace($hr, $sop->public_id, (int) $sop->lock_version, self::PDF_V1)->assertSessionHasErrors(['file' => 'This is the same file as the current version. Choose the new file.']);
        $this->replace($hr, $sop->public_id, (int) $sop->lock_version + 5, self::PDF_V2)->assertSessionHasErrors('records');
        $this->replace($hr, $sop->public_id, (int) $sop->lock_version, self::PDF_V2)->assertSessionHasNoErrors();
        $v2 = DB::table('hr_sop_versions')->where('version', 2)->first();
        $this->assertNotNull($v2);
        $this->selectBranch($doctor);
        $this->get(route('hr.sops.index'))->assertInertia(fn (Assert $page) => $page->where('sops.my.0.version', 2)
            ->where('sops.my.0.acknowledgedOn', null)->where('sops.my.0.earlier', ['version' => 1, 'on' => '6 Nov 2026']));
        $this->get(route('hr.sops.open', $v1->public_id))->assertRedirect(route('hr.sops.index'));
        $this->acknowledge($doctor, $v2->public_id)->assertSessionHasErrors(['sop' => 'Open the SOP first.']);
        $this->get(route('hr.sops.open', $v2->public_id))->assertOk();
        $this->acknowledge($doctor, $v2->public_id)->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('hr_sop_acknowledgements')->where('user_id', $doctor->id)->count());
        // A manager opens any version.
        $this->selectBranch($hr);
        $this->assertSame(self::PDF_V1, $this->get(route('hr.sops.open', $v1->public_id))->assertOk()->getContent());

        // Audit holds ids and versions only, never the title or the file.
        $audit = (string) json_encode(DB::table('audit_logs')->where('event', 'like', 'hr.sops.%')->pluck('metadata')->all());
        $this->assertStringNotContainsString('hygiene', $audit);
        $this->assertStringNotContainsString('SYNTHETIC', $audit);
        $this->assertStringNotContainsString('sop.pdf', $audit);
    }

    public function test_withdrawal_keeps_every_acknowledgement_and_postgresql_refuses_changes(): void
    {
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $this->publish($director, ['roles' => ['ca']])->assertSessionHasNoErrors();
        $sop = DB::table('hr_sops')->first();
        $version = DB::table('hr_sop_versions')->first();
        $this->assertNotNull($sop);
        $this->assertNotNull($version);

        // The database refuses an acknowledgement without an open, and one for a version that is not current.
        $this->assertRefused('23514', fn () => DB::table('hr_sop_acknowledgements')->insert(['organisation_id' => $ca->organisation_id, 'sop_version_id' => $version->id,
            'user_id' => $ca->id, 'created_at' => now()]));
        $this->selectBranch($ca);
        $this->get(route('hr.sops.open', $version->public_id))->assertOk();
        $this->acknowledge($ca, $version->public_id)->assertSessionHasNoErrors();
        $ack = DB::table('hr_sop_acknowledgements')->first();
        $this->assertNotNull($ack);

        $this->assertRefused('P0001', fn () => DB::table('hr_sop_acknowledgements')->where('id', $ack->id)->update(['created_at' => now()->subDay()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_sop_acknowledgements')->where('id', $ack->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_sop_versions')->where('id', $version->id)->update(['title' => 'Changed title']));
        $this->assertRefused('P0001', fn () => DB::table('hr_sop_opens')->delete());
        $this->assertRefused('P0001', fn () => DB::statement('TRUNCATE hr_sop_acknowledgements'));
        $this->assertRefused('P0001', fn () => DB::table('hr_sops')->where('id', $sop->id)->update(['current_version' => 2]));
        $this->assertRefused('23514', fn () => DB::table('hr_sop_versions')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $sop->organisation_id,
            'sop_id' => $sop->id, 'version' => 3, 'title' => 'Gap version', 'bytes' => 10, 'sha256' => str_repeat('a', 64), 'stored_path' => 'x',
            'published_by_user_id' => $director->id, 'created_at' => now()]));

        // Withdraw: it leaves SOPs to read, refuses acknowledgements and new versions, and keeps every acknowledgement.
        $this->selectBranch($director);
        $this->post(route('hr.sops.withdraw', $sop->public_id), ['expected_lock_version' => $sop->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('hr_sop_acknowledgements')->count());
        $this->get(route('hr.sops.index', ['tab' => 'manage']))->assertInertia(fn (Assert $page) => $page->where('sops.manage.sops.0.isActive', false)
            ->where('sops.manage.sops.0.withdrawnOn', '6 Nov 2026')->where('sops.manage.sops.0.notYet', []));
        $this->replace($director, $sop->public_id, (int) $sop->lock_version + 1, self::PDF_V2)->assertSessionHasErrors(['sop' => 'This SOP was withdrawn. Publish a new SOP instead.']);
        $this->assertRefused('P0001', fn () => DB::table('hr_sops')->where('id', $sop->id)->update(['is_active' => true, 'withdrawn_by_user_id' => null, 'withdrawn_at' => null]));
        $this->selectBranch($ca);
        $this->get(route('hr.sops.index'))->assertInertia(fn (Assert $page) => $page->where('sops.my', []));
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->where('sopsToRead', 0));
    }

    public function test_another_organisations_sops_are_not_found(): void
    {
        $org = new Organisation;
        $org->forceFill(['code' => 'HR_OTHER_'.Str::upper(Str::random(6)), 'name' => 'Synthetic Other Organisation', 'is_active' => true])->save();
        $user = new User;
        $user->forceFill(['organisation_id' => $org->id, 'name' => 'Synthetic Other User', 'email' => 'other.'.Str::lower(Str::random(8)).'@kpone.test', 'is_active' => true])->save();
        $sopId = DB::table('hr_sops')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $org->id, 'current_version' => 1, 'is_active' => true,
            'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $versionPublic = (string) Str::uuid();
        DB::table('hr_sop_versions')->insert(['public_id' => $versionPublic, 'organisation_id' => $org->id, 'sop_id' => $sopId, 'version' => 1, 'title' => 'Other SOP',
            'bytes' => 10, 'sha256' => str_repeat('b', 64), 'stored_path' => 'hr-sops/other.bin', 'published_by_user_id' => $user->id, 'created_at' => now()]);
        DB::table('hr_sop_audiences')->insert(['sop_version_id' => DB::table('hr_sop_versions')->where('public_id', $versionPublic)->value('id'), 'role' => 'director']);
        $sopPublic = (string) DB::table('hr_sops')->where('id', $sopId)->value('public_id');

        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->get(route('hr.sops.open', $versionPublic))->assertNotFound();
        $this->post(route('hr.sops.acknowledge', $versionPublic), ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post(route('hr.sops.withdraw', $sopPublic), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->get(route('hr.sops.index', ['tab' => 'manage']))->assertInertia(fn (Assert $page) => $page->where('sops.manage.sops', []));
        $this->get(route('hr.sops.index'))->assertInertia(fn (Assert $page) => $page->where('sops.my', []));
    }

    /** @param array<string, mixed> $input */
    private function publish(User $actor, array $input, ?UploadedFile $file = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.sops.publish'), ['title' => self::TITLE, 'idempotency_key' => (string) Str::uuid(), ...$input,
            'file' => $file ?? UploadedFile::fake()->createWithContent('sop.pdf', self::PDF_V1)]);
    }

    private function replace(User $actor, string $sopId, int $lockVersion, string $pdf): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.sops.replace', $sopId), ['title' => self::TITLE.' (2)', 'roles' => ['ca', 'resident_doctor'], 'expected_lock_version' => $lockVersion,
            'idempotency_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('sop.pdf', $pdf)]);
    }

    private function acknowledge(User $actor, string $versionId): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.sops.acknowledge', $versionId), ['idempotency_key' => (string) Str::uuid()]);
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
