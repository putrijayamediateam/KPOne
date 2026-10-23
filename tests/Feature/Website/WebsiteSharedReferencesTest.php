<?php

namespace Tests\Feature\Website;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsiteEditorialFields;
use App\Domain\Organisation\Website\WebsiteEditorialService;
use App\Domain\Organisation\Website\WebsitePeopleFields;
use App\Domain\Organisation\Website\WebsitePeopleService;
use App\Domain\Organisation\Website\WebsiteServiceFields;
use App\Domain\Organisation\Website\WebsiteServicesService;
use App\Domain\Organisation\Website\WebsiteSharedReferences;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestCase;

class WebsiteSharedReferencesTest extends BillingTestCase
{
    private function service(string $type): WebsitePeopleService|WebsiteServicesService|WebsiteEditorialService
    {
        return app(match ($type) {
            'doctor_profile','career_posting' => WebsitePeopleService::class,'service_category','public_service' => WebsiteServicesService::class,default => WebsiteEditorialService::class
        });
    }

    private function input(string $type, int $version = 0, string $language = 'en', array $refs = []): array
    {
        $fields = match ($type) {
            'doctor_profile','career_posting' => WebsitePeopleFields::class,'service_category','public_service' => WebsiteServiceFields::class,default => WebsiteEditorialFields::class
        };
        $content = $fields::empty($type);
        $content[match ($type) {
            'doctor_profile' => 'display_name','service_category','public_service' => 'name','faq' => 'question',default => 'title'
        }] = 'SYNTHETIC '.$language.' TEXT';
        if ($type === 'career_posting') {
            $content['apply_email'] = 'careers@synthetic.test';
        }
        $branches = in_array($type, ['service_category', 'faq'], true) ? [] : [$this->branch->id];
        foreach ($refs as $key => $value) {
            if ($key === '_branch_ids') {
                $branches = $value;
            } else {
                $content[$key] = $value;
            }
        }

        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'language' => $language, 'content' => $content, 'branch_ids' => $branches, 'portrait_public_id' => null, 'idempotency_key' => (string) Str::uuid()];
    }

    private function create(User $actor, string $type, array $refs = []): string
    {
        return $this->service($type)->mutate($actor, $type, null, 'save', $this->input($type, refs: $refs));
    }

    private function current(string $id, string $language): \stdClass
    {
        return DB::table('website_content_revisions')->where('item_id', DB::table('website_content_items')->where('public_id', $id)->value('id'))->where('language', $language)->orderByDesc('revision_number')->first();
    }

    private function refuse(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected validation refusal.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function refs(string $type, int $branch, string $root, string $service): array
    {
        return match ($type) {
            'service_category' => ['parent_public_id' => $root],'public_service' => ['_branch_ids' => [$branch], 'parent_public_id' => $root],'faq' => ['linked_service_public_id' => $service],default => ['_branch_ids' => [$branch]]
        };
    }

    public function test_every_type_coordinated_append_preserves_text_one_version_one_audit_retry_stale_and_nonshared_save(): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $this->finalizedFixture();
        $this->selectBranch($actor);
        $rootA = $this->create($actor, 'service_category');
        $rootB = $this->create($actor, 'service_category');
        $svcA = $this->create($actor, 'public_service');
        $svcB = $this->create($actor, 'public_service');
        $branch = Branch::where('organisation_id', $actor->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail()->id;
        foreach (['doctor_profile', 'career_posting', 'service_category', 'public_service', 'promotion', 'activity', 'faq'] as $type) {
            $initial = $this->refs($type, $this->branch->id, $rootA, $svcA);
            $changed = $this->refs($type, $branch, $rootB, $svcB);
            $id = $this->create($actor, $type, $initial);
            $s = $this->service($type);
            $s->mutate($actor, $type, $id, 'save', $this->input($type, 1, 'bm', $initial));
            $en = $this->current($id, 'en');
            $bm = $this->current($id, 'bm');
            $before = $this->rows($id);
            $auditBefore = DB::table('audit_logs')->count();
            $cmd = $this->input($type, 2, 'en', $changed);
            $s->mutate($actor, $type, $id, 'save', $cmd);
            $newBm = $this->current($id, 'bm');
            $newEn = $this->current($id, 'en');
            $this->assertSame(2, $newEn->revision_number);
            $this->assertSame(2, $newBm->revision_number);
            $oldContent = json_decode($bm->content, true);
            $newContent = json_decode($newBm->content, true);
            $otherContent = json_decode($newEn->content, true);
            foreach (WebsiteSharedReferences::fields($type) as $key) {
                $this->assertSame($otherContent[$key] ?? null, $newContent[$key] ?? null);
                unset($oldContent[$key],$newContent[$key]);
            }
            $this->assertSame($oldContent, $newContent);
            $this->assertSame($bm->hero_asset_id, $newBm->hero_asset_id);
            $this->assertSame($bm->content, DB::table('website_content_revisions')->where('id', $bm->id)->value('content'));
            $this->assertSame($en->content, DB::table('website_content_revisions')->where('id', $en->id)->value('content'));
            $this->assertSame(3, DB::table('website_content_items')->where('public_id', $id)->value('lock_version'));
            $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());
            $this->assertSame($before, $this->rows($id));
            $audit = json_decode(DB::table('audit_logs')->orderByDesc('id')->value('metadata'), true);
            $this->assertSame(['en', 'bm'], $audit['languages']);
            $this->assertStringNotContainsString('SYNTHETIC', json_encode($audit));
            $this->assertStringNotContainsString($rootB, json_encode($audit));
            $this->assertStringNotContainsString($svcB, json_encode($audit));
            $this->assertSame($id, $s->mutate($actor, $type, $id, 'save', $cmd));
            $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());
            $conflict = $cmd;
            $conflict['content']['sort_order'] = 4;
            $this->refuse(fn () => $s->mutate($actor, $type, $id, 'save', $conflict));
            $this->refuse(fn () => $s->mutate($actor, $type, $id, 'save', $this->input($type, 2, 'en', $changed)));
            $plain = $this->input($type, 3, 'en', $changed);
            $plain['content']['sort_order'] = 5;
            $s->mutate($actor, $type, $id, 'save', $plain);
            $this->assertSame($newBm->id, $this->current($id, 'bm')->id);
            $this->assertSame(4, DB::table('website_content_items')->where('public_id', $id)->value('lock_version'));
            $this->assertArrayNotHasKey('languages', json_decode(DB::table('audit_logs')->orderByDesc('id')->value('metadata'), true));
        }
    }

    private function rows(?string $target = null): array
    {
        $id = $target ? DB::table('website_content_items')->where('public_id', $target)->value('id') : null;
        $out = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            if ($target && $table->tablename === 'audit_logs') {
                continue;
            }
            $q = DB::table($table->tablename);
            if ($target && $table->tablename === 'website_content_items') {
                $q->where('id', '<>', $id);
            }
            if ($target && in_array($table->tablename, ['website_content_revisions', 'website_content_item_branches'])) {
                $q->where('item_id', '<>', $id);
            }
            if ($target && $table->tablename === 'website_draft_commands') {
                $q->where('result_id', '<>', $id);
            }
            $columns = Schema::getColumnListing($table->tablename);
            foreach (in_array('id', $columns) ? ['id'] : $columns as $c) {
                $q->orderBy($c);
            }
            $out[$table->tablename] = $q->get()->toJson();
        }

        return $out;
    }

    public function test_deferred_constraint_rejects_direct_divergence_for_every_type_and_allows_a_matching_pair(): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $rootA = $this->create($actor, 'service_category');
        $rootB = $this->create($actor, 'service_category');
        $svcA = $this->create($actor, 'public_service');
        $svcB = $this->create($actor, 'public_service');
        $branch = Branch::where('organisation_id', $actor->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail()->id;
        foreach (['doctor_profile', 'career_posting', 'service_category', 'public_service', 'promotion', 'activity', 'faq'] as $type) {
            $initial = $this->refs($type, $this->branch->id, $rootA, $svcA);
            $id = $this->create($actor, $type, $initial);
            $this->service($type)->mutate($actor, $type, $id, 'save', $this->input($type, 1, 'bm', $initial));
            DB::statement('SET CONSTRAINTS website_shared_references_consistent IMMEDIATE');
            DB::statement('SET CONSTRAINTS website_shared_references_consistent DEFERRED');
            $r = (array) $this->current($id, 'en');
            unset($r['id']);
            $r['revision_number'] = 2;
            $r['content'] = json_encode([...json_decode($r['content'], true), ...$this->refs($type, $branch, $rootB, $svcB)]);
            try {
                DB::transaction(function () use ($r) {
                    DB::table('website_content_revisions')->insert($r);
                    DB::statement('SET CONSTRAINTS website_shared_references_consistent IMMEDIATE');
                });
                $this->fail('Divergent direct SQL accepted.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('shared references must agree', $e->getMessage());
            }
            $this->assertSame(1, $this->current($id, 'en')->revision_number);
        }
    }

    public function test_legacy_divergence_is_visible_and_next_save_converges_without_rewriting_history(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $a = $this->create($actor, 'service_category');
        $b = $this->create($actor, 'service_category');
        $id = $this->create($actor, 'public_service', ['parent_public_id' => $a]);
        $s = $this->service('public_service');
        $s->mutate($actor, 'public_service', $id, 'save', $this->input('public_service', 1, 'bm', ['parent_public_id' => $a]));
        DB::statement('SET CONSTRAINTS website_shared_references_consistent IMMEDIATE');
        DB::statement('SET CONSTRAINTS website_shared_references_consistent DEFERRED');
        DB::statement('ALTER TABLE website_content_revisions DISABLE TRIGGER website_shared_references_consistent');
        $r = (array) $this->current($id, 'bm');
        unset($r['id']);
        $r['revision_number'] = 2;
        $r['content'] = json_encode([...json_decode($r['content'], true), 'parent_public_id' => $b]);
        DB::table('website_content_revisions')->insert($r);
        DB::statement('ALTER TABLE website_content_revisions ENABLE TRIGGER website_shared_references_consistent');
        $old = $this->current($id, 'bm');
        $page = $s->page($actor, 'public_service', $id);
        $this->assertTrue($page['sharedReferencesDiverge']);
        $this->get('/website/services/'.$id)->assertOk()->assertInertia(fn ($page) => $page->where('services.sharedReferencesDiverge', true));
        $s->mutate($actor, 'public_service', $id, 'save', $this->input('public_service', 2, 'en', ['parent_public_id' => $a]));
        $this->assertFalse($s->page($actor, 'public_service', $id)['sharedReferencesDiverge']);
        $this->assertSame($a, json_decode($this->current($id, 'bm')->content, true)['parent_public_id']);
        $this->assertSame($old->content, DB::table('website_content_revisions')->where('id', $old->id)->value('content'));
    }

    public function test_service_archive_counts_active_faqs_and_dangling_legacy_links_can_be_cleared(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $service = $this->create($actor, 'public_service');
        $faq = $this->create($actor, 'faq', ['linked_service_public_id' => $service]);
        $faq2 = $this->create($actor, 'faq', ['linked_service_public_id' => $service]);
        $this->service('faq')->mutate($actor, 'faq', $faq, 'save', $this->input('faq', 1, 'bm', ['linked_service_public_id' => $service]));
        try {
            $this->service('public_service')->mutate($actor, 'public_service', $service, 'archive', $this->input('public_service', 1));
            $this->fail('Linked service archived.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('2 active FAQs', $e->errors()['archive'][0]);
        }
        $item = DB::table('website_content_items')->where('public_id', $service)->first();
        try {
            DB::transaction(fn () => DB::table('website_content_items')->where('id', $item->id)->update(['archived_at' => now(), 'lock_version' => 2]));
            $this->fail('SQL service archive bypassed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('2 active FAQs', $e->getMessage());
        }
        $this->service('faq')->mutate($actor, 'faq', $faq2, 'archive', $this->input('faq', 1));
        $this->assertSame(1, WebsiteSharedReferences::activeFaqCount($service));
        DB::statement('SET CONSTRAINTS website_shared_references_consistent IMMEDIATE');
        DB::statement('SET CONSTRAINTS website_shared_references_consistent DEFERRED');
        DB::statement('ALTER TABLE website_content_items DISABLE TRIGGER website_service_faq_archive_guard');
        DB::table('website_content_items')->where('id', $item->id)->update(['archived_at' => now(), 'lock_version' => 2]);
        DB::statement('ALTER TABLE website_content_items ENABLE TRIGGER website_service_faq_archive_guard');
        $this->assertTrue($this->service('faq')->page($actor, 'faq', $faq)['danglingServiceLink']);
        $old = $this->current($faq, 'bm');
        $this->service('faq')->mutate($actor, 'faq', $faq, 'save', $this->input('faq', 2));
        $this->assertFalse($this->service('faq')->page($actor, 'faq', $faq)['danglingServiceLink']);
        $this->assertNull(json_decode($this->current($faq, 'bm')->content, true)['linked_service_public_id']);
        $this->assertSame($old->content, DB::table('website_content_revisions')->where('id', $old->id)->value('content'));
        $this->service('public_service')->mutate($actor, 'public_service', $service, 'unarchive', $this->input('public_service', 2));
        $this->service('public_service')->mutate($actor, 'public_service', $service, 'archive', $this->input('public_service', 3));
        $this->assertSame(0, WebsiteSharedReferences::activeFaqCount($service));
    }

    public function test_coordinated_audit_failure_rolls_back_both_revisions_command_and_version_for_each_type(): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $root = $this->create($actor, 'service_category');
        $service = $this->create($actor, 'public_service');
        $branch = Branch::where('organisation_id', $actor->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail()->id;
        foreach (['doctor_profile', 'career_posting', 'service_category', 'public_service', 'promotion', 'activity', 'faq'] as $type) {
            $id = $this->create($actor, $type);
            $s = $this->service($type);
            $s->mutate($actor, $type, $id, 'save', $this->input($type, 1, 'bm'));
            $audit = \Mockery::mock(AuditRecorder::class);
            $audit->shouldReceive('record')->andReturnNull();
            $this->app->instance(AuditRecorder::class, $audit);
            $before = $this->rows();
            try {
                $this->service($type)->mutate($actor, $type, $id, 'save', $this->input($type, 2, 'en', $this->refs($type, $branch, $root, $service)));
                $this->fail('Audit failure released coordinated save.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Website audit could not be recorded.', $e->getMessage());
            }
            $this->assertSame($before, $this->rows());
            $this->app->forgetInstance(AuditRecorder::class);
        }
    }

    public function test_people_archive_and_unarchive_use_explicit_redirect_even_with_unrelated_referer(): void
    {
        $actor = $this->actor('hr_manager');
        $this->selectBranch($actor);
        foreach (['doctor_profile' => 'doctor-profiles', 'career_posting' => 'careers'] as $type => $path) {
            $id = $this->create($actor, $type);
            foreach (['archive' => 1, 'unarchive' => 2] as $op => $v) {
                $this->from('/dashboard')->post('/website/'.$path.'/'.$id.'/'.$op, $this->input($type, $v))->assertRedirect('/website/'.$path.'/'.$id);
            }
        }
    }

    public function test_coordinated_copy_keeps_other_language_retired_media_without_reattaching_it(): void
    {
        Storage::fake('website_media');
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $branch = Branch::where('organisation_id', $actor->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail()->id;
        foreach (['public_service', 'promotion', 'activity'] as $type) {
            app(WebsiteDraftService::class)->upload($actor, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'alt_en' => 'Synthetic media', 'alt_bm' => 'Imej sintetik', 'rights_reference' => 'Synthetic rights', 'identifiable_person' => false, 'consent_reference' => ''], UploadedFile::fake()->image('synthetic.png', 20, 20));
            $asset = DB::table('website_media_assets')->orderByDesc('id')->first();
            $id = $this->create($actor, $type);
            $cmd = $this->input($type, 1, 'bm');
            $cmd['content']['cover_public_id'] = $asset->public_id;
            $this->service($type)->mutate($actor, $type, $id, 'save', $cmd);
            $old = $this->current($id, 'bm');
            app(WebsiteDraftService::class)->retire($actor, $asset->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
            $this->service($type)->mutate($actor, $type, $id, 'save', $this->input($type, 2, 'en', ['_branch_ids' => [$branch]]));
            $new = $this->current($id, 'bm');
            $this->assertSame($old->hero_asset_id, $new->hero_asset_id);
            $this->assertSame($asset->public_id, json_decode($new->content, true)['cover_public_id']);
            $this->refuse(fn () => $this->service($type)->mutate($actor, $type, $id, 'save', $this->input($type, 3, 'en', ['_branch_ids' => [$branch], 'cover_public_id' => $asset->public_id])));
        }
    }
}
