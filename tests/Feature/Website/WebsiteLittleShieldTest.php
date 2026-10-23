<?php

namespace Tests\Feature\Website;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Website\WebsiteAccess;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsiteLittleShieldFields as F;
use App\Domain\Organisation\Website\WebsiteLittleShieldService as S;
use App\Domain\Organisation\Website\WebsitePermissions as P;
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

class WebsiteLittleShieldTest extends BillingTestCase
{
    private function input(array $extra = []): array
    {
        return array_replace(['expected_branch_id' => $this->branch->id, 'item_public_id' => null, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'language' => 'en', 'content' => F::empty(), 'branch_ids' => []], $extra);
    }

    private function edit(string $id, int $version, array $extra = []): array
    {
        return $this->input(['item_public_id' => $id, 'lock_version' => $version, ...$extra]);
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

    public function test_every_catalogue_role_exact_existing_grants_view_and_mutation_access_and_no_new_permissions(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->assertSame(0, DB::table('website_content_items')->where('type', 'little_shield')->count());
        $id = app(S::class)->save($director, $this->input());
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $view = in_array($role, ['director', 'marketing', 'hr_manager', 'administrator'], true);
            $manage = in_array($role, ['director', 'marketing', 'administrator'], true);
            $this->assertSame($view, $actor->can(P::VIEW));
            $this->assertSame($manage, $actor->can(P::MANAGE));
            $this->assertSame($view ? 200 : 403, $this->get('/website/little-shield')->status(), $role);
            $version = DB::table('website_content_items')->where('public_id', $id)->value('lock_version');
            $response = $this->post('/website/little-shield', $this->edit($id, $version));
            if ($manage) {
                $response->assertRedirect('/website/little-shield');
            } else {
                $response->assertForbidden();
            }
        }
        $this->assertSame(['website.assets.manage.organisation', 'website.careers.manage.organisation', 'website.content.manage.organisation', 'website.content.view.organisation', 'website.doctor_profiles.manage.organisation'], Permission::where('name', 'like', 'website.%')->orderBy('name')->pluck('name')->all());
        $this->app['auth']->forgetGuards();
        $this->get('/website/little-shield')->assertRedirect('/login');
    }

    public function test_singleton_created_only_on_save_retry_conflict_stale_and_shared_branches_preserve_text_history_and_one_version(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $this->assertNull($s->page($actor)['item']);
        $cmd = $this->input(['content' => [...F::empty(), 'title' => 'SYNTHETIC EN']]);
        $id = $s->save($actor, $cmd);
        $this->assertSame($id, $s->save($actor, $cmd));
        $this->refuse(fn () => $s->save($actor, [...$cmd, 'content' => [...F::empty(), 'title' => 'Conflict']]));
        $this->refuse(fn () => $s->save($actor, $this->input()));
        $s->save($actor, $this->edit($id, 1, ['language' => 'bm', 'content' => [...F::empty(), 'title' => 'TEKS SINTETIK']]));
        $old = DB::table('website_content_revisions')->where('item_id', DB::table('website_content_items')->where('public_id', $id)->value('id'))->orderBy('id')->get()->toJson();
        $beforeAudit = DB::table('audit_logs')->count();
        $s->save($actor, $this->edit($id, 2, ['branch_ids' => [$this->branch->id], 'content' => [...F::empty(), 'title' => 'SYNTHETIC EN']]));
        $page = $s->page($actor);
        $this->assertSame(3, $page['item']['lockVersion']);
        $this->assertSame('SYNTHETIC EN', $page['drafts']['en']['content']['title']);
        $this->assertSame('TEKS SINTETIK', $page['drafts']['bm']['content']['title']);
        foreach (['en', 'bm'] as $lang) {
            $this->assertSame([$this->branch->id], $page['drafts'][$lang]['branchIds']);
            $this->assertSame(2, $page['drafts'][$lang]['number']);
        }
        $this->assertCount(4, $page['history']);
        $this->assertSame($old, DB::table('website_content_revisions')->where('item_id', DB::table('website_content_items')->where('public_id', $id)->value('id'))->orderBy('id')->limit(2)->get()->toJson());
        $this->assertSame($beforeAudit + 1, DB::table('audit_logs')->count());
        $audit = json_decode(DB::table('audit_logs')->orderByDesc('id')->value('metadata'), true);
        $this->assertSame(['en', 'bm'], $audit['languages']);
        $this->assertStringNotContainsString('SYNTHETIC', json_encode($audit));
        $this->refuse(fn () => $s->save($actor, $this->edit($id, 2)));
        for ($v = 3; $v < 15; $v++) {
            $s->save($actor, $this->edit($id, $v, ['branch_ids' => [$this->branch->id]]));
        }
        $this->assertCount(12, $s->page($actor)['history']);
        $this->assertCount(4, $s->page($actor, ['history_page' => 2])['history']);
        $this->assertSame(1, DB::table('website_content_items')->where('type', 'little_shield')->count());
    }

    public function test_every_text_card_list_budget_markup_hidden_characters_and_completeness(): void
    {
        $base = F::empty();
        $invalid = ['<b>bad</b>', '**bad**', "\nraw", "\0bad", "\u{202e}bad", "\u{200b}bad", "bad\xFF"];
        foreach (F::TEXT as $key => $limit) {
            $this->assertSame(str_repeat('x', $limit), F::validate([...$base, $key => str_repeat('x', $limit)])[$key]);
            foreach ([...$invalid, str_repeat('x', $limit + 1)] as $bad) {
                $this->refuse(fn () => F::validate([...$base, $key => $bad]));
            }
        }
        foreach (F::LISTS as $key => [$count,$limit]) {
            $this->assertCount($count, F::validate([...$base, $key => array_fill(0, $count, str_repeat('x', $limit))])[$key]);
            foreach ([array_fill(0, $count + 1, 'x'), ['wrong' => 'x'], [str_repeat('x', $limit + 1)], ...array_map(fn ($v) => [$v], $invalid)] as $bad) {
                $this->refuse(fn () => F::validate([...$base, $key => $bad]));
            }
        }
        foreach (F::CARDS as $key => [$count,$title,$description]) {
            $card = ['title' => str_repeat('t', $title), 'description' => str_repeat('d', $description)];
            $this->assertCount($count, F::validate([...$base, $key => array_fill(0, $count, $card)])[$key]);
            foreach ([array_fill(0, $count + 1, $card), ['wrong' => $card], [['title' => str_repeat('t', $title + 1), 'description' => 'x']], [['title' => 'x', 'description' => str_repeat('d', $description + 1)]], [['title' => 'x', 'description' => 'x', 'extra' => 'no']]] as $bad) {
                $this->refuse(fn () => F::validate([...$base, $key => $bad]));
            }
            foreach (['title', 'description'] as $field) {
                foreach ($invalid as $bad) {
                    $this->refuse(fn () => F::validate([...$base, $key => [[...$card, $field => $bad]]]));
                }
            }
        }
        foreach (['tracking_code', 'child_name', 'parent_phone', 'school_list', 'impact_numbers', 'sort_order', 'apply_url'] as $unknown) {
            $this->refuse(fn () => F::validate([...$base, $unknown => 'not allowed']));
        }
        $this->refuse(fn () => F::validate([...$base, 'include_on_website' => 'bad']));
        $content = [...$base, 'title' => 'Synthetic title', 'introduction' => 'Synthetic introduction', 'programme_pillars' => [['title' => 'One', 'description' => 'Description']], 'programme_flow_steps' => [['title' => 'Step', 'description' => 'Description']], 'medical_safeguards' => ['Synthetic safeguard']];
        $this->assertSame([], F::missing(F::validate($content), [$this->branch->id]));
        $this->assertSame(['branch_ids'], F::missing($content, []));
        foreach (['title', 'introduction', 'programme_pillars', 'programme_flow_steps', 'medical_safeguards'] as $key) {
            $this->assertContains($key, F::missing([...$content, $key => is_array($content[$key]) ? [] : ''], [$this->branch->id]));
        }
    }

    public function test_list_order_and_saved_only_http_preview_are_exact(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $content = [...F::empty(), 'overview_paragraphs' => ['Second', 'First'], 'medical_safeguards' => ['Second', 'First']];
        foreach (array_keys(F::CARDS) as $key) {
            $content[$key] = [['title' => 'Second', 'description' => 'Second description'], ['title' => 'First', 'description' => 'First description']];
        }
        $id = app(S::class)->save($actor, $this->input(['content' => $content]));
        $page = app(S::class)->page($actor);
        $saved = $page['drafts']['en']['content'];
        ksort($content);
        ksort($saved);
        $this->assertSame($content, $saved);
        $this->get('/website/little-shield')->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')->assertInertia(fn ($p) => $p->component('Website/LittleShield')->where('littleShield.item.publicId', $id)->where('littleShield.drafts.en.content', $content));
        $bad = $this->edit($id, 1, ['content' => [...$content, 'title' => "\ncontrol"]]);
        $this->post('/website/little-shield', $bad)->assertSessionHasErrors('content.title');
        $saved = app(S::class)->page($actor)['drafts']['en']['content'];
        ksort($saved);
        $this->assertSame($content, $saved);
    }

    public function test_organisation_branch_isolation_and_fresh_role_and_direct_authority_revocation(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $id = $s->save($actor, $this->input());
        $other = Organisation::create(['code' => 'LITTLE_OTHER', 'name' => 'Synthetic other organisation', 'is_active' => true]);
        $foreign = Branch::forceCreate(['organisation_id' => $other->id, 'code' => 'LITTLE_OTHER', 'name' => 'Synthetic other branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $this->refuse(fn () => $s->save($actor, $this->edit($id, 1, ['branch_ids' => [$foreign->id]])));
        $this->post('/website/little-shield', $this->edit((string) Str::uuid(), 1))->assertNotFound();
        $this->post('/website/little-shield', $this->edit($id, 1, ['expected_branch_id' => $foreign->id]))->assertSessionHasErrors('expected_branch_id');
        $role = Role::findByName('marketing');
        $this->assertTrue($actor->can(P::MANAGE));
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', Permission::findByName(P::MANAGE)->id)->delete();
        $this->post('/website/little-shield', $this->edit($id, 1))->assertForbidden();
        $role->givePermissionTo(P::MANAGE);
        $role->revokePermissionTo(P::VIEW);
        $this->get('/website/little-shield')->assertForbidden();
        $role->givePermissionTo(P::VIEW);
        $profile = DB::table('staff_profiles')->where('user_id', $actor->id)->value('id');
        DB::table('staff_branch_assignments')->where('staff_profile_id', $profile)->update(['valid_until' => now()->subDay()->toDateString()]);
        try {
            $s->save($actor, $this->edit($id, 1));
            $this->fail('Revoked branch accepted.');
        } catch (AuthorizationException|HttpException $e) {
            $this->assertContains($e instanceof HttpException ? $e->getStatusCode() : 403, [403, 404]);
        }
    }

    public function test_organisation_singletons_are_isolated_and_direct_cached_permissions_are_revoked_freshly(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $id = $s->save($actor, $this->input(['content' => [...F::empty(), 'title' => 'LOCAL SENTINEL']]));
        $org = Organisation::create(['code' => 'LITTLE_ISOLATION', 'name' => 'Synthetic isolated organisation']);
        $branch = Branch::forceCreate(['organisation_id' => $org->id, 'code' => 'LITTLE_ISOLATION', 'name' => 'Synthetic isolated branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $other = $this->actor('marketing', $branch);
        $this->selectBranch($other, $branch);
        $this->assertNull($s->page($other)['item']);
        $otherId = $s->save($other, $this->input(['expected_branch_id' => $branch->id, 'content' => [...F::empty(), 'title' => 'FOREIGN SENTINEL']]));
        $this->assertNotSame($id, $otherId);
        $this->selectBranch($actor);
        $this->assertSame('LOCAL SENTINEL', $s->page($actor)['drafts']['en']['content']['title']);
        $this->post('/website/little-shield', $this->edit($otherId, 1))->assertNotFound();
        $direct = $this->actor('ca');
        $this->selectBranch($direct);
        $direct->givePermissionTo([P::VIEW, P::MANAGE]);
        $this->assertTrue($direct->can(P::VIEW));
        $this->assertTrue($direct->can(P::MANAGE));
        DB::table('model_has_permissions')->where('model_id', $direct->id)->whereIn('permission_id', Permission::whereIn('name', [P::VIEW, P::MANAGE])->pluck('id'))->delete();
        try {
            $s->page($direct);
            $this->fail('Cached direct view permission accepted.');
        } catch (AuthorizationException|HttpException $e) {
            $this->assertSame(403, $e instanceof HttpException ? $e->getStatusCode() : 403);
        }
        try {
            $s->save($direct, $this->edit($id, 1));
            $this->fail('Cached direct manage permission accepted.');
        } catch (AuthorizationException|HttpException $e) {
            $this->assertSame(403, $e instanceof HttpException ? $e->getStatusCode() : 403);
        }
    }

    public function test_postgresql_singleton_append_only_identity_and_cross_language_guards(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $id = $s->save($actor, $this->input());
        $s->save($actor, $this->edit($id, 1, ['language' => 'bm']));
        DB::statement('SET CONSTRAINTS website_shared_references_consistent IMMEDIATE');
        DB::statement('SET CONSTRAINTS website_shared_references_consistent DEFERRED');
        $item = DB::table('website_content_items')->where('public_id', $id)->first();
        $rev = DB::table('website_content_revisions')->where('item_id', $item->id)->first();
        $second = (array) $item;
        unset($second['id']);
        $second['public_id'] = (string) Str::uuid();
        $second['slug'] = 'second-synthetic';
        $bad = (array) $rev;
        unset($bad['id']);
        $bad['revision_number'] = 2;
        $bad['content'] = json_encode([...F::empty(), '_branch_ids' => [$this->branch->id]]);
        $actions = [fn () => DB::table('website_content_items')->insert($second), fn () => DB::table('website_content_revisions')->where('id', $rev->id)->update(['content' => '{}']), fn () => DB::table('website_content_revisions')->where('id', $rev->id)->delete(), fn () => DB::statement('TRUNCATE website_content_revisions'), fn () => DB::table('website_content_items')->where('id', $item->id)->update(['archived_at' => now(), 'lock_version' => 3]), fn () => DB::table('website_content_items')->where('id', $item->id)->update(['slug' => 'changed', 'lock_version' => 3]), fn () => DB::table('website_content_revisions')->insert($bad)];
        foreach ($actions as $action) {
            try {
                DB::transaction(function () use ($action) {
                    $action();
                    DB::statement('SET CONSTRAINTS website_shared_references_consistent IMMEDIATE');
                });
                $this->fail('Database guard bypassed.');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertSame(1, DB::table('website_content_items')->where('type', 'little_shield')->count());
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
                $q->where('type', '<>', 'little_shield');
            }
            if ($unrelated && in_array($row->tablename, ['website_content_revisions', 'website_content_item_branches'], true)) {
                $q->whereNotIn('item_id', DB::table('website_content_items')->select('id')->where('type', 'little_shield'));
            }
            if ($unrelated && $row->tablename === 'website_draft_commands') {
                $q->where('operation', '<>', 'little_save');
            }
            foreach (in_array('id', Schema::getColumnListing($row->tablename), true) ? ['id'] : Schema::getColumnListing($row->tablename) as $column) {
                $q->orderBy($column);
            }
            $out[$row->tablename] = $q->get()->toJson();
        }

        return $out;
    }

    public function test_no_prohibited_queries_http_jobs_scheduler_and_all_unrelated_rows_unchanged_after_each_action(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->finalizedFixture();
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        DB::transaction(fn () => app(WebsiteAccess::class)->lock($actor, $this->branch->id, P::MANAGE));
        $authority = array_map(fn ($q) => [$q['query'], $q['bindings']], DB::getQueryLog());
        DB::disableQueryLog();
        $schedule = array_map(fn ($e) => [$e->expression, $e->command], app(Schedule::class)->events());
        $before = $this->rows(true);
        $id = null;
        $actions = [function () use ($s, $actor, &$id) {
            $id = $s->save($actor, $this->input(['content' => [...F::empty(), 'title' => 'PRIVATE SENTINEL']]));
        }, fn () => $s->page($actor), function () use ($s, $actor, &$id) {
            $s->save($actor, $this->edit($id, 1, ['language' => 'bm']));
        }, function () use ($s, $actor, &$id) {
            $s->save($actor, $this->edit($id, 2, ['branch_ids' => [$this->branch->id]]));
        }, fn () => $this->get('/website/little-shield')->assertOk()];
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
        foreach (DB::table('audit_logs')->where('event', 'website.little_shield.save')->get() as $audit) {
            $this->assertStringNotContainsString('SENTINEL', $audit->metadata);
            $this->assertSame(['field_names', 'item_id', 'languages', 'revision_number', 'version'], collect(array_keys(json_decode($audit->metadata, true)))->sort()->values()->all());
        }
        $this->assertSame($schedule, array_map(fn ($e) => [$e->expression, $e->command], app(Schedule::class)->events()));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_audit_failure_rolls_back_first_and_coordinated_saves(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $id = $s->save($actor, $this->input());
        $s->save($actor, $this->edit($id, 1, ['language' => 'bm']));
        $audit = \Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->andReturnNull();
        $this->app->instance(AuditRecorder::class, $audit);
        $before = $this->rows();
        try {
            app(S::class)->save($actor, $this->edit($id, 2, ['branch_ids' => [$this->branch->id]]));
            $this->fail('Audit failure accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Website audit could not be recorded.', $e->getMessage());
        }
        $this->assertSame($before, $this->rows());
        // A fresh organisation has no singleton yet: failing its first audit leaves none.
        $other = Organisation::create(['code' => 'LITTLE_AUDIT', 'name' => 'Synthetic audit organisation', 'is_active' => true]);
        $branch = Branch::forceCreate(['organisation_id' => $other->id, 'code' => 'LITTLE_AUDIT', 'name' => 'Synthetic audit branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $otherActor = $this->actor('marketing', $branch);
        $this->selectBranch($otherActor, $branch);
        $before = $this->rows();
        try {
            app(S::class)->save($otherActor, $this->input(['expected_branch_id' => $branch->id]));
            $this->fail('Audit failure accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Website audit could not be recorded.', $e->getMessage());
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_media_consent_scope_order_retirement_and_removal(): void
    {
        Storage::fake('website_media');
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $s = app(S::class);
        $media = app(WebsiteDraftService::class);
        $assets = [];
        for ($i = 0; $i < 8; $i++) {
            $media->upload($actor, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'alt_en' => 'Synthetic image', 'alt_bm' => 'Imej sintetik', 'rights_reference' => 'Synthetic rights', 'identifiable_person' => true, 'consent_reference' => 'Synthetic consent'], UploadedFile::fake()->image('synthetic.png', 20 + $i, 10));
            $assets[] = DB::table('website_media_assets')->orderByDesc('id')->first();
        }
        $content = [...F::empty(), 'cover_public_id' => $assets[0]->public_id, 'gallery_public_ids' => array_reverse(array_column($assets, 'public_id'))];
        $id = $s->save($actor, $this->input(['content' => $content]));
        $this->assertSame($content['gallery_public_ids'], $s->page($actor)['drafts']['en']['content']['gallery_public_ids']);
        foreach ([array_fill(0, 9, (string) Str::uuid()), [$assets[0]->public_id, strtoupper($assets[0]->public_id)], ['wrong' => 'x'], [(string) Str::uuid()]] as $bad) {
            $this->refuse(fn () => $s->save($actor, $this->edit($id, 1, ['content' => [...F::empty(), 'gallery_public_ids' => $bad]])));
        }
        $copy = (array) $assets[0];
        unset($copy['id']);
        $copy['public_id'] = (string) Str::uuid();
        $copy['path'] = 'synthetic-no-consent.png';
        $copy['consent_reference'] = '';
        DB::table('website_media_assets')->insert($copy);
        $this->refuse(fn () => $s->save($actor, $this->edit($id, 1, ['content' => [...F::empty(), 'cover_public_id' => $copy['public_id']]])));
        $org = Organisation::create(['code' => 'LITTLE_MEDIA', 'name' => 'Synthetic media organisation', 'is_active' => true]);
        $copy['public_id'] = (string) Str::uuid();
        $copy['path'] = 'synthetic-foreign.png';
        $copy['organisation_id'] = $org->id;
        DB::table('website_media_assets')->insert($copy);
        $this->refuse(fn () => $s->save($actor, $this->edit($id, 1, ['content' => [...F::empty(), 'cover_public_id' => $copy['public_id']]])));
        $s->save($actor, $this->edit($id, 1, ['language' => 'bm', 'content' => $content]));
        $media->retire($actor, $assets[0]->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->refuse(fn () => $s->save($actor, $this->edit($id, 2, ['content' => $content])));
        $clean = [...$content, 'cover_public_id' => null, 'gallery_public_ids' => array_values(array_filter($content['gallery_public_ids'], fn ($a) => $a !== $assets[0]->public_id))];
        $s->save($actor, $this->edit($id, 2, ['content' => $clean, 'branch_ids' => [$this->branch->id]]));
        $this->assertNull($s->page($actor)['drafts']['en']['content']['cover_public_id']);
        $this->assertSame($assets[0]->public_id, $s->page($actor)['drafts']['bm']['content']['cover_public_id']);
        Role::findByName('marketing')->revokePermissionTo(P::ASSETS);
        $this->post('/website/little-shield', $this->edit($id, 3, ['content' => [...F::empty(), 'cover_public_id' => $assets[1]->public_id]]))->assertForbidden();
    }
}
