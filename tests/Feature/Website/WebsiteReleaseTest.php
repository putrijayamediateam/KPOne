<?php

namespace Tests\Feature\Website;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Website\WebsiteAccess;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsiteEditorialFields;
use App\Domain\Organisation\Website\WebsiteLittleShieldFields;
use App\Domain\Organisation\Website\WebsiteLittleShieldService;
use App\Domain\Organisation\Website\WebsitePeopleFields;
use App\Domain\Organisation\Website\WebsitePermissions as P;
use App\Domain\Organisation\Website\WebsiteProfileFields;
use App\Domain\Organisation\Website\WebsiteReleaseJson as J;
use App\Domain\Organisation\Website\WebsiteReleaseService as S;
use App\Domain\Organisation\Website\WebsiteServiceFields;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Billing\BillingTestCase;

class WebsiteReleaseTest extends BillingTestCase
{
    // The freeze owns a real repeatable-read transaction, not a test savepoint.
    public function refreshDatabase(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('website_media');
    }

    private function input(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    private function failure(callable $call, ?string $field = null): array
    {
        try {
            $call();
            $this->fail('Expected a validation refusal.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
            if ($field) {
                $this->assertStringContainsString($field, implode(' ', array_keys($e->errors())));
            }

            return $e->errors();
        }
    }

    private function profiles(): void
    {
        foreach (Branch::where('organisation_id', $this->organisation->id)->where('is_active', true)->get() as $b) {
            $content = [...WebsiteProfileFields::empty(), 'display_name' => 'Synthetic public branch', 'address_line_1' => 'Synthetic test address', 'postcode' => '00000', 'city' => 'Synthetic city', 'state' => 'Synthetic state', 'public_phone' => '+60000000000', 'opening_hours' => array_fill_keys(WebsiteProfileFields::DAYS, ['closed' => true, 'open' => '', 'close' => ''])];
            $this->source('branch_public_profile', $content, $b->id);
        }
    }

    private function source(string $type, array $changes = [], ?int $branch = null): int
    {
        $empty = match ($type) {
            'branch_public_profile' => WebsiteProfileFields::empty(),'doctor_profile','career_posting' => WebsitePeopleFields::empty($type),'service_category','public_service' => WebsiteServiceFields::empty($type),'promotion','activity','faq' => WebsiteEditorialFields::empty($type),'little_shield' => WebsiteLittleShieldFields::empty()
        };
        $uuid = (string) Str::uuid();
        $item = $branch ? DB::table('website_content_items')->where('branch_id', $branch)->first() : null;
        $id = $item?->id ?? DB::table('website_content_items')->insertGetId(['organisation_id' => $this->organisation->id, 'public_id' => $uuid, 'branch_id' => $branch, 'type' => $type, 'slug' => 'synthetic-'.$uuid, 'lock_version' => 1, 'created_at' => now()]);
        $content = [...$empty, ...$changes];
        if ($type !== 'branch_public_profile') {
            $content['_branch_ids'] = $changes['_branch_ids'] ?? ($type === 'faq' || $type === 'service_category' ? [] : [$this->branch->id]);
        }
        DB::transaction(function () use ($id, $content): void {
            foreach (['en', 'bm'] as $language) {
                DB::table('website_content_revisions')->insert(['organisation_id' => $this->organisation->id, 'item_id' => $id, 'language' => $language, 'revision_number' => DB::table('website_content_revisions')->where('item_id', $id)->where('language', $language)->max('revision_number') + 1, 'content' => J::encode($content), 'hero_asset_id' => null, 'is_seed_copy' => false, 'created_by_user_id' => null, 'created_at' => now()]);
            }
        });

        return $id;
    }

    private function updateSource(int $id, array $changes): void
    {
        DB::transaction(function () use ($id, $changes): void {
            foreach (['en', 'bm'] as $language) {
                $row = DB::table('website_content_revisions')->where('item_id', $id)->where('language', $language)->orderByDesc('revision_number')->first();
                DB::table('website_content_revisions')->insert(['organisation_id' => $this->organisation->id, 'item_id' => $id, 'language' => $language, 'revision_number' => $row->revision_number + 1, 'content' => J::encode([...json_decode($row->content, true), ...$changes]), 'hero_asset_id' => $row->hero_asset_id, 'is_seed_copy' => false, 'created_by_user_id' => null, 'created_at' => now()]);
            }
        });
    }

    private function little(array $extra = []): int
    {
        return $this->source('little_shield', ['title' => 'Synthetic programme', 'introduction' => 'Synthetic introduction', 'programme_pillars' => [['title' => 'Pillar', 'description' => 'Description']], 'programme_flow_steps' => [['title' => 'Step', 'description' => 'Description']], 'medical_safeguards' => ['Requires medical review'], 'include_on_website' => true, ...$extra]);
    }

    private function prepare($actor, array $extra = []): int
    {
        return app(S::class)->mutate($actor, 'prepare', null, $this->input($extra));
    }

    private function decision($actor, int $id, string $op, array $extra = []): int
    {
        $r = app(S::class)->page($actor, $id)['detail'];

        return app(S::class)->mutate($actor, $op, $id, $this->input(['lock_version' => $r['lockVersion'], 'manifest_hash' => $r['hash'], 'confirmation' => $r['number'], 'reason' => 'Synthetic reason', ...$extra]));
    }

    private function asset($actor): object
    {
        app(WebsiteDraftService::class)->upload($actor, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'alt_en' => 'Synthetic poster', 'alt_bm' => 'Poster sintetik', 'rights_reference' => 'PRIVATE_RIGHTS_SENTINEL', 'identifiable_person' => true, 'consent_reference' => 'PRIVATE_CONSENT_SENTINEL'], UploadedFile::fake()->image('synthetic.png', 40, 30));

        return DB::table('website_media_assets')->orderByDesc('id')->first();
    }

    public function test_exact_role_grants_and_every_http_action_denied_without_authority(): void
    {
        $this->profiles();
        $director = $this->actor('director');
        $this->selectBranch($director);
        $id = $this->prepare($director);
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $prepare = in_array($role, ['director', 'marketing', 'administrator'], true);
            $approve = $role === 'director';
            $this->assertSame($prepare, $actor->can(P::PREPARE));
            $this->assertSame($approve, $actor->can(P::APPROVE));
            foreach (['/website/releases', '/website/releases/'.$id, '/website/releases/'.$id.'/media/'.str_repeat('a', 64).'.png'] as $url) {
                $this->assertSame($prepare ? (str_contains($url, '/media/') ? 404 : 200) : 403, $this->get($url)->status(), $role);
            }
            if (! $prepare) {
                $this->post('/website/releases', $this->input())->assertForbidden();
            }
            foreach (['approve', 'refuse', 'withdraw'] as $op) {
                if (! $approve) {
                    $this->post('/website/releases/'.$id.'/'.$op, $this->input())->assertForbidden();
                }
            }
        }
        $this->assertSame(7, Permission::where('name', 'like', 'website.%')->count());
    }

    public function test_active_branch_profiles_required_and_all_errors_returned_without_rows_or_number(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $errors = $this->failure(fn () => $this->prepare($actor), 'prepare');
        $this->assertGreaterThan(10, count($errors));
        $this->assertSame(0, DB::table('website_releases')->count());
        $this->assertSame(0, DB::table('website_release_counters')->count());
        $this->post('/website/releases', $this->input())->assertRedirect('/website/releases')->assertSessionHasErrors();
        $this->profiles();
        $id = $this->prepare($actor);
        $this->assertSame('WEB/'.now()->setTimezone('Asia/Kuala_Lumpur')->year.'/000001', DB::table('website_releases')->where('id', $id)->value('number'));
    }

    public function test_selection_omissions_both_languages_completeness_and_saved_only(): void
    {
        $this->profiles();
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $excluded = $this->source('faq');
        $archived = $this->source('activity');
        DB::table('website_content_items')->where('id', $archived)->update(['archived_at' => now(), 'lock_version' => 2]);
        $little = $this->little();
        $this->updateSource($little, ['include_on_website' => false]);
        $id = $this->prepare($actor, ['content' => ['title' => 'UNSAVED_SENTINEL']]);
        $detail = app(S::class)->page($actor, $id)['detail'];
        $this->assertCount(3, $detail['omissions']);
        $this->assertStringNotContainsString('UNSAVED_SENTINEL', json_encode($detail));
        $this->assertCount(6, $detail['items']);
        $this->updateSource($little, ['include_on_website' => true, 'title' => '']);
        $this->failure(fn () => $this->prepare($actor), 'title');
        $this->assertNotNull($excluded);
    }

    public function test_dependency_category_faq_inclusion_archive_foreign_missing_and_legacy_divergence(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $faq = $this->source('faq', ['question' => 'Synthetic question', 'answer' => 'Synthetic answer', 'include_on_website' => true, 'linked_service_public_id' => null]);
        $category = $this->source('service_category', ['name' => 'Category', 'short_description' => 'Summary', 'include_on_website' => true]);
        $categoryId = DB::table('website_content_items')->where('id', $category)->value('public_id');
        $service = $this->source('public_service', ['name' => 'Service', 'short_summary' => 'Summary', 'seo_title' => 'SEO', 'seo_description' => 'Description', 'description_sections' => [['heading' => 'Heading', 'body' => 'Body']], 'include_on_website' => true, 'parent_public_id' => $categoryId]);
        $serviceId = DB::table('website_content_items')->where('id', $service)->value('public_id');
        $this->updateSource($faq, ['linked_service_public_id' => $serviceId]);
        $id = $this->prepare($actor);
        $this->assertCount(12, app(S::class)->page($actor, $id)['detail']['items']);
        $this->updateSource($category, ['include_on_website' => false]);
        $this->failure(fn () => $this->prepare($actor), 'parent_public_id');
        $this->updateSource($category, ['include_on_website' => true]);
        // Legacy rows are injected only into the task-owned database, with guards immediately restored.
        DB::statement('ALTER TABLE website_content_revisions DISABLE TRIGGER USER');
        try {
            $row = DB::table('website_content_revisions')->where('item_id', $faq)->where('language', 'en')->orderByDesc('revision_number')->first();
            DB::table('website_content_revisions')->where('id', $row->id)->update(['content' => J::encode([...json_decode($row->content, true), 'linked_service_public_id' => (string) Str::uuid()])]);
        } finally {
            DB::statement('ALTER TABLE website_content_revisions ENABLE TRIGGER USER');
        }
        $this->failure(fn () => $this->prepare($actor), 'linked_service_public_id');
        DB::statement('ALTER TABLE website_content_items DISABLE TRIGGER USER');
        try {
            DB::table('website_content_items')->where('id', $category)->update(['archived_at' => now(), 'lock_version' => 2]);
        } finally {
            DB::statement('ALTER TABLE website_content_items ENABLE TRIGGER USER');
        }$this->failure(fn () => $this->prepare($actor), 'parent_public_id');
    }

    public function test_public_projection_sentinels_all_types_and_nested_fields_never_reach_manifest_or_preview(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $asset = $this->asset($actor);
        $private = ['rights_reference', 'consent_reference', 'profile_consent_reference', 'registration_display_reference', 'price_display_reference', 'offer_display_reference', 'path', 'slug', 'private_staff_email', 'internal_id', 'audit_data'];
        $sentinels = [];
        foreach ($private as $field) {
            $sentinels[$field] = 'PRIVATE_'.strtoupper($field).'_SENTINEL';
        }
        $category = $this->source('service_category', ['name' => 'Category', 'short_description' => 'Description', 'include_on_website' => true, ...$sentinels]);
        $cat = DB::table('website_content_items')->where('id', $category)->value('public_id');
        $service = $this->source('public_service', ['name' => 'Service', 'short_summary' => 'Summary', 'seo_title' => 'SEO', 'seo_description' => 'SEO description', 'description_sections' => [['heading' => 'Heading', 'body' => 'Body', 'path' => 'NESTED_SENTINEL']], 'price_text' => 'RM 1', 'include_on_website' => true, 'parent_public_id' => $cat, ...$sentinels]);
        $pub = DB::table('website_content_items')->where('id', $service)->value('public_id');
        $this->source('doctor_profile', ['display_name' => 'Synthetic public doctor', 'professional_title' => 'Doctor', 'biography' => 'Biography', 'qualifications' => ['Qualification'], 'areas_of_practice' => ['Practice'], 'languages_spoken' => ['BM'], 'public_registration_text' => 'MMC SYNTHETIC', 'include_on_website' => true, ...$sentinels]);
        $this->source('career_posting', ['title' => 'Synthetic role', 'summary' => 'Summary', 'location_note' => 'Location', 'responsibilities' => ['Responsibility'], 'requirements' => ['Requirement'], 'apply_email' => 'careers@kpone.test', 'open' => true, ...$sentinels]);
        foreach (['promotion', 'activity'] as $type) {
            $this->source($type, ['title' => 'Synthetic '.$type, 'short_summary' => 'Summary', 'description_sections' => [['heading' => 'Heading', 'body' => 'Body']], 'offer_text' => 'Manual offer', 'include_on_website' => true, ...$sentinels]);
        }
        $this->source('faq', ['question' => 'Question', 'answer' => 'Answer', 'linked_service_public_id' => $pub, 'include_on_website' => true, ...$sentinels]);
        $this->little(['cover_public_id' => $asset->public_id, ...$sentinels]);
        $id = $this->prepare($actor);
        $release = DB::table('website_releases')->where('id', $id)->first();
        $preview = J::encode(app(S::class)->page($actor, $id));
        foreach ([...array_values($sentinels), 'NESTED_SENTINEL', 'PRIVATE_RIGHTS_SENTINEL', 'PRIVATE_CONSENT_SENTINEL', $asset->path] as $value) {
            $this->assertStringNotContainsString($value, $release->manifest);
            $this->assertStringNotContainsString($value, $preview);
        }
        $this->assertSame(22, DB::table('website_release_items')->where('release_id', $id)->count());
        $this->assertStringContainsString('MMC SYNTHETIC', $release->manifest);
        $this->assertStringContainsString('Manual offer', $release->manifest);
        $this->assertStringContainsString('RM 1', $release->manifest);
    }

    public function test_media_rejection_integrity_consent_and_snapshot_retained_after_draft_and_asset_changes(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $asset = $this->asset($actor);
        $little = $this->little(['cover_public_id' => $asset->public_id]);
        $id = $this->prepare($actor);
        $before = app(S::class)->page($actor, $id)['detail'];
        $filename = $before['media'][0]['filename'];
        $bytes = app(S::class)->media($actor, $id, $filename)['content'];
        $this->updateSource($little, ['title' => 'Changed after freeze']);
        app(WebsiteDraftService::class)->retire($actor, $asset->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame($before, app(S::class)->page($actor, $id)['detail']);
        $this->assertSame($bytes, app(S::class)->media($actor, $id, $filename)['content']);
        $this->failure(fn () => $this->prepare($actor), 'media');
        Storage::disk('website_media')->put($asset->path, 'corrupt');
        $this->failure(fn () => $this->prepare($actor), 'media');
        $this->get('/website/releases/'.$id.'/media/'.$filename)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Content-Type', 'image/png');
    }

    public function test_doctor_and_manual_display_references_required_in_each_language(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $doctor = $this->source('doctor_profile', ['display_name' => 'Doctor', 'professional_title' => 'Doctor', 'biography' => 'Bio', 'qualifications' => ['A'], 'areas_of_practice' => ['A'], 'languages_spoken' => ['BM'], 'public_registration_text' => 'MMC SYNTHETIC', 'include_on_website' => true]);
        $errors = $this->failure(fn () => $this->prepare($actor), 'profile_consent_reference');
        $this->assertStringContainsString('registration_display_reference', implode(' ', array_keys($errors)));
        $this->updateSource($doctor, ['profile_consent_reference' => 'PRIVATE_REFERENCE', 'registration_display_reference' => 'PRIVATE_APPROVAL']);
        $this->assertGreaterThan(0, $this->prepare($actor));
    }

    public function test_state_machine_typed_confirmation_hash_versions_retry_conflict_and_reason_validation(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $input = $this->input();
        $s = app(S::class);
        $id = $s->mutate($actor, 'prepare', null, $input);
        $this->assertSame($id, $s->mutate($actor, 'prepare', null, $input));
        $this->failure(fn () => $s->mutate($actor, 'prepare', null, [...$input, 'lock_version' => 1]), 'idempotency_key');
        $this->failure(fn () => $this->decision($actor, $id, 'approve', ['confirmation' => 'wrong']), 'confirmation');
        $this->failure(fn () => $this->decision($actor, $id, 'approve', ['manifest_hash' => str_repeat('0', 64)]), 'version');
        $this->failure(fn () => $this->decision($actor, $id, 'approve', ['lock_version' => 0]), 'version');
        $detail = $s->page($actor, $id)['detail'];
        $command = $this->input(['lock_version' => 1, 'manifest_hash' => $detail['hash'], 'confirmation' => $detail['number']]);
        $s->mutate($actor, 'approve', $id, $command);
        $this->assertSame($id, $s->mutate($actor, 'approve', $id, $command));
        $this->failure(fn () => $this->decision($actor, $id, 'approve'), 'state');
        $this->failure(fn () => $this->decision($actor, $id, 'refuse'), 'state');
        foreach (['', str_repeat('x', 301), "bad\u{202e}", '**markup**'] as $bad) {
            $this->failure(fn () => $this->decision($actor, $id, 'withdraw', ['reason' => $bad]));
        }
        $this->decision($actor, $id, 'withdraw');
        $this->failure(fn () => $this->decision($actor, $id, 'approve'), 'state');
        $second = $this->prepare($actor);
        $this->decision($actor, $second, 'refuse');
        $this->failure(fn () => $this->decision($actor, $second, 'approve'), 'state');
        $this->assertSame(['prepared', 'approved', 'withdrawn'], $s->page($actor, $id)['detail']['actions'] ? array_column($s->page($actor, $id)['detail']['actions'], 'action') : []);
    }

    public function test_manifest_determinism_numbering_local_new_year_and_change_list(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $little = $this->little();
        $this->travelTo(CarbonImmutable::parse('2026-12-31T15:59:59Z'));
        $a = $this->prepare($actor);
        $b = $this->prepare($actor);
        $s = app(S::class);
        $first = $s->page($actor, $a)['detail'];
        $this->assertSame($first['hash'], $s->page($actor, $b)['detail']['hash']);
        $this->assertSame('WEB/2026/000001', $first['number']);
        $this->assertSame('WEB/2026/000002', $s->page($actor, $b)['detail']['number']);
        $this->decision($actor, $a, 'approve');
        $this->updateSource($little, ['title' => 'Changed title', '_branch_ids' => []]);
        $this->failure(fn () => $this->prepare($actor), 'branch_ids');
        $this->updateSource($little, ['_branch_ids' => [$this->branch->id], 'title' => 'Changed title']);
        $this->travelTo(CarbonImmutable::parse('2026-12-31T16:00:00Z'));
        $c = $this->prepare($actor);
        $detail = $s->page($actor, $c)['detail'];
        $this->assertSame('WEB/2027/000001', $detail['number']);
        $this->assertCount(2, $detail['changes']['changed']);
        $this->assertSame($first['number'], $detail['comparedTo']);
        $this->travelBack();
    }

    public function test_database_refuses_all_evidence_updates_deletes_truncates_and_counter_reuse(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $this->asset($actor);
        $this->little(['cover_public_id' => DB::table('website_media_assets')->value('public_id')]);
        $this->prepare($actor);
        foreach (['website_releases', 'website_release_items', 'website_release_media', 'website_release_actions', 'website_release_commands'] as $table) {
            foreach (["UPDATE {$table} SET id=id", "DELETE FROM {$table}", "TRUNCATE {$table} CASCADE"] as $sql) {
                try {
                    DB::statement($sql);
                    $this->fail('Immutable evidence changed');
                } catch (QueryException $e) {
                    $this->assertSame('P0001', $e->errorInfo[0]);
                }
            }
        }
        foreach (['UPDATE website_release_counters SET last_number=0', 'DELETE FROM website_release_counters', 'TRUNCATE website_release_counters', "UPDATE website_release_controls SET state='withdrawn',lock_version=2"] as $sql) {
            try {
                DB::statement($sql);
                $this->fail('Invalid metadata mutation');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
        }
    }

    public function test_fresh_revocation_and_foreign_organisation_and_branch_scope(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $id = $this->prepare($actor);
        $other = Organisation::create(['code' => 'SYNTHETIC_OTHER', 'name' => 'Synthetic other']);
        $branch = Branch::forceCreate(['organisation_id' => $other->id, 'code' => 'OTHER', 'name' => 'Synthetic other branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreign = $this->actor('director', $branch);
        $this->selectBranch($foreign, $branch);
        $this->get('/website/releases/'.$id)->assertNotFound();
        $this->post('/website/releases/'.$id.'/approve', $this->input(['expected_branch_id' => $branch->id]))->assertNotFound();
        $this->selectBranch($actor);
        $this->failure(fn () => $this->prepare($actor, ['expected_branch_id' => $branch->id]));
        DB::table('role_has_permissions')->where('role_id', $actor->roles()->first()->id)->where('permission_id', Permission::findByName(P::PREPARE)->id)->delete();
        $this->get('/website/releases')->assertForbidden();
        try {
            $this->prepare($actor);
            $this->fail('Cached authority used');
        } catch (HttpException|AuthorizationException $e) {
            $this->assertNotEmpty($e);
        }
    }

    private function rows(): array
    {
        $out = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $r) {
            if (str_starts_with($r->tablename, 'website_release') || $r->tablename === 'website_releases' || $r->tablename === 'audit_logs') {
                continue;
            }$q = DB::table($r->tablename);
            foreach (in_array('id', Schema::getColumnListing($r->tablename), true) ? ['id'] : Schema::getColumnListing($r->tablename) as $c) {
                $q->orderBy($c);
            }$out[$r->tablename] = $q->get()->toJson();
        }

        return $out;
    }

    public function test_all_unrelated_rows_queries_http_jobs_scheduler_and_audit_are_values_free(): void
    {
        $this->finalizedFixture();
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $this->little();
        $s = app(S::class);
        Http::preventStrayRequests();
        Queue::fake();
        DB::enableQueryLog();
        DB::flushQueryLog();
        DB::transaction(fn () => app(WebsiteAccess::class)->lock($actor, $this->branch->id, P::PREPARE));
        $authority = array_map(fn ($q) => [$q['query'], $q['bindings']], DB::getQueryLog());
        DB::disableQueryLog();
        $schedule = array_map(fn ($e) => [$e->expression, $e->command], app(Schedule::class)->events());
        $before = $this->rows();
        $id = null;
        foreach ([function () use ($actor, &$id) {
            $id = $this->prepare($actor);
        }, function () use ($s, $actor, &$id) {
            $s->page($actor, $id);
        }, function () use ($actor, &$id) {
            $this->decision($actor, $id, 'approve');
        }, function () use ($actor, &$id) {
            $this->decision($actor, $id, 'withdraw');
        }, function () use ($actor, &$id) {
            $id = $this->prepare($actor);
        }, function () use ($actor, &$id) {
            $this->decision($actor, $id, 'refuse');
        }, function () use (&$id) {
            $this->get('/website/releases/'.$id)->assertOk();
        }] as $action) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $action();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            foreach ($queries as $q) {
                $this->assertDoesNotMatchRegularExpression('/(?:from|join|into|update)\s+"?(?:patients|patient_[a-z_]+|visits|visit_[a-z_]+|queue_[a-z_]+|clinical_[a-z_]+|encounters|treatment_[a-z_]+|invoices|invoice_[a-z_]+|payments|payment_[a-z_]+|dispensary_[a-z_]+|stock_[a-z_]+|inventory_[a-z_]+|medicine[a-z_]*|charge_definitions|price_[a-z_]+|appointments|clinician_credentials|staff_schedules|staff_rosters)"?\b/i', $q['query']);
                if (preg_match('/(?:from|join)\s+"?(?:users|staff_profiles|staff_branch_assignments)"?\b/i', $q['query'])) {
                    if (preg_match('/\Aselect "name", "id" from "users" where "organisation_id" = \? and "id" in \(\?(?:, \?)*\)\z/', $q['query'])) {
                        // This one extra read is presentation-only; direct mutations/media
                        // are separately tested to issue no display-name lookup.
                        $this->assertSame($actor->organisation_id, $q['bindings'][0]);
                        $releaseIds = DB::table('website_releases')->where('organisation_id', $actor->organisation_id)->pluck('id');
                        $releaseActors = DB::table('website_releases')->whereIn('id', $releaseIds)->pluck('created_by_user_id')->merge(DB::table('website_release_actions')->whereIn('release_id', $releaseIds)->pluck('actor_user_id'))->unique()->all();
                        foreach (array_slice($q['bindings'], 1) as $actorId) {
                            $this->assertContains($actorId, $releaseActors);
                        }
                        $this->assertCount(1, array_filter($queries, fn ($query) => preg_match('/\Aselect "name", "id" from "users" where "organisation_id" = \? and "id" in \(\?(?:, \?)*\)\z/', $query['query'])));
                    } else {
                        $this->assertContains([$q['query'], $q['bindings']], $authority);
                    }
                }
            }
            $this->assertSame($before, $this->rows());
        }
        foreach (DB::table('audit_logs')->where('event', 'like', 'website.release.%')->get() as $audit) {
            $this->assertStringNotContainsString('Synthetic reason', $audit->metadata);
            $this->assertSame(['action', 'counts', 'manifest_hash_prefix', 'number', 'release_id'], collect(array_keys(json_decode($audit->metadata, true)))->sort()->values()->all());
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame($schedule, array_map(fn ($e) => [$e->expression, $e->command], app(Schedule::class)->events()));
    }

    public function test_audit_failure_rolls_back_prepare_and_every_decision(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $s = app(S::class);
        $id = $this->prepare($actor);
        $original = app(AuditRecorder::class);
        foreach (['prepare', 'approve', 'refuse', 'withdraw'] as $operation) {
            if ($operation === 'withdraw') {
                $this->decision($actor, $id, 'approve');
            }
            $before = [];
            foreach (['website_releases', 'website_release_controls', 'website_release_items', 'website_release_media', 'website_release_actions', 'website_release_commands', 'website_release_counters', 'audit_logs'] as $table) {
                $before[$table] = DB::table($table)->get()->toJson();
            }
            $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andReturnNull();
            $this->app->forgetInstance(S::class);
            try {
                if ($operation === 'prepare') {
                    $this->prepare($actor);
                } else {
                    $this->decision($actor, $id, $operation);
                }$this->fail('Audit failure accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('audit', $e->getMessage());
            }
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->get()->toJson());
            }$this->app->instance(AuditRecorder::class, $original);
            $this->app->forgetInstance(S::class);
        }
    }

    public function test_private_no_store_noindex_http_and_no_unsaved_content(): void
    {
        $this->profiles();
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $id = $this->prepare($actor);
        $this->get('/website/releases/'.$id)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')->assertDontSee('PRIVATE_SENTINEL');
        $this->app['auth']->forgetGuards();
        $this->get('/website/releases')->assertRedirect('/login');
    }

    public function test_all_media_failure_modes_and_foreign_branches_are_independent_blockers(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $asset = $this->asset($actor);
        $id = $this->little(['cover_public_id' => $asset->public_id]);
        $original = Storage::disk('website_media')->get($asset->path);
        foreach (['missing', 'corrupt', 'hash', 'consent', 'rights', 'foreign'] as $mode) {
            if ($mode === 'missing') {
                Storage::disk('website_media')->delete($asset->path);
            } elseif ($mode === 'corrupt') {
                Storage::disk('website_media')->put($asset->path, 'not an image');
            } else {
                DB::statement('ALTER TABLE website_media_assets DISABLE TRIGGER USER');
                try {
                    DB::table('website_media_assets')->where('id', $asset->id)->update(match ($mode) {
                        'hash' => ['sha256' => str_repeat('0', 64)],'consent' => ['consent_reference' => ''],'rights' => ['rights_reference' => ''],'foreign' => ['organisation_id' => Organisation::create(['code' => 'SYN_FOREIGN_MEDIA', 'name' => 'Synthetic media organisation'])->id]
                    });
                } finally {
                    DB::statement('ALTER TABLE website_media_assets ENABLE TRIGGER USER');
                }
            }
            $this->failure(fn () => $this->prepare($actor), 'media');
            DB::statement('ALTER TABLE website_media_assets DISABLE TRIGGER USER');
            try {
                DB::table('website_media_assets')->where('id', $asset->id)->update(['sha256' => $asset->sha256, 'consent_reference' => $asset->consent_reference, 'rights_reference' => $asset->rights_reference, 'organisation_id' => $asset->organisation_id]);
            } finally {
                DB::statement('ALTER TABLE website_media_assets ENABLE TRIGGER USER');
            }
            Storage::disk('website_media')->put($asset->path, $original);
        }
        $this->updateSource($id, ['_branch_ids' => [999999]]);
        $this->failure(fn () => $this->prepare($actor), 'branches');
    }

    public function test_approve_permission_revocation_is_fresh_and_migration_is_additive_replay_safe(): void
    {
        $migration = require database_path('migrations/2026_10_26_000200_grant_website_release_permissions.php');
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $id = $this->prepare($actor);
        $this->assertTrue($actor->can(P::APPROVE));
        DB::table('role_has_permissions')->where('role_id', $actor->roles()->first()->id)->where('permission_id', Permission::findByName(P::APPROVE)->id)->delete();
        foreach (['approve', 'refuse', 'withdraw'] as $operation) {
            $this->post('/website/releases/'.$id.'/'.$operation, $this->input())->assertForbidden();
        }
        $this->assertSame('prepared', DB::table('website_release_controls')->where('release_id', $id)->value('state'));
    }

    public function test_concurrent_preparations_allocate_unique_numbers_with_no_mixed_source_snapshot(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $this->little();
        $dir = sys_get_temp_dir().'/release-concurrency-'.Str::uuid();
        mkdir($dir);
        DB::purge();
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Fork failed');
            }
            if ($pid === 0) {
                try {
                    $id = $this->prepare($actor);
                    file_put_contents($dir.'/'.$i, json_encode(['id' => $id]));
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents($dir.'/'.$i, json_encode(['error' => $e->getMessage()]));
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), implode(' ', array_map('file_get_contents', glob($dir.'/*'))));
        }
        DB::purge();
        $rows = DB::table('website_releases')->orderBy('number')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]->manifest_hash, $rows[1]->manifest_hash);
        $this->assertStringEndsWith('/000001', $rows[0]->number);
        $this->assertStringEndsWith('/000002', $rows[1]->number);
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }rmdir($dir);
    }

    public function test_edit_started_during_capture_waits_and_cannot_mix_frozen_languages(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $little = $this->little();
        $dir = sys_get_temp_dir().'/release-snapshot-'.Str::uuid();
        mkdir($dir);
        DB::purge();
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $until = microtime(true) + 20;
                while (! file_exists($dir.'/go') && microtime(true) < $until) {
                    usleep(10000);
                }if (! file_exists($dir.'/go')) {
                    throw new \RuntimeException('Snapshot signal missing');
                }
                $item = DB::table('website_content_items')->where('id', $little)->first();
                $row = DB::table('website_content_revisions')->where('item_id', $little)->where('language', 'en')->orderByDesc('revision_number')->first();
                $content = json_decode($row->content, true);
                unset($content['_branch_ids']);
                $content['title'] = 'After concurrent edit';
                app(WebsiteLittleShieldService::class)->save($actor, ['expected_branch_id' => $this->branch->id, 'item_public_id' => $item->public_id, 'lock_version' => $item->lock_version, 'idempotency_key' => (string) Str::uuid(), 'language' => 'en', 'content' => $content, 'branch_ids' => [$this->branch->id]]);
                file_put_contents($dir.'/done', 'ok');
                DB::disconnect();
                exit(0);
            } catch (\Throwable $e) {
                file_put_contents($dir.'/done', $e->getMessage());
                exit(1);
            }
        }
        if ($pid === -1) {
            throw new \RuntimeException('Fork failed');
        }
        $signalled = false;
        DB::listen(function ($event) use ($dir, &$signalled): void {
            if (! $signalled && str_contains($event->sql, 'website_content_revisions')) {
                $signalled = true;
                file_put_contents($dir.'/go', 'go');
                usleep(100000);
            }
        });
        $id = $this->prepare($actor);
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status), file_exists($dir.'/done') ? file_get_contents($dir.'/done') : 'No outcome');
        $manifest = DB::table('website_releases')->where('id', $id)->value('manifest');
        $this->assertStringNotContainsString('After concurrent edit', $manifest);
        $this->assertSame(2, substr_count($manifest, 'Synthetic programme'));
        $this->assertStringContainsString('After concurrent edit', DB::table('website_content_revisions')->where('item_id', $little)->where('language', 'en')->orderByDesc('revision_number')->value('content'));
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }rmdir($dir);
    }

    public function test_change_list_added_removed_media_and_branch_changes_are_exact(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $first = $this->asset($actor);
        $little = $this->little(['cover_public_id' => $first->public_id]);
        $old = $this->prepare($actor);
        $this->decision($actor, $old, 'approve');
        // Different sanitised pixels ensure a genuinely different content hash.
        $image = UploadedFile::fake()->image('second.png', 50, 31);
        app(WebsiteDraftService::class)->upload($actor, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'alt_en' => 'Second synthetic poster', 'alt_bm' => 'Poster sintetik kedua', 'rights_reference' => 'Private rights', 'identifiable_person' => false, 'consent_reference' => ''], $image);
        $second = DB::table('website_media_assets')->orderByDesc('id')->first();
        $other = Branch::where('organisation_id', $this->organisation->id)->where('id', '<>', $this->branch->id)->first();
        $this->updateSource($little, ['cover_public_id' => $second->public_id, '_branch_ids' => [$other->id]]);
        $this->source('faq', ['question' => 'New question', 'answer' => 'New answer', 'include_on_website' => true]);
        $id = $this->prepare($actor);
        $changes = app(S::class)->page($actor, $id)['detail']['changes'];
        $this->assertCount(2, $changes['added']);
        $this->assertCount(2, $changes['changed']);
        $this->assertCount(2, $changes['branches']);
        $this->assertSame([$second->sha256], $changes['mediaAdded']);
        $this->assertSame([$first->sha256], $changes['mediaRemoved']);
        $this->updateSource($little, ['include_on_website' => false]);
        $id = $this->prepare($actor);
        $this->assertCount(2, app(S::class)->page($actor, $id)['detail']['changes']['removed']);
    }

    public function test_release_page_names_are_batched_scoped_display_only_with_fallback(): void
    {
        $this->profiles();
        $creator = $this->actor('marketing');
        $director = $this->actor('director');
        $unused = $this->actor('marketing');
        $other = Organisation::create(['code' => 'SYN_NAMES_OTHER', 'name' => 'Synthetic other']);
        $foreign = User::factory()->create(['organisation_id' => $other->id, 'name' => 'Synthetic foreign actor', 'email' => 'foreign.names@kpone.test']);
        $creator->forceFill(['name' => 'Synthetic Website Editor'])->save();
        $director->forceFill(['name' => 'Synthetic Website Approver'])->save();
        $this->selectBranch($creator);
        $first = $this->prepare($creator);
        $read = function (int $id) use ($director): array {
            $this->selectBranch($director);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $page = app(S::class)->page($director, $id);
            $queries = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'select "name", "id" from "users"')));
            DB::disableQueryLog();
            $this->assertCount(1, $queries);
            $this->assertMatchesRegularExpression('/\Aselect "name", "id" from "users" where "organisation_id" = \? and "id" in \(\?(?:, \?)*\)\z/', $queries[0]['query']);
            $this->assertSame($director->organisation_id, $queries[0]['bindings'][0]);

            return [$page, array_slice($queries[0]['bindings'], 1)];
        };
        [$page, $ids] = $read($first);
        $this->assertSame([$creator->id], $ids);
        $this->assertSame('Synthetic Website Editor', $page['releases'][0]['creator']);
        $this->assertSame(['Synthetic Website Editor'], array_column($page['detail']['actions'], 'actor'));
        $this->decision($director, $first, 'approve');
        $this->decision($director, $first, 'withdraw');
        $this->selectBranch($creator);
        $second = $this->prepare($creator);
        $this->decision($director, $second, 'refuse');
        $this->selectBranch($creator);
        $this->prepare($creator);
        $before = [];
        foreach (['website_releases', 'website_release_items', 'website_release_media', 'website_release_actions', 'website_release_controls', 'website_release_commands', 'website_release_counters', 'audit_logs'] as $table) {
            $before[$table] = DB::table($table)->orderBy($table === 'website_release_controls' ? 'release_id' : 'id')->get()->toJson();
        }
        [$page, $ids] = $read($first);
        sort($ids);
        $expected = [$creator->id, $director->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains($unused->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $summary = collect($page['releases'])->firstWhere('id', $first);
        $this->assertSame('Synthetic Website Editor', $summary['creator']);
        $this->assertSame('Synthetic Website Approver', $summary['approver']);
        $this->assertSame(['Synthetic Website Editor', 'Synthetic Website Approver', 'Synthetic Website Approver'], array_column($page['detail']['actions'], 'actor'));
        $creator->forceFill(['name' => 'Synthetic Renamed Editor'])->save();
        [$page] = $read($first);
        $this->assertSame('Synthetic Renamed Editor', collect($page['releases'])->firstWhere('id', $first)['creator']);
        // A row no longer present in the scoped organisation must use the id fallback.
        DB::table('users')->where('id', $creator->id)->update(['organisation_id' => $other->id]);
        [$page] = $read($first);
        $this->assertSame('Staff #'.$creator->id, collect($page['releases'])->firstWhere('id', $first)['creator']);
        $this->assertSame('Staff #'.$creator->id, $page['detail']['actions'][0]['actor']);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy($table === 'website_release_controls' ? 'release_id' : 'id')->get()->toJson(), $table);
        }
    }

    public function test_change_baseline_is_currently_approved_lower_id_only(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $s = app(S::class);
        $first = $this->prepare($actor);
        $this->decision($actor, $first, 'approve');
        $second = $this->prepare($actor);
        $this->decision($actor, $second, 'approve');
        $this->assertSame('First release', $s->page($actor, $first)['detail']['comparedTo']);
        $this->decision($actor, $second, 'withdraw');
        $third = $this->prepare($actor);
        $this->decision($actor, $third, 'refuse');
        $fourth = $this->prepare($actor);
        $fifth = $this->prepare($actor);
        $firstNumber = DB::table('website_releases')->where('id', $first)->value('number');
        $this->assertSame($firstNumber, $s->page($actor, $fifth)['detail']['comparedTo']);
        $this->assertSame($firstNumber, $s->page($actor, $second)['detail']['comparedTo']);
        $this->assertSame($firstNumber, $s->page($actor, $fourth)['detail']['comparedTo']);
        $this->decision($actor, $first, 'withdraw');
        $this->assertSame('First release', $s->page($actor, $fifth)['detail']['comparedTo']);
        $this->decision($actor, $fifth, 'approve');
        $this->assertSame('First release', $s->page($actor, $fourth)['detail']['comparedTo']);
        $this->assertSame('First release', $s->page($actor, $first)['detail']['comparedTo']);
    }

    public function test_display_name_query_is_absent_from_direct_mutations_and_media(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $asset = $this->asset($actor);
        $this->little(['cover_public_id' => $asset->public_id]);
        $s = app(S::class);
        $check = function (callable $call): mixed {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = $call();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            foreach ($queries as $q) {
                $this->assertStringNotContainsString('select "name", "id" from "users"', $q['query']);
            }

            return $result;
        };
        $id = $check(fn () => $s->mutate($actor, 'prepare', null, $this->input()));
        $detail = $s->page($actor, $id)['detail'];
        $check(fn () => $s->media($actor, $id, $detail['media'][0]['filename']));
        $check(fn () => $s->mutate($actor, 'approve', $id, $this->input(['lock_version' => 1, 'manifest_hash' => $detail['hash'], 'confirmation' => $detail['number']])));
        $check(fn () => $s->mutate($actor, 'withdraw', $id, $this->input(['lock_version' => 2, 'manifest_hash' => $detail['hash'], 'reason' => 'Synthetic reason'])));
        $second = $check(fn () => $s->mutate($actor, 'prepare', null, $this->input()));
        $detail = $s->page($actor, $second)['detail'];
        $check(fn () => $s->mutate($actor, 'refuse', $second, $this->input(['lock_version' => 1, 'manifest_hash' => $detail['hash'], 'reason' => 'Synthetic reason'])));
    }
}
