<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Records\Models\Document;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\HR\Records\Services\DocumentService;
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
use RuntimeException;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-4b documents and checklists (owner, 2026-11-06; HR-PLAN 11.3 rules 10 and 11). Synthetic files only. The clock
 * is frozen in Kuala Lumpur so every date is branch-local.
 */
class DocumentsTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-CONTRACT-MARKER\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentService::DISK);
        $this->travelTo(CarbonImmutable::parse('2027-03-08 09:00', $this->branch->timezone));
    }

    public function test_the_documents_permission_is_exact_and_not_authority(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array(RecordPermissions::DOCUMENTS_VIEW, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(RecordPermissions::DOCUMENTS_VIEW));
        $hr = $this->actor('hr_manager');
        $worker = $this->workerFor($hr, $this->actor('ca'));
        $this->selectBranch($this->actor('ca'));
        $this->post(route('hr.records.documents.store', $worker->public_id), ['category' => 'contract', 'file' => $this->pdf(), 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->put(route('hr.records.retention'), ['category' => 'contract', 'retention_days' => 30, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.records.documents.show', [$worker->public_id, (string) Str::uuid()]))->assertForbidden();
    }

    public function test_a_document_is_checked_encrypted_unnamed_and_every_open_is_recorded_first(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $worker = $this->workerFor($hr, $ca);

        $this->upload($hr, $worker, ['category' => 'contract'], UploadedFile::fake()->createWithContent('contract.pdf', 'not a pdf'))->assertSessionHasErrors('file');
        $this->upload($hr, $worker, ['category' => 'nonsense'])->assertSessionHasErrors(['category' => 'Choose the document type.']);
        $this->upload($hr, $worker, ['category' => 'certificate', 'issued_on' => '2027-03-01', 'expires_on' => '2027-02-01'])
            ->assertSessionHasErrors(['expires_on' => 'Choose an expiry date on or after the issue date.']);
        $this->upload($hr, $worker, ['category' => 'certificate', 'issued_on' => '2026-03-01', 'expires_on' => '2027-03-20'])->assertSessionHasNoErrors();
        $this->upload($hr, $worker, ['category' => 'certificate'])->assertSessionHasErrors(['file' => 'This file is already in the staff file.']);
        $big = UploadedFile::fake()->createWithContent('big.pdf', str_repeat('x', DocumentService::MAX_BYTES + 1));
        $this->upload($hr, $worker, ['category' => 'other'], $big)->assertSessionHasErrors(['file' => 'This file is over 10 MB.']);

        $document = Document::query()->where('worker_id', $worker->id)->firstOrFail();
        $stored = Storage::disk(DocumentService::DISK)->get($document->stored_path);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('SYNTHETIC-CONTRACT-MARKER', $stored);
        $this->assertSame(self::PDF, Crypt::decryptString($stored));
        $this->assertStringNotContainsString('contract', $document->stored_path);
        $this->assertStringNotContainsString('certificate.pdf', (string) json_encode(DB::table('hr_documents')->get()->toArray()));

        // The page lists names and dates only, with the expiry in words (Daniel).
        $this->selectBranch($hr);
        $this->get(route('hr.records.show', [$worker->public_id, 'tab' => 'documents']))->assertInertia(fn (Assert $page) => $page
            ->where('file.documents.0.categoryLabel', 'Certificate')->where('file.documents.0.expiry', 'Expires in 12 days')->where('file.canOpenDocuments', true));

        // Every open is recorded before any byte is sent; staff open their own; nobody else's without the permission.
        $response = $this->get(route('hr.records.documents.show', [$worker->public_id, $document->public_id]));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(self::PDF, $response->getContent());
        $this->selectBranch($ca);
        $this->get(route('hr.records.documents.show', [$worker->public_id, $document->public_id]))->assertOk();
        $this->get(route('hr.records.index'))->assertInertia(fn (Assert $page) => $page->has('records.my.documents', 1)->where('records.my.workerId', $worker->public_id));
        $other = $this->workerFor($hr, $this->actor('ca'));
        $this->upload($hr, $other, ['category' => 'contract'], $this->pdf('SECOND'))->assertSessionHasNoErrors();
        $otherDoc = Document::query()->where('worker_id', $other->id)->firstOrFail();
        $this->selectBranch($ca);
        $this->get(route('hr.records.documents.show', [$other->public_id, $otherDoc->public_id]))->assertForbidden();
        $this->get(route('hr.records.documents.show', [$worker->public_id, $otherDoc->public_id]))->assertNotFound();
        $this->assertSame(2, DB::table('hr_document_events')->where('event', 'opened')->count()); // the two refusals record nothing
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'hr.records.document_opened')->count());

        // If the audit row cannot be written, nothing is sent.
        $this->selectBranch($hr);
        $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable')));
        $failed = $this->get(route('hr.records.documents.show', [$worker->public_id, $document->public_id]));
        $this->assertSame(500, $failed->getStatusCode());
        $this->assertStringNotContainsString('SYNTHETIC-CONTRACT-MARKER', (string) $failed->getContent());
    }

    public function test_a_removal_keeps_the_row_deletes_the_bytes_and_follows_rule_3(): void
    {
        $director = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $worker = $this->workerFor($hr, $this->actor('ca'));
        $directorWorker = $this->workerFor($this->actor('director'), $director);
        $this->upload($hr, $worker, ['category' => 'contract'])->assertSessionHasNoErrors();
        $this->upload($hr, $directorWorker, ['category' => 'contract'])->assertSessionHasErrors(['records' => "Only a Director may change a Director's staff file."]);
        $document = Document::query()->where('worker_id', $worker->id)->firstOrFail();

        $this->remove($hr, $worker, $document, null)->assertSessionHasErrors(['reason' => 'Choose why it is removed.']);
        $this->remove($hr, $worker, $document, 'wrong_file')->assertSessionHasNoErrors();
        $document->refresh();
        $this->assertSame(['removed', 'wrong_file', $hr->id], [$document->status, $document->removal_reason, $document->removed_by_user_id]);
        $this->assertFalse(Storage::disk(DocumentService::DISK)->exists($document->stored_path));
        $this->selectBranch($hr);
        $this->get(route('hr.records.documents.show', [$worker->public_id, $document->public_id]))->assertNotFound();
        $this->get(route('hr.records.show', [$worker->public_id, 'tab' => 'documents']))->assertInertia(fn (Assert $page) => $page->where('file.documents', []));

        // PostgreSQL keeps the document as uploaded and moves it once; nothing is deleted.
        $this->assertRefused('P0001', fn () => DB::table('hr_documents')->where('id', $document->id)->update(['status' => 'active', 'removed_at' => null, 'removal_reason' => null, 'removed_by_user_id' => null]));
        $this->assertRefused('P0001', fn () => DB::table('hr_documents')->where('id', $document->id)->update(['category' => 'other']));
        $this->assertRefused('P0001', fn () => DB::table('hr_documents')->where('id', $document->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_document_events')->delete());
        $this->assertNoValuesInAudit();
    }

    public function test_retention_is_clinic_entered_and_the_purge_counts_from_the_last_period_end(): void
    {
        $hr = $this->actor('hr_manager');
        $current = $this->workerFor($hr, $this->actor('ca'));
        $left = $this->workerFor($hr, $this->actor('ca'));
        $this->period($hr, $current, ['type' => 'permanent', 'starts_on' => '2020-01-01']);
        $this->period($hr, $left, ['type' => 'contract', 'starts_on' => '2020-01-01', 'ends_on' => '2026-12-31', 'end_reason' => 'contract_ended']);
        $this->upload($hr, $current, ['category' => 'contract'], $this->pdf('A'))->assertSessionHasNoErrors();
        $this->upload($hr, $left, ['category' => 'contract'], $this->pdf('B'))->assertSessionHasNoErrors();
        $this->upload($hr, $left, ['category' => 'certificate'], $this->pdf('C'))->assertSessionHasNoErrors();

        // KPOne ships no number: nothing is purged before HR enters one.
        $this->assertSame(0, app(DocumentService::class)->purgeExpired());
        $this->selectBranch($hr);
        $this->put(route('hr.records.retention'), ['category' => 'contract', 'retention_days' => 0, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('retention_days');
        $this->put(route('hr.records.retention'), ['category' => 'contract', 'retention_days' => 30, 'expected_lock_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->get(route('hr.records.index', ['tab' => 'files']))->assertInertia(fn (Assert $page) => $page->where('records.settings.retentions.0.days', 30)
            ->where('records.settings.retentions.1.days', null));

        $this->assertSame(1, app(DocumentService::class)->purgeExpired()); // the left worker's contract only (ended 67 days ago)
        $this->assertSame(['active'], Document::query()->where('worker_id', $current->id)->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['purged', 'active'], Document::query()->where('worker_id', $left->id)->pluck('status')->all());
        $this->assertSame(1, DB::table('hr_document_events')->where('event', 'purged')->whereNull('actor_user_id')->count());
        $this->assertCount(2, Storage::disk(DocumentService::DISK)->allFiles());
        $this->assertSame(0, app(DocumentService::class)->purgeExpired());
    }

    public function test_checklists_are_clinic_entered_ticked_once_and_never_change_an_account(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $worker = $this->workerFor($hr, $ca);
        $this->period($hr, $worker, ['type' => 'permanent', 'starts_on' => '2027-03-01']);
        $period = DB::table('hr_employment_periods')->where('worker_id', $worker->id)->first();
        $this->assertNotNull($period);
        $before = $this->accounts();

        $this->selectBranch($hr);
        $this->get(route('hr.records.show', [$worker->public_id, 'tab' => 'checklist']))->assertInertia(fn (Assert $page) => $page
            ->where('file.checklist.0.onboarding', [])->where('file.checklist.0.offboarding', null));
        $add = fn (array $input) => $this->post(route('hr.records.checklist-items.store'), [...$input, 'idempotency_key' => (string) Str::uuid()]);
        $add(['list' => 'onboarding', 'label' => 'x'])->assertSessionHasErrors('label');
        $add(['list' => 'onboarding', 'label' => 'Sign the contract'])->assertSessionHasNoErrors();
        $add(['list' => 'onboarding', 'label' => 'sign the contract'])->assertSessionHasErrors(['label' => 'This item is already on the list.']);
        $add(['list' => 'onboarding', 'label' => 'Create the KPOne account', 'about_account' => true])->assertSessionHasNoErrors();
        $add(['list' => 'offboarding', 'label' => 'Deactivate the KPOne account', 'about_account' => true])->assertSessionHasNoErrors();
        $items = DB::table('hr_checklist_items')->orderBy('id')->get();

        $tick = fn (object $item) => $this->post(route('hr.records.checklist.tick', [$worker->public_id, $period->public_id, $item->public_id]), ['idempotency_key' => (string) Str::uuid()]);
        $tick($items[0])->assertSessionHasNoErrors();
        $tick($items[0])->assertSessionHasErrors(['checklist' => 'This item was already ticked. Reload the page.']);
        $tick($items[1])->assertSessionHasNoErrors();
        $tick($items[2])->assertSessionHasErrors(['checklist' => 'Offboarding starts once the period has an end date.']);
        $this->get(route('hr.records.show', [$worker->public_id, 'tab' => 'checklist']))->assertInertia(fn (Assert $page) => $page
            ->where('file.checklist.0.onboarding.0.ticked.by', $hr->name)->where('file.checklist.0.onboarding.1.aboutAccount', true));

        // Archiving keeps an item where it was ticked; its wording never changes.
        $this->post(route('hr.records.checklist-items.archive', $items[0]->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->get(route('hr.records.show', [$worker->public_id, 'tab' => 'checklist']))->assertInertia(fn (Assert $page) => $page->has('file.checklist.0.onboarding', 2));
        $this->assertRefused('P0001', fn () => DB::table('hr_checklist_items')->where('id', $items[0]->id)->update(['label' => 'Other']));
        $this->assertRefused('P0001', fn () => DB::table('hr_checklist_ticks')->delete());
        $this->assertRefused('23514', fn () => DB::table('hr_checklist_ticks')->insert(['organisation_id' => $hr->organisation_id, 'period_id' => $period->id,
            'item_id' => $items[2]->id, 'ticked_by_user_id' => $hr->id, 'ticked_at' => now()]));

        // Staff cannot tick, and HR never changed an account.
        $this->selectBranch($ca);
        $tick($items[1])->assertForbidden();
        $this->assertSame($before, $this->accounts());
    }

    private function workerFor(User $manager, User $subject): Worker
    {
        $this->selectBranch($manager);
        $this->post(route('hr.records.files.store'), ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return Worker::query()->where('user_id', $subject->id)->firstOrFail();
    }

    /** @param array<string, mixed> $input */
    private function period(User $actor, Worker $worker, array $input): void
    {
        $this->selectBranch($actor);
        $this->post(route('hr.records.periods.store', $worker->public_id), [...$input, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    /** @param array<string, mixed> $input */
    private function upload(User $actor, Worker $worker, array $input, ?UploadedFile $file = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.records.documents.store', $worker->public_id), [...$input, 'file' => $file ?? $this->pdf(), 'idempotency_key' => (string) Str::uuid()]);
    }

    private function remove(User $actor, Worker $worker, Document $document, ?string $reason): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.records.documents.remove', [$worker->public_id, $document->public_id]), ['reason' => $reason,
            'expected_lock_version' => (int) DB::table('hr_documents')->where('id', $document->id)->value('lock_version'), 'idempotency_key' => (string) Str::uuid()]);
    }

    private function pdf(string $mark = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('certificate.pdf', str_replace('MARKER', 'MARKER'.$mark, self::PDF));
    }

    private function assertNoValuesInAudit(): void
    {
        $audit = (string) json_encode(DB::table('audit_logs')->where('event', 'like', 'hr.records.document%')->pluck('metadata')->all());
        $this->assertStringNotContainsString('SYNTHETIC', $audit);
        $this->assertStringNotContainsString('certificate.pdf', $audit);
        $this->assertStringNotContainsString('hr-documents/', $audit);
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
    private function accounts(): array
    {
        return json_decode((string) json_encode([
            'users' => DB::table('users')->orderBy('id')->get(['id', 'is_active', 'name'])->toArray(),
            'roles' => DB::table('model_has_roles')->orderBy('model_id')->orderBy('role_id')->get()->toArray(),
            'assignments' => DB::table('staff_branch_assignments')->orderBy('id')->get()->toArray(),
        ]), true);
    }
}
