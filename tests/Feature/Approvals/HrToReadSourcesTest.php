<?php

namespace Tests\Feature\Approvals;

use App\Domain\Approvals\ApprovalSource;
use App\Domain\Approvals\ApprovalSources;
use App\Domain\Approvals\Sources\MemoToReadSource;
use App\Domain\Approvals\Sources\SopToReadSource;
use App\Domain\HR\Memos\Services\MemosPageService;
use App\Domain\HR\Sops\Services\SopService;
use App\Domain\HR\Sops\Services\SopsPageService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;

/**
 * APPR-1d step 0 contract: the two HR "to read" task sources count exactly what the SOPs and memos pages count as still to
 * read, because both use the same rule (SopsToRead, MemosToRead). Tasks never grant the number.
 */
final class HrToReadSourcesTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-SOP\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(SopService::DISK);
        $this->travelTo(CarbonImmutable::parse('2026-11-22 09:00', $this->branch->timezone));
    }

    public function test_sops_to_read_counts_what_the_sops_page_counts(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $doctor = $this->actor('resident_doctor');
        $this->publishSop($hr, ['ca']);
        $this->travel(1)->hours();
        $this->publishSop($hr, ['ca', 'resident_doctor']);
        $source = app(SopToReadSource::class);
        $page = app(SopsPageService::class);

        $this->assertSame([2, 2], [$page->toRead($ca), $source->count($ca, null)]);
        $this->assertSame([1, 1], [$page->toRead($doctor), $source->count($doctor, null)]);
        $this->assertSame('2026-11-22 01:00:00', $source->oldest($ca, null)?->utc()->format('Y-m-d H:i:s'));

        $first = DB::table('hr_sop_versions')->orderBy('id')->firstOrFail();
        $this->selectBranch($ca);
        $this->get(route('hr.sops.open', $first->public_id))->assertOk();
        $this->post(route('hr.sops.acknowledge', $first->public_id), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->assertSame([1, 1], [$page->toRead($ca), $source->count($ca, null)]);
        $this->assertSame('2026-11-22 02:00:00', $source->oldest($ca, null)?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame([], $source->items($ca, null, 5), 'a task carries no title');
    }

    public function test_memos_to_read_counts_what_the_memos_page_counts(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $doctor = $this->actor('resident_doctor');
        $this->publishMemo($hr, ['ca']);
        $this->publishMemo($hr, ['ca', 'resident_doctor']);
        $source = app(MemoToReadSource::class);
        $page = app(MemosPageService::class);

        $this->assertSame([2, 2], [$page->toRead($ca), $source->count($ca, null)]);
        $this->assertSame([1, 1], [$page->toRead($doctor), $source->count($doctor, null)]);

        $memo = DB::table('hr_memos')->orderBy('id')->firstOrFail();
        $this->selectBranch($ca);
        $this->postJson(route('hr.memos.open', $memo->public_id))->assertOk();
        $this->post(route('hr.memos.acknowledge', $memo->public_id), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->assertSame([1, 1], [$page->toRead($ca), $source->count($ca, null)]);
        $this->assertNotNull($source->oldest($ca, null));
        $this->assertSame([], $source->items($ca, null, 5));
    }

    public function test_tasks_never_grant_the_number_and_the_tv_account_gets_no_task(): void
    {
        $sources = app(ApprovalSources::class);
        foreach ([SopToReadSource::class, MemoToReadSource::class] as $class) {
            $this->assertSame(ApprovalSource::TASK, app($class)->kind());
            $this->assertSame('HR', app($class)->area());
            $this->assertFalse(app($class)->branchScoped());
            foreach (app($class)->permissions() as $permission) {
                $this->assertNotContains($permission, $sources->approvalPermissions());
            }
        }
        $tv = $this->actor('queue_display');
        $this->selectBranch($tv);
        $this->assertSame([], $sources->visibleTo($tv, $this->branch));
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($doctor);
        $this->actingAs($doctor)->get(route('approvals.index'))->assertForbidden();
    }

    /** @param list<string> $roles */
    private function publishSop(User $hr, array $roles): void
    {
        $this->selectBranch($hr);
        $this->post(route('hr.sops.publish'), ['title' => 'Synthetic SOP '.Str::random(4), 'roles' => $roles, 'idempotency_key' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->createWithContent('sop.pdf', self::PDF)])->assertSessionHasNoErrors();
    }

    /** @param list<string> $roles */
    private function publishMemo(User $hr, array $roles): void
    {
        $this->selectBranch($hr);
        $this->post(route('hr.memos.publish'), ['title' => 'Synthetic memo '.Str::random(4), 'body' => 'Synthetic memo body for testing.', 'roles' => $roles,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }
}
