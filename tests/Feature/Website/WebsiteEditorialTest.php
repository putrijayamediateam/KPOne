<?php

namespace Tests\Feature\Website;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Website\WebsiteAccess;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsiteEditorialFields as F;
use App\Domain\Organisation\Website\WebsiteEditorialService as S;
use App\Domain\Organisation\Website\WebsitePeopleFields;
use App\Domain\Organisation\Website\WebsitePeopleService;
use App\Domain\Organisation\Website\WebsitePermissions as P;
use App\Domain\Organisation\Website\WebsiteServiceFields;
use App\Domain\Organisation\Website\WebsiteServicesService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Billing\BillingTestCase;

class WebsiteEditorialTest extends BillingTestCase
{
    private function input(string $type = 'promotion', array $extra = []): array
    {
        return array_replace(['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'language' => 'en', 'content' => F::empty($type), 'branch_ids' => $type !== 'faq' ? [$this->branch->id] : []], $extra);
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

    private function create(User $actor, string $type = 'promotion'): string
    {
        $input = $this->input($type);
        $input['content'][$type === 'faq' ? 'question' : 'title'] = 'Synthetic manual '.$type;

        return app(S::class)->mutate($actor, $type, null, 'save', $input);
    }

    public function test_every_catalogue_role_exact_existing_grants_and_all_route_denials(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        foreach (['promotion' => 'promotions', 'activity' => 'activities', 'faq' => 'faqs'] as $type => $path) {
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
        $this->get('/website/promotions')->assertRedirect('/login');
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
                $q->where('operation', 'not like', 'editorial_%');
            }
            $columns = Schema::getColumnListing($row->tablename);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $c) {
                $q->orderBy($c);
            }
            $out[$row->tablename] = $q->get()->toJson();
        }

        return $out;
    }

    public function test_independent_language_text_consistent_item_references_retries_archives_and_history(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        foreach (F::TYPES as $type) {
            $input = $this->input($type);
            $id = $s->mutate($actor, $type, null, 'save', $input);
            $this->assertSame($id, $s->mutate($actor, $type, null, 'save', $input));
            $bad = $input;
            $bad['content']['sort_order'] = 2;
            $this->refuse(fn () => $s->mutate($actor, $type, null, 'save', $bad));
            $this->refuse(fn () => $s->mutate($actor, $type, $id, 'save', $this->input($type)));
            $page = $s->page($actor, $type, $id);
            $slug = $page['item']['slug'];
            $this->assertNotEmpty($page['drafts']['en']['missing']);
            $bm = $this->input($type, ['language' => 'bm', 'lock_version' => 1]);
            $bm['content'][$type === 'faq' ? 'question' : 'title'] = 'Teks sintetik';
            $s->mutate($actor, $type, $id, 'save', $bm);
            $page = $s->page($actor, $type, $id);
            $this->assertSame('Teks sintetik', $page['drafts']['bm']['content'][$type === 'faq' ? 'question' : 'title']);
            $this->assertSame('', $page['drafts']['en']['content'][$type === 'faq' ? 'question' : 'title']);
            foreach (['archive' => 2, 'unarchive' => 3] as $op => $v) {
                $cmd = $this->input($type, ['lock_version' => $v]);
                $s->mutate($actor, $type, $id, $op, $cmd);
                $this->assertSame($id, $s->mutate($actor, $type, $id, $op, $cmd));
                $this->assertSame($op === 'archive', $s->page($actor, $type, $id)['item']['archived']);
            }
            for ($v = 4; $v < 17; $v++) {
                $s->mutate($actor, $type, $id, 'save', $this->input($type, ['lock_version' => $v]));
            }
            $page = $s->page($actor, $type, $id);
            $this->assertSame($slug, $page['item']['slug']);
            $this->assertCount(12, $page['history']);
            $this->assertCount(3, $s->page($actor, $type, $id, ['history_page' => 2])['history']);
            $this->assertCount(1, $s->page($actor, $type, null, ['search' => 'sintetik'])['items']);
        }
    }

    public function test_every_text_section_list_date_flag_and_completeness_budget(): void
    {
        foreach (F::TYPES as $type) {
            $base = F::empty($type);
            foreach (F::TEXT[$type] as $key => $limit) {
                $valid = [...$base, $key => str_repeat('x', $limit)];
                if ($key === 'offer_text') {
                    $valid['offer_display_reference'] = 'Synthetic approval';
                }
                $this->assertSame(str_repeat('x', $limit), F::validate($type, $valid)[$key]);
                $this->refuse(fn () => F::validate($type, [...$valid, $key => str_repeat('x', $limit + 1)]));
                foreach (['<b>bad</b>', '**bad**', "\nraw", "\u{202e}bad", "\u{200b}bad", "\0bad", "bad\xFF"] as $bad) {
                    $this->refuse(fn () => F::validate($type, [...$base, $key => $bad]));
                }
            }
            $this->refuse(fn () => F::validate($type, [...$base, 'tracking_code' => 'bad']));
            $this->refuse(fn () => F::validate($type, [...$base, 'sort_order' => 100001]));
            $this->refuse(fn () => F::validate($type, [...$base, 'include_on_website' => 'bad']));
            if ($type !== 'faq') {
                $sections = array_fill(0, 6, ['heading' => str_repeat('h', 100), 'body' => str_repeat('b', 1500)]);
                $this->assertCount(6, F::validate($type, [...$base, 'description_sections' => $sections])['description_sections']);
                foreach ([array_fill(0, 7, ['heading' => 'h', 'body' => 'b']), [['heading' => str_repeat('h', 101), 'body' => 'b']], [['heading' => 'h', 'body' => str_repeat('b', 1501)]], [['heading' => 'h', 'body' => '<i>bad</i>']], [['heading' => 'h', 'body' => 'b', 'unknown' => 'x']]] as $bad) {
                    $this->refuse(fn () => F::validate($type, [...$base, 'description_sections' => $bad]));
                }
                [$start,$end] = F::DATES[$type];
                $valid = [...$base, $start => '2026-12-31', $end => '2027-01-01'];
                $this->assertSame('2027-01-01', F::validate($type, $valid)[$end]);
                $this->assertSame('2026-12-31', F::validate($type, [...$valid, $end => '2026-12-31'])[$end]);
                foreach (['2026-02-30', '22/10/2026', '2026-1-01', '2026-10-22T00:00:00Z', "2026-01-01\n"] as $bad) {
                    $this->refuse(fn () => F::validate($type, [...$base, $start => $bad]));
                }
                $this->refuse(fn () => F::validate($type, [...$valid, $end => '2026-12-30']));
                $complete = [...$base, 'title' => 'Title', 'short_summary' => 'Summary', 'description_sections' => [['heading' => 'Heading', 'body' => 'Body']]];
                $this->assertSame([], F::missing($type, $complete, [$this->branch->id]));
                $this->assertContains('branch_ids', F::missing($type, $complete));
            } else {
                $this->assertSame([], F::missing('faq', [...$base, 'question' => 'Question', 'answer' => 'Answer']));
            }
        }
        $base = F::empty('promotion');
        $this->refuse(fn () => F::validate('promotion', [...$base, 'offer_text' => 'Manual offer']));
        $this->assertCount(8, F::validate('promotion', [...$base, 'terms' => array_fill(0, 8, str_repeat('t', 200))])['terms']);
        foreach ([array_fill(0, 9, 'term'), [str_repeat('t', 201)], ['**bad**'], ['bad' => 'term']] as $terms) {
            $this->refuse(fn () => F::validate('promotion', [...$base, 'terms' => $terms]));
        }
    }

    private function publicService(User $actor): string
    {
        return app(WebsiteServicesService::class)->mutate($actor, 'public_service', null, 'save', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'language' => 'en', 'content' => WebsiteServiceFields::empty('public_service'), 'branch_ids' => [$this->branch->id]]);
    }

    public function test_item_wide_branch_and_faq_service_references_coordinate_language_changes(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $branch = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        foreach (['promotion', 'activity'] as $type) {
            $id = $this->create($actor, $type);
            $bm = $this->input($type, ['language' => 'bm', 'lock_version' => 1, 'branch_ids' => [$branch->id]]);
            $s->mutate($actor, $type, $id, 'save', $bm);
            $this->assertSame([$branch->id], $s->page($actor, $type, $id)['drafts']['en']['branchIds']);
            $this->assertSame([$branch->id], $s->page($actor, $type, $id)['drafts']['bm']['branchIds']);
            $s->mutate($actor, $type, $id, 'save', $this->input($type, ['lock_version' => 2, 'branch_ids' => []]));
            $this->assertSame([], $s->page($actor, $type, $id)['drafts']['en']['branchIds']);
            $this->assertSame([], $s->page($actor, $type, $id)['drafts']['bm']['branchIds']);
        }
        $linked = $this->publicService($actor);
        $input = $this->input('faq');
        $input['content']['linked_service_public_id'] = strtoupper($linked);
        $id = $s->mutate($actor, 'faq', null, 'save', $input);
        $this->assertSame($linked, $s->page($actor, 'faq', $id)['drafts']['bm']['content']['linked_service_public_id']);
        $bm = $this->input('faq', ['language' => 'bm', 'lock_version' => 1]);
        $s->mutate($actor, 'faq', $id, 'save', $bm);
        $this->assertNull($s->page($actor, 'faq', $id)['drafts']['en']['content']['linked_service_public_id']);
        $this->assertNull($s->page($actor, 'faq', $id)['drafts']['bm']['content']['linked_service_public_id']);
        $bm['lock_version'] = 2;
        $bm['idempotency_key'] = (string) Str::uuid();
        $bm['content']['linked_service_public_id'] = $linked;
        $s->mutate($actor, 'faq', $id, 'save', $bm);
        $this->assertSame($linked, $s->page($actor, 'faq', $id)['drafts']['en']['content']['linked_service_public_id']);
        $this->assertSame($linked, $s->page($actor, 'faq', $id)['drafts']['bm']['content']['linked_service_public_id']);
        $this->refuse(fn () => $s->mutate($actor, 'faq', null, 'save', $this->input('faq', ['branch_ids' => [$this->branch->id]])));
        $category = app(WebsiteServicesService::class)->mutate($actor, 'service_category', null, 'save', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'language' => 'en', 'content' => WebsiteServiceFields::empty('service_category'), 'branch_ids' => []]);
        $this->refuse(fn () => $s->mutate($actor, 'faq', null, 'save', $this->input('faq', ['content' => [...F::empty('faq'), 'linked_service_public_id' => $category]])));
        $foreignOrg = Organisation::create(['code' => 'FAQ_FOREIGN', 'name' => 'Synthetic foreign organisation', 'is_active' => true]);
        $foreignService = (string) Str::uuid();
        DB::table('website_content_items')->insert(['public_id' => $foreignService, 'organisation_id' => $foreignOrg->id, 'branch_id' => null, 'type' => 'public_service', 'slug' => 'foreign-'.$foreignService, 'lock_version' => 1, 'created_at' => now()]);
        $this->refuse(fn () => $s->mutate($actor, 'faq', null, 'save', $this->input('faq', ['content' => [...F::empty('faq'), 'linked_service_public_id' => $foreignService]])));
        $wrong = $this->create($actor, 'activity');
        foreach ([$wrong, (string) Str::uuid()] as $bad) {
            $this->refuse(fn () => $s->mutate($actor, 'faq', null, 'save', $this->input('faq', ['content' => [...F::empty('faq'), 'linked_service_public_id' => $bad]])));
        }
        $s->mutate($actor, 'faq', $id, 'archive', $this->input('faq', ['lock_version' => 3]));
        app(WebsiteServicesService::class)->mutate($actor, 'public_service', $linked, 'archive', ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->refuse(fn () => $s->mutate($actor, 'faq', null, 'save', $this->input('faq', ['content' => [...F::empty('faq'), 'linked_service_public_id' => $linked]])));
        $this->assertSame($linked, $s->page($actor, 'faq', $id)['history'][0]['content']['linked_service_public_id']);
    }

    public function test_foreign_branch_organisation_items_filters_and_fresh_permissions(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $id = $this->create($actor);
        $foreign = Organisation::create(['code' => 'W1D_FOREIGN', 'name' => 'Synthetic foreign organisation', 'is_active' => true]);
        $branch = Branch::forceCreate(['organisation_id' => $foreign->id, 'code' => 'W1D_FOREIGN', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        foreach ([$branch->id, 999999] as $bad) {
            $this->refuse(fn () => app(S::class)->mutate($actor, 'promotion', null, 'save', $this->input(extra: ['branch_ids' => [$bad]])));
        }
        $this->get('/website/promotions?branch_id='.$branch->id)->assertForbidden();
        $this->post('/website/promotions/'.$id, $this->input(extra: ['lock_version' => 1, 'expected_branch_id' => $branch->id]))->assertSessionHasErrors('expected_branch_id');
        $foreignItem = (string) Str::uuid();
        DB::table('website_content_items')->insert(['public_id' => $foreignItem, 'organisation_id' => $foreign->id, 'branch_id' => null, 'type' => 'promotion', 'slug' => 'foreign-'.$foreignItem, 'lock_version' => 1, 'created_at' => now()]);
        $this->get('/website/promotions/'.$foreignItem)->assertNotFound();
        $this->post('/website/promotions/'.$foreignItem, $this->input())->assertNotFound();
        $role = Role::findByName('marketing');
        $this->assertTrue($actor->can(P::MANAGE));
        $role->revokePermissionTo(P::MANAGE);
        $this->post('/website/promotions/'.$id, $this->input(extra: ['lock_version' => 1]))->assertForbidden();
        $role->revokePermissionTo(P::VIEW);
        $this->get('/website/promotions/'.$id)->assertForbidden();
    }

    public function test_media_order_consent_foreign_missing_retired_removal_and_budgets(): void
    {
        Storage::fake('website_media');
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $assets = [];
        for ($i = 0; $i < 12; $i++) {
            app(WebsiteDraftService::class)->upload($actor, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'alt_en' => 'Synthetic image', 'alt_bm' => 'Imej sintetik', 'rights_reference' => 'Synthetic rights', 'identifiable_person' => true, 'consent_reference' => 'Synthetic consent'], UploadedFile::fake()->image('synthetic.png', 20, 10));
            $assets[] = DB::table('website_media_assets')->orderByDesc('id')->first();
        }
        $base = F::empty('activity');
        $content = [...$base, 'cover_public_id' => $assets[0]->public_id, 'gallery_public_ids' => array_reverse(array_column($assets, 'public_id'))];
        $id = $s->mutate($actor, 'activity', null, 'save', $this->input('activity', ['content' => $content]));
        $this->assertSame($content['gallery_public_ids'], $s->page($actor, 'activity', $id)['history'][0]['content']['gallery_public_ids']);
        foreach ([array_fill(0, 13, (string) Str::uuid()), [$assets[0]->public_id, strtoupper($assets[0]->public_id)], [(string) Str::uuid()]] as $bad) {
            $this->refuse(fn () => $s->mutate($actor, 'activity', null, 'save', $this->input('activity', ['content' => [...$base, 'gallery_public_ids' => $bad]])));
        }
        $noConsent = (array) $assets[0];
        unset($noConsent['id']);
        $noConsent['public_id'] = (string) Str::uuid();
        $noConsent['path'] = 'synthetic-unconsented.png';
        $noConsent['consent_reference'] = '';
        DB::table('website_media_assets')->insert($noConsent);
        $this->refuse(fn () => $s->mutate($actor, 'promotion', null, 'save', $this->input('promotion', ['content' => [...F::empty('promotion'), 'cover_public_id' => $noConsent['public_id']]])));
        $foreign = Organisation::create(['code' => 'W1D_MEDIA', 'name' => 'Synthetic media org', 'is_active' => true]);
        $foreignAsset = (array) $assets[0];
        unset($foreignAsset['id']);
        $foreignAsset['organisation_id'] = $foreign->id;
        $foreignAsset['public_id'] = (string) Str::uuid();
        $foreignAsset['path'] = 'synthetic-foreign.png';
        DB::table('website_media_assets')->insert($foreignAsset);
        $this->refuse(fn () => $s->mutate($actor, 'activity', null, 'save', $this->input('activity', ['content' => [...$base, 'gallery_public_ids' => [$foreignAsset['public_id']]]])));
        app(WebsiteDraftService::class)->retire($actor, $assets[0]->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->refuse(fn () => $s->mutate($actor, 'activity', $id, 'save', $this->input('activity', ['lock_version' => 1, 'content' => $content])));
        $removed = [...$content, 'cover_public_id' => null, 'gallery_public_ids' => array_values(array_filter($content['gallery_public_ids'], fn ($a) => $a !== $assets[0]->public_id))];
        $s->mutate($actor, 'activity', $id, 'save', $this->input('activity', ['lock_version' => 1, 'content' => $removed]));
        $page = $s->page($actor, 'activity', $id);
        $this->assertNull($page['history'][0]['content']['cover_public_id']);
        $this->assertSame($assets[0]->public_id, $page['history'][1]['content']['cover_public_id']);
        $role = Role::findByName('marketing');
        $role->revokePermissionTo(P::ASSETS);
        $this->post('/website/activities', $this->input('activity', ['content' => [...$base, 'cover_public_id' => $assets[1]->public_id]]))->assertForbidden();
    }

    public function test_no_prohibited_source_reads_network_jobs_or_scheduler_and_all_unrelated_rows_identical(): void
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
        $this->publicService($actor);
        $scheduleBefore = array_map(fn ($e) => [$e->expression, $e->command], app(Schedule::class)->events());
        $before = $this->rows(true);
        foreach (F::TYPES as $type) {
            $id = null;
            $data = $this->input($type);
            if ($type === 'promotion') {
                $data['content']['offer_text'] = 'MANUAL OFFER SENTINEL';
                $data['content']['offer_display_reference'] = 'PRIVATE APPROVAL SENTINEL';
            }
            $actions = [function () use ($actor, $data, &$id, $type) {
                $id = app(S::class)->mutate($actor, $type, null, 'save', $data);
            }, function () use ($actor, &$id, $type) {
                return app(S::class)->page($actor, $type, $id);
            }, function () use ($actor, &$id, $type) {
                return app(S::class)->mutate($actor, $type, $id, 'save', $this->input($type, ['lock_version' => 1]));
            }, function () use ($actor, &$id, $type) {
                return app(S::class)->mutate($actor, $type, $id, 'archive', $this->input($type, ['lock_version' => 2]));
            }, function () use ($actor, &$id, $type) {
                return app(S::class)->mutate($actor, $type, $id, 'unarchive', $this->input($type, ['lock_version' => 3]));
            }, function () use (&$id, $type) {
                $this->get('/website/'.['promotion' => 'promotions', 'activity' => 'activities', 'faq' => 'faqs'][$type].'/'.$id)->assertOk();
            }];
            foreach ($actions as $action) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                $action();
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                foreach ($queries as $q) {
                    $this->assertDoesNotMatchRegularExpression('/(?:from|join|into|update)\s+"?(?:public_patient_intakes|public_intake_[a-z_]+|patients|patient_[a-z_]+|visits|visit_[a-z_]+|queue_[a-z_]+|clinical_[a-z_]+|encounters|treatment_[a-z_]+|invoices|invoice_[a-z_]+|payments|payment_[a-z_]+|dispensary_[a-z_]+|stock_[a-z_]+|inventory_[a-z_]+|medicine[a-z_]*|charge_definitions|price_[a-z_]+|appointments|appointment_[a-z_]+|clinician_credentials|staff_schedules|staff_rosters)"?\b/i', $q['query']);
                    if (preg_match('/(?:from|join)\s+"?(?:users|staff_profiles|staff_branch_assignments)"?\b/i', $q['query'])) {
                        $this->assertContains([$q['query'], $q['bindings']], $authority);
                    }
                }
                $this->assertSame($before, $this->rows(true));
            }
        }
        foreach (DB::table('audit_logs')->where('event', 'like', 'website.editorial.%')->get() as $audit) {
            $this->assertStringNotContainsString('SENTINEL', $audit->metadata);
            $this->assertSame(['field_names', 'item_id', 'language', 'revision_number', 'version'], collect(array_keys(json_decode($audit->metadata, true)))->sort()->values()->all());
        }
        $this->assertSame($scheduleBefore, array_map(fn ($e) => [$e->expression, $e->command], app(Schedule::class)->events()));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_audit_failure_rolls_back_every_mutation_and_http_preserves_control_refusal(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $id = $this->create($actor, 'promotion');
        $archived = $this->create($actor, 'promotion');
        app(S::class)->mutate($actor, 'promotion', $archived, 'archive', $this->input(extra: ['lock_version' => 1]));
        $bad = $this->input();
        $bad['content']['title'] = "\nmalformed";
        $this->post('/website/promotions', $bad)->assertSessionHasErrors('content.title');
        $audit = \Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->andReturnNull();
        $this->app->instance(AuditRecorder::class, $audit);
        foreach ([['save', null, 0], ['save', $id, 1], ['archive', $id, 1], ['unarchive', $archived, 2]] as [$operation, $target, $version]) {
            $before = $this->rows();
            try {
                app(S::class)->mutate($actor, 'promotion', $target, $operation, $this->input(extra: ['lock_version' => $version]));
                $this->fail('Audit failure released mutation.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Website audit could not be recorded.', $e->getMessage());
            }
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_database_guards_refuse_evidence_edits_divergent_references_and_faq_branches(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        foreach (F::TYPES as $type) {
            $id = $this->create($actor, $type);
            $item = DB::table('website_content_items')->where('public_id', $id)->first();
            $revision = DB::table('website_content_revisions')->where('item_id', $item->id)->first();
            $actions = [fn () => DB::table('website_content_revisions')->where('id', $revision->id)->update(['content' => '{}']), fn () => DB::table('website_content_revisions')->where('id', $revision->id)->delete(), fn () => DB::statement('TRUNCATE website_content_revisions'), fn () => DB::table('website_content_items')->where('id', $item->id)->update(['slug' => 'changed', 'lock_version' => 2])];
            $bad = (array) $revision;
            unset($bad['id']);
            $bad['language'] = 'bm';
            $bad['content'] = json_encode([...F::empty($type), '_branch_ids' => [$type === 'faq' ? $this->branch->id : 999999]]);
            $actions[] = fn () => DB::table('website_content_revisions')->insert($bad);
            if ($type === 'faq') {
                $actions[] = fn () => DB::table('website_content_item_branches')->insert(['organisation_id' => $actor->organisation_id, 'item_id' => $item->id, 'branch_id' => $this->branch->id]);
            }
            foreach ($actions as $action) {
                try {
                    DB::transaction(function () use ($action): void {
                        $action();
                        DB::statement('SET CONSTRAINTS website_shared_references_consistent IMMEDIATE');
                    });
                    $this->fail('Database protection bypassed.');
                } catch (QueryException $e) {
                    $this->assertNotEmpty($e->getMessage());
                }
            }
        }
        $linked = $this->publicService($actor);
        $input = $this->input('faq', ['content' => [...F::empty('faq'), 'linked_service_public_id' => $linked]]);
        $id = $s->mutate($actor, 'faq', null, 'save', $input);
        $item = DB::table('website_content_items')->where('public_id', $id)->first();
        $r = (array) DB::table('website_content_revisions')->where('item_id', $item->id)->first();
        unset($r['id']);
        $r['language'] = 'bm';
        $r['content'] = json_encode([...F::empty('faq'), '_branch_ids' => []]);
        try {
            DB::transaction(function () use ($r): void {
                DB::table('website_content_revisions')->insert($r);
                DB::statement('SET CONSTRAINTS website_shared_references_consistent IMMEDIATE');
            });
            $this->fail('Divergent service link accepted.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_direct_permission_and_effective_branch_revocation_refuse_freshly(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $id = $this->create($actor);
        $role = Role::findByName('marketing');
        $this->assertTrue($actor->can(P::MANAGE));
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', Permission::findByName(P::MANAGE)->id)->delete();
        try {
            $s->mutate($actor, 'promotion', $id, 'save', $this->input(extra: ['lock_version' => 1]));
            $this->fail('Cached management permission accepted.');
        } catch (AuthorizationException|HttpException $e) {
            $this->assertSame(403, $e instanceof HttpException ? $e->getStatusCode() : 403);
        }
        $role->givePermissionTo(P::MANAGE);
        $profile = DB::table('staff_profiles')->where('user_id', $actor->id)->value('id');
        DB::table('staff_branch_assignments')->where('staff_profile_id', $profile)->update(['valid_until' => now()->subDay()->toDateString()]);
        try {
            $s->mutate($actor, 'promotion', $id, 'archive', $this->input(extra: ['lock_version' => 1]));
            $this->fail('Revoked branch assignment accepted.');
        } catch (AuthorizationException|HttpException $e) {
            $this->assertContains($e instanceof HttpException ? $e->getStatusCode() : 403, [403, 404]);
        }
    }
}
