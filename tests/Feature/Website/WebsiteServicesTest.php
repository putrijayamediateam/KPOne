<?php

namespace Tests\Feature\Website;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Website\WebsiteAccess;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsitePeopleFields;
use App\Domain\Organisation\Website\WebsitePeopleService;
use App\Domain\Organisation\Website\WebsitePermissions as P;
use App\Domain\Organisation\Website\WebsiteServiceFields as F;
use App\Domain\Organisation\Website\WebsiteServicesService as S;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Billing\BillingTestCase;

class WebsiteServicesTest extends BillingTestCase
{
    private function input(string $type = 'public_service', array $extra = []): array
    {
        return array_replace(['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'language' => 'en', 'content' => F::empty($type), 'branch_ids' => $type === 'public_service' ? [$this->branch->id] : []], $extra);
    }

    private function refuse(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation refusal.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function create(User $actor, string $type = 'service_category', ?string $parent = null): string
    {
        $input = $this->input($type);
        $input['content']['name'] = 'Synthetic manual '.$type;
        $input['content']['parent_public_id'] = $parent;

        return app(S::class)->mutate($actor, $type, null, 'save', $input);
    }

    public function test_every_catalogue_role_exact_existing_grants_and_all_route_denials(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        foreach (['service_category' => 'service-categories', 'public_service' => 'services'] as $type => $path) {
            $id = $this->create($director, $type);
            foreach (array_keys(PermissionCatalogue::roles()) as $role) {
                $actor = $this->actor($role);
                $this->selectBranch($actor);
                $view = in_array($role, ['director', 'marketing', 'hr_manager', 'administrator'], true);
                $manage = in_array($role, ['director', 'marketing', 'administrator'], true);
                $this->assertSame($view, $actor->can(P::VIEW));
                $this->assertSame($manage, $actor->can(P::MANAGE));
                $this->assertSame($view ? 200 : 403, $this->get('/website/'.$path)->status(), $role.' list');
                $this->assertSame($view ? 200 : 403, $this->get('/website/'.$path.'/'.$id)->status(), $role.' detail');
                $this->assertSame($manage ? 200 : 403, $this->get('/website/'.$path.'/create')->status(), $role.' create');
                if (! $manage) {
                    foreach (['', '/'.$id, '/'.$id.'/archive', '/'.$id.'/unarchive'] as $suffix) {
                        $this->post('/website/'.$path.$suffix, $this->input($type))->assertForbidden();
                    }
                }
            }
            foreach (['director', 'marketing', 'administrator'] as $role) {
                $actor = $this->actor($role);
                $this->selectBranch($actor);
                $input = $this->input($type);
                $this->post('/website/'.$path, $input)->assertRedirect();
                $own = DB::table('website_content_items')->where('type', $type)->orderByDesc('id')->first();
                $this->post('/website/'.$path.'/'.$own->public_id, $this->input($type, ['lock_version' => 1]))->assertRedirect('/website/'.$path.'/'.$own->public_id);
            }
        }
        $this->app['auth']->forgetGuards();
        $this->get('/website/services')->assertRedirect('/login');
    }

    public function test_language_snapshots_retry_versions_archives_history_filters_and_stable_slug(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $service = app(S::class);
        $root = $this->create($actor);
        $sub = $this->create($actor, parent: $root);
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $input = $this->input();
        $input['content'] = [...$input['content'], 'name' => 'Synthetic searchable service', 'short_summary' => 'Manual summary', 'description_sections' => [['heading' => 'Saved heading', 'body' => 'Saved body']], 'parent_public_id' => $sub, 'seo_title' => 'Manual SEO', 'seo_description' => 'Manual description'];
        $id = $service->mutate($actor, 'public_service', null, 'save', $input);
        $this->assertSame($id, $service->mutate($actor, 'public_service', null, 'save', $input));
        $page = $service->page($actor, 'public_service', $id);
        $slug = $page['item']['slug'];
        $this->assertSame([], $page['drafts']['en']['missing']);
        $this->assertSame(0, $page['drafts']['bm']['number']);
        $this->assertCount(1, $page['history']);
        $conflict = $input;
        $conflict['content']['name'] = 'Different';
        $this->refuse(fn () => $service->mutate($actor, 'public_service', null, 'save', $conflict));
        $this->refuse(fn () => $service->mutate($actor, 'public_service', $id, 'save', $this->input()));
        $bm = $this->input(extra: ['language' => 'bm', 'lock_version' => 1, 'branch_ids' => [$other->id]]);
        $bm['content']['name'] = 'Perkhidmatan sintetik';
        $service->mutate($actor, 'public_service', $id, 'save', $bm);
        $page = $service->page($actor, 'public_service', $id);
        $this->assertSame('Synthetic searchable service', $page['drafts']['en']['content']['name']);
        $this->assertSame([$this->branch->id], $page['drafts']['en']['branchIds']);
        $this->assertSame([$other->id], $page['drafts']['bm']['branchIds']);
        $this->assertSame(1, $page['drafts']['bm']['number']);
        $this->assertSame($slug, $page['item']['slug']);
        $this->assertCount(1, $service->page($actor, 'public_service', null, ['search' => 'searchable', 'branch_id' => $other->id, 'category_id' => $sub])['items']);
        foreach (['archive' => 2, 'unarchive' => 3] as $operation => $version) {
            $data = $this->input(extra: ['lock_version' => $version]);
            $service->mutate($actor, 'public_service', $id, $operation, $data);
            $this->assertSame($id, $service->mutate($actor, 'public_service', $id, $operation, $data));
            $this->assertSame($operation === 'archive', $service->page($actor, 'public_service', $id)['item']['archived']);
            $this->assertSame(2, DB::table('website_content_revisions')->where('item_id', DB::table('website_content_items')->where('public_id', $id)->value('id'))->count());
        }
        $this->assertSame($slug, $service->page($actor, 'public_service', $id)['item']['slug']);
        for ($version = 4; $version < 17; $version++) {
            $service->mutate($actor, 'public_service', $id, 'save', $this->input(extra: ['lock_version' => $version]));
        }
        $this->assertCount(12, $service->page($actor, 'public_service', $id)['history']);
        $this->assertCount(3, $service->page($actor, 'public_service', $id, ['history_page' => 2])['history']);
    }

    public function test_all_text_and_list_budgets_markup_controls_and_completeness(): void
    {
        foreach (F::TYPES as $type) {
            $base = F::empty($type);
            foreach ($type === 'service_category' ? F::CATEGORY_TEXT : F::SERVICE_TEXT as $field => $limit) {
                $valid = $base;
                $valid[$field] = str_repeat('a', $limit);
                if ($field === 'price_text') {
                    $valid['price_display_reference'] = 'Synthetic approval';
                }
                $this->assertSame($valid[$field], F::validate($type, $valid)[$field]);
                $valid[$field] .= 'a';
                $this->refuse(fn () => F::validate($type, $valid));
                foreach (['<script>x</script>', '**markup**', "\nleading", "x\0", "x\u{202E}", "x\u{200B}", "x\u{2066}"] as $value) {
                    $invalid = $base;
                    $invalid[$field] = $value;
                    $this->refuse(fn () => F::validate($type, $invalid));
                }
            }
            $invalid = $base;
            $invalid['unexpected'] = 'x';
            $this->refuse(fn () => F::validate($type, $invalid));
            $this->refuse(fn () => F::validate($type, [...$base, 'sort_order' => 100001]));
            $this->refuse(fn () => F::validate($type, [...$base, 'include_on_website' => 'yes']));
            $this->assertNotEmpty(F::missing($type, $base));
        }
        foreach (F::LISTS as $field => [$count, $limit]) {
            $valid = F::empty('public_service');
            $valid[$field] = array_fill(0, $count, str_repeat('a', $limit));
            $this->assertCount($count, F::validate('public_service', $valid)[$field]);
            $invalid = $valid;
            $invalid[$field][] = 'extra';
            $this->refuse(fn () => F::validate('public_service', $invalid));
            $invalid = $valid;
            $invalid[$field][0] .= 'x';
            $this->refuse(fn () => F::validate('public_service', $invalid));
            $invalid = $valid;
            $invalid[$field][0] = "x\u{200B}";
            $this->refuse(fn () => F::validate('public_service', $invalid));
        }
        $base = F::empty('public_service');
        $base['description_sections'] = array_fill(0, 8, ['heading' => str_repeat('h', 100), 'body' => str_repeat('b', 1500)]);
        $this->assertCount(8, F::validate('public_service', $base)['description_sections']);
        foreach ([['heading', 101], ['body', 1501]] as [$field, $length]) {
            $bad = $base;
            $bad['description_sections'][0][$field] = str_repeat('x', $length);
            $this->refuse(fn () => F::validate('public_service', $bad));
        }
        $base['description_sections'][] = ['heading' => 'ninth', 'body' => 'extra'];
        $this->refuse(fn () => F::validate('public_service', $base));
        $this->refuse(fn () => F::validate('public_service', [...F::empty('public_service'), 'price_text' => 'From RM 50']));
        $this->assertSame('From RM 50', F::validate('public_service', [...F::empty('public_service'), 'price_text' => 'From RM 50', 'price_display_reference' => 'Synthetic owner approval'])['price_text']);
        $this->assertContains('branch_ids', F::missing('public_service', F::empty('public_service')));
    }

    public function test_hierarchy_and_archive_guards_including_each_language(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $root = $this->create($actor);
        $sub = $this->create($actor, parent: $root);
        $service = $this->create($actor, 'public_service', $sub);
        $this->refuse(fn () => $this->create($actor, parent: $sub));
        $this->refuse(fn () => $this->create($actor, parent: $service));
        $this->refuse(fn () => $s->mutate($actor, 'service_category', $root, 'archive', $this->input('service_category', ['lock_version' => 1])));
        $same = $this->input('service_category', ['lock_version' => 1]);
        $same['content']['parent_public_id'] = $root;
        $this->refuse(fn () => $s->mutate($actor, 'service_category', $root, 'save', $same));
        $otherRoot = $this->create($actor);
        $move = $this->input('service_category', ['lock_version' => 1, 'language' => 'bm']);
        $move['content']['parent_public_id'] = $otherRoot;
        $this->refuse(fn () => $s->mutate($actor, 'service_category', $root, 'save', $move));
        $s->mutate($actor, 'public_service', $service, 'archive', $this->input(extra: ['lock_version' => 1]));
        $s->mutate($actor, 'service_category', $sub, 'archive', $this->input('service_category', ['lock_version' => 1]));
        $this->refuse(fn () => $this->create($actor, 'public_service', $sub));
        $this->refuse(fn () => $s->mutate($actor, 'public_service', $service, 'unarchive', $this->input(extra: ['lock_version' => 2])));
        $s->mutate($actor, 'service_category', $root, 'archive', $this->input('service_category', ['lock_version' => 1]));
        $this->refuse(fn () => $s->mutate($actor, 'service_category', $sub, 'unarchive', $this->input('service_category', ['lock_version' => 2])));
        $s->mutate($actor, 'service_category', $root, 'unarchive', $this->input('service_category', ['lock_version' => 2]));
        $s->mutate($actor, 'service_category', $sub, 'unarchive', $this->input('service_category', ['lock_version' => 2]));
        $s->mutate($actor, 'public_service', $service, 'unarchive', $this->input(extra: ['lock_version' => 2]));
        $this->assertCount(1, $s->page($actor, 'public_service', null)['items']);
    }

    public function test_branch_parent_organisation_and_fresh_authority_refusals(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $id = $this->create($actor, 'public_service');
        $foreign = Organisation::query()->create(['code' => 'SYNTHETIC_W1C', 'name' => 'Synthetic foreign organisation']);
        $branch = Branch::query()->forceCreate(['organisation_id' => $foreign->id, 'code' => 'SYN', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $other = $this->actor('director', $branch);
        $this->selectBranch($other, $branch);
        $parent = $s->mutate($other, 'service_category', null, 'save', $this->input('service_category', ['expected_branch_id' => $branch->id]));
        $this->selectBranch($actor);
        $this->refuse(fn () => $s->mutate($actor, 'public_service', $id, 'save', $this->input(extra: ['lock_version' => 1, 'branch_ids' => [$branch->id]])));
        $bad = $this->input();
        $bad['content']['parent_public_id'] = $parent;
        $this->refuse(fn () => $s->mutate($actor, 'public_service', null, 'save', $bad));
        $this->get('/website/service-categories/'.$parent)->assertNotFound();
        $this->get('/website/services?branch_id='.$branch->id)->assertForbidden();
        $this->refuse(fn () => $s->mutate($actor, 'service_category', null, 'save', $this->input('service_category', ['branch_ids' => [$this->branch->id]])));
        $role = Role::findByName('marketing');
        $role->revokePermissionTo(P::MANAGE);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->post('/website/services', $this->input())->assertForbidden();
        $role->givePermissionTo(P::MANAGE);
        $role->revokePermissionTo(P::VIEW);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->get('/website/services')->assertForbidden();
    }

    private function asset(User $actor, bool $person = false): \stdClass
    {
        Storage::fake('website_media');
        app(WebsiteDraftService::class)->upload($actor, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'alt_en' => 'Synthetic cover', 'alt_bm' => 'Imej sintetik', 'rights_reference' => 'Synthetic illustration', 'identifiable_person' => $person, 'consent_reference' => $person ? 'Synthetic consent reference' : ''], UploadedFile::fake()->image('synthetic.png', 80, 60));

        return DB::table('website_media_assets')->orderByDesc('id')->first();
    }

    public function test_cover_gallery_limits_order_consent_retirement_and_snapshot_preservation(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $a = $this->asset($actor);
        $b = $this->asset($actor, true);
        $input = $this->input();
        $input['content']['cover_public_id'] = $a->public_id;
        $input['content']['gallery_public_ids'] = [$b->public_id, $a->public_id];
        $maximum = F::empty('public_service');
        $maximum['gallery_public_ids'] = array_map(fn () => (string) Str::uuid(), range(1, 12));
        $this->assertCount(12, F::validate('public_service', $maximum)['gallery_public_ids']);
        $missingConsent = (array) $b;
        unset($missingConsent['id']);
        $missingConsent['public_id'] = (string) Str::uuid();
        $missingConsent['path'] = 'synthetic-missing-consent.png';
        $missingConsent['consent_reference'] = '';
        DB::table('website_media_assets')->insert($missingConsent);
        $badConsent = $input;
        $badConsent['content']['cover_public_id'] = $missingConsent['public_id'];
        $this->refuse(fn () => $s->mutate($actor, 'public_service', null, 'save', $badConsent));
        $foreignOrg = Organisation::query()->create(['code' => 'SYNTHETIC_MEDIA', 'name' => 'Synthetic media organisation']);
        $foreignAsset = (array) $a;
        unset($foreignAsset['id']);
        $foreignAsset['public_id'] = (string) Str::uuid();
        $foreignAsset['organisation_id'] = $foreignOrg->id;
        $foreignAsset['path'] = 'synthetic-foreign-media.png';
        DB::table('website_media_assets')->insert($foreignAsset);
        $badForeign = $input;
        $badForeign['content']['gallery_public_ids'] = [$foreignAsset['public_id']];
        $this->refuse(fn () => $s->mutate($actor, 'public_service', null, 'save', $badForeign));
        $id = $s->mutate($actor, 'public_service', null, 'save', $input);
        $this->assertSame([$b->public_id, $a->public_id], $s->page($actor, 'public_service', $id)['drafts']['en']['content']['gallery_public_ids']);
        $bad = $input;
        $bad['content']['gallery_public_ids'] = array_fill(0, 13, $a->public_id);
        $this->refuse(fn () => F::validate('public_service', $bad['content']));
        $bad['content']['gallery_public_ids'] = [$a->public_id, $a->public_id];
        $this->refuse(fn () => F::validate('public_service', $bad['content']));
        $bad['content']['gallery_public_ids'] = [(string) Str::uuid()];
        $this->refuse(fn () => $s->mutate($actor, 'public_service', null, 'save', $bad));
        $bad = $input;
        $bad['content']['cover_public_id'] = (string) Str::uuid();
        $this->refuse(fn () => $s->mutate($actor, 'public_service', null, 'save', $bad));
        app(WebsiteDraftService::class)->retire($actor, $a->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame($id, $s->mutate($actor, 'public_service', null, 'save', $input));
        $newAttachment = $input;
        $newAttachment['idempotency_key'] = (string) Str::uuid();
        $this->refuse(fn () => $s->mutate($actor, 'public_service', null, 'save', $newAttachment));
        $this->assertSame($a->public_id, $s->page($actor, 'public_service', $id)['history'][0]['content']['cover_public_id']);
        $this->refuse(fn () => F::validate('service_category', [...F::empty('service_category'), 'gallery_public_ids' => [$b->public_id]]));
        $role = Role::findByName('marketing');
        $role->revokePermissionTo(P::ASSETS);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->post('/website/services', $this->input(extra: ['content' => [...F::empty('public_service'), 'cover_public_id' => $b->public_id]]))->assertForbidden();
    }

    public function test_postgresql_guards_and_existing_branch_profiles_preserved(): void
    {
        $before = DB::table('website_content_items')->where('type', 'branch_public_profile')->orderBy('id')->get()->toJson();
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $root = $this->create($actor);
        $sub = $this->create($actor, parent: $root);
        $id = $this->create($actor, 'public_service', $sub);
        $item = DB::table('website_content_items')->where('public_id', $id)->first();
        $rootItem = DB::table('website_content_items')->where('public_id', $root)->first();
        foreach ([fn () => DB::table('website_content_revisions')->where('item_id', $item->id)->update(['content' => '{}']), fn () => DB::table('website_content_revisions')->where('item_id', $item->id)->delete(), fn () => DB::statement('TRUNCATE website_content_revisions'), fn () => DB::table('website_content_items')->where('id', $item->id)->update(['slug' => 'changed', 'lock_version' => 2]), fn () => DB::table('website_content_items')->where('id', $rootItem->id)->update(['archived_at' => now(), 'lock_version' => 2]), fn () => DB::table('website_content_items')->where('id', $item->id)->update(['type' => 'promotion', 'lock_version' => 2]), fn () => DB::table('website_content_item_branches')->insert(['item_id' => $rootItem->id, 'organisation_id' => $actor->organisation_id, 'branch_id' => $this->branch->id])] as $action) {
            try {
                DB::transaction($action);
                $this->fail('Database accepted invalid mutation.');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertSame($before, DB::table('website_content_items')->where('type', 'branch_public_profile')->orderBy('id')->get()->toJson());
    }

    public function test_database_refuses_invalid_new_hierarchy_and_child_unarchive_without_active_parent(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $root = $this->create($actor);
        $sub = $this->create($actor, parent: $root);
        $leaf = $this->create($actor, 'public_service', $sub);
        $rootItem = DB::table('website_content_items')->where('public_id', $root)->first();
        $subItem = DB::table('website_content_items')->where('public_id', $sub)->first();
        foreach ([$root, $sub, $leaf, (string) Str::uuid()] as $parent) {
            $itemId = $parent === $root ? $rootItem->id : $subItem->id;
            try {
                DB::transaction(fn () => DB::table('website_content_revisions')->insert(['organisation_id' => $actor->organisation_id, 'item_id' => $itemId, 'language' => 'bm', 'revision_number' => 1, 'content' => json_encode([...F::empty('service_category'), 'parent_public_id' => $parent]), 'is_seed_copy' => false, 'created_by_user_id' => $actor->id, 'created_at' => now()]));
                $this->fail('Database accepted invalid category hierarchy.');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        app(S::class)->mutate($actor, 'public_service', $leaf, 'archive', $this->input(extra: ['lock_version' => 1]));
        app(S::class)->mutate($actor, 'service_category', $sub, 'archive', $this->input('service_category', ['lock_version' => 1]));
        app(S::class)->mutate($actor, 'service_category', $root, 'archive', $this->input('service_category', ['lock_version' => 1]));
        try {
            DB::transaction(fn () => DB::table('website_content_items')->where('id', $subItem->id)->update(['archived_at' => null, 'lock_version' => 3]));
            $this->fail('Database accepted child unarchive under an archived parent.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_category_actions_preserve_all_unrelated_rows_and_direct_grant_revocation_is_fresh(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $before = $this->rows(true);
        $id = $this->create($actor);
        $this->assertSame($before, $this->rows(true));
        app(S::class)->page($actor, 'service_category', $id);
        $this->assertSame($before, $this->rows(true));
        app(S::class)->mutate($actor, 'service_category', $id, 'save', $this->input('service_category', ['lock_version' => 1, 'language' => 'bm']));
        $this->assertSame($before, $this->rows(true));
        foreach (['archive' => 2, 'unarchive' => 3] as $operation => $version) {
            app(S::class)->mutate($actor, 'service_category', $id, $operation, $this->input('service_category', ['lock_version' => $version]));
            $this->assertSame($before, $this->rows(true));
        }
        $actor->load('roles.permissions');
        $role = Role::findByName('marketing');
        $permissionId = DB::table('permissions')->where('name', P::MANAGE)->value('id');
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permissionId)->delete();
        $this->post('/website/service-categories', $this->input('service_category'))->assertForbidden();
    }

    public function test_uuid_case_cannot_bypass_hierarchy_guards_or_media_resolution(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $root = $this->create($actor);
        $asset = $this->asset($actor);
        $input = $this->input();
        $input['content']['parent_public_id'] = strtoupper($root);
        $input['content']['cover_public_id'] = strtoupper($asset->public_id);
        $input['content']['gallery_public_ids'] = [strtoupper($asset->public_id)];
        $id = app(S::class)->mutate($actor, 'public_service', null, 'save', $input);
        $page = app(S::class)->page($actor, 'public_service', strtoupper($id), ['category_id' => strtoupper($root)]);
        $this->assertSame($root, $page['drafts']['en']['content']['parent_public_id']);
        $this->assertSame($asset->public_id, $page['drafts']['en']['content']['cover_public_id']);
        $this->assertSame([$asset->public_id], $page['drafts']['en']['content']['gallery_public_ids']);
        app(S::class)->mutate($actor, 'public_service', $id, 'save', $this->input(extra: ['lock_version' => 1]));
        $item = DB::table('website_content_items')->where('public_id', $id)->first();
        DB::table('website_content_revisions')->insert(['organisation_id' => $actor->organisation_id, 'item_id' => $item->id, 'language' => 'bm', 'revision_number' => 1, 'content' => json_encode([...F::empty('public_service'), 'parent_public_id' => strtoupper($root)]), 'is_seed_copy' => false, 'created_by_user_id' => $actor->id, 'created_at' => now()]);
        $rootItem = DB::table('website_content_items')->where('public_id', $root)->first();
        try {
            DB::transaction(fn () => DB::table('website_content_items')->where('id', $rootItem->id)->update(['archived_at' => now(), 'lock_version' => 2]));
            $this->fail('Uppercase parent reference bypassed active-child guard.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function rows(bool $unrelated = false): array
    {
        $out = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $row) {
            if ($unrelated && $row->tablename === 'audit_logs') {
                continue;
            }
            $q = DB::table($row->tablename);
            if ($unrelated && $row->tablename === 'website_content_items') {
                $q->whereNotIn('type', F::TYPES);
            }
            if ($unrelated && in_array($row->tablename, ['website_content_revisions', 'website_content_item_branches'], true)) {
                $q->whereNotIn('item_id', DB::table('website_content_items')->select('id')->whereIn('type', F::TYPES));
            }
            if ($unrelated && $row->tablename === 'website_draft_commands') {
                $q->where('operation', 'not like', 'services_%');
            }
            $columns = Schema::getColumnListing($row->tablename);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $c) {
                $q->orderBy($c);
            }
            $out[$row->tablename] = $q->get()->toJson();
        }

        return $out;
    }

    public function test_no_operational_pricing_staff_sources_no_network_jobs_and_all_unrelated_rows_identical(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->finalizedFixture();
        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        foreach (['doctor_profile', 'career_posting'] as $peopleType) {
            $peopleContent = WebsitePeopleFields::empty($peopleType);
            if ($peopleType === 'career_posting') {
                $peopleContent['apply_email'] = 'careers@synthetic.test';
            }
            app(WebsitePeopleService::class)->mutate($hr, $peopleType, null, 'save', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'language' => 'en', 'content' => $peopleContent, 'branch_ids' => [$this->branch->id], 'portrait_public_id' => null]);
        }
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        DB::enableQueryLog();
        DB::flushQueryLog();
        DB::transaction(fn () => app(WebsiteAccess::class)->lock($actor, $this->branch->id, P::MANAGE));
        $authority = array_map(fn ($q) => [$q['query'], $q['bindings']], DB::getQueryLog());
        DB::disableQueryLog();
        $before = $this->rows(true);
        $id = null;
        $data = $this->input();
        $data['content']['price_text'] = 'MANUAL PRICE SENTINEL';
        $data['content']['price_display_reference'] = 'PRIVATE APPROVAL SENTINEL';
        $actions = [function () use ($actor, $data, &$id) {
            $id = app(S::class)->mutate($actor, 'public_service', null, 'save', $data);
        }, function () use ($actor, &$id) {
            return app(S::class)->page($actor, 'public_service', $id);
        }, function () use ($actor, &$id) {
            return app(S::class)->mutate($actor, 'public_service', $id, 'save', $this->input(extra: ['lock_version' => 1]));
        }, function () use ($actor, &$id) {
            return app(S::class)->mutate($actor, 'public_service', $id, 'archive', $this->input(extra: ['lock_version' => 2]));
        }, function () use ($actor, &$id) {
            return app(S::class)->mutate($actor, 'public_service', $id, 'unarchive', $this->input(extra: ['lock_version' => 3]));
        }, function () use (&$id) {
            $this->get('/website/services/'.$id)->assertOk();
        }];
        foreach ($actions as $action) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $action();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            foreach ($queries as $q) {
                $this->assertDoesNotMatchRegularExpression('/(?:from|join|into|update)\s+"?(?:public_patient_intakes|public_intake_[a-z_]+|patients|patient_[a-z_]+|visits|visit_[a-z_]+|queue_[a-z_]+|clinical_[a-z_]+|encounters|treatment_[a-z_]+|invoices|invoice_[a-z_]+|payments|payment_[a-z_]+|dispensary_[a-z_]+|stock_[a-z_]+|inventory_[a-z_]+|medicine[a-z_]*|charge_definitions|price_[a-z_]+|clinician_credentials|staff_schedules|staff_rosters)"?\b/i', $q['query']);
                if (preg_match('/(?:from|join)\s+"?(?:users|staff_profiles|staff_branch_assignments)"?\b/i', $q['query'])) {
                    $this->assertContains([$q['query'], $q['bindings']], $authority);
                }
            }
            $this->assertSame($before, $this->rows(true));
        }
        foreach (DB::table('audit_logs')->where('event', 'like', 'website.services.%')->get() as $audit) {
            $this->assertStringNotContainsString('SENTINEL', $audit->metadata);
            $this->assertSame(['field_names', 'item_id', 'language', 'revision_number', 'version'], collect(array_keys(json_decode($audit->metadata, true)))->sort()->values()->all());
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_audit_failure_rolls_back_every_mutation_and_http_preserves_control_refusal(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $id = $this->create($actor, 'public_service');
        $archived = $this->create($actor, 'public_service');
        app(S::class)->mutate($actor, 'public_service', $archived, 'archive', $this->input(extra: ['lock_version' => 1]));
        $bad = $this->input();
        $bad['content']['name'] = "\nmalformed";
        $this->post('/website/services', $bad)->assertSessionHasErrors('content.name');
        $audit = \Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->andReturnNull();
        $this->app->instance(AuditRecorder::class, $audit);
        foreach ([['save', null, 0], ['save', $id, 1], ['archive', $id, 1], ['unarchive', $archived, 2]] as [$operation, $target, $version]) {
            $before = $this->rows();
            try {
                app(S::class)->mutate($actor, 'public_service', $target, $operation, $this->input(extra: ['lock_version' => $version]));
                $this->fail('Audit failure released mutation.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Website audit could not be recorded.', $e->getMessage());
            }
            $this->assertSame($before, $this->rows());
        }
    }
}
