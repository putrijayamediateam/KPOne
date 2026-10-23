<?php

namespace Tests\Feature\Website;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Website\WebsiteAccess;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsitePeopleFields as F;
use App\Domain\Organisation\Website\WebsitePeopleService as S;
use App\Domain\Organisation\Website\WebsitePermissions as P;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Billing\BillingTestCase;

class WebsitePeopleTest extends BillingTestCase
{
    private function input(string $type = 'doctor_profile', array $extra = []): array
    {
        $content = F::empty($type);
        if ($type === 'career_posting') {
            $content['apply_email'] = 'careers@synthetic.test';
        }

        return array_replace(['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'language' => 'en', 'content' => $content, 'branch_ids' => [$this->branch->id], 'portrait_public_id' => null], $extra);
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

    public function test_exact_role_grants_every_view_and_mutation_denial_and_anonymous(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        foreach (['doctor_profile' => 'doctor-profiles', 'career_posting' => 'careers'] as $type => $path) {
            $id = app(S::class)->mutate($director, $type, null, 'save', $this->input($type));
            foreach (array_keys(PermissionCatalogue::roles()) as $role) {
                $actor = $this->actor($role);
                $this->selectBranch($actor);
                $view = in_array($role, ['director', 'hr_manager', 'marketing', 'administrator'], true);
                $manage = in_array($role, ['director', 'hr_manager'], true);
                foreach ([P::VIEW => $view, P::DOCTORS => $manage, P::CAREERS => $manage] as $permission => $expected) {
                    $this->assertSame($expected, $actor->can($permission), "$role $permission");
                }
                DB::enableQueryLog();
                DB::flushQueryLog();
                $this->assertSame($view ? 200 : 403, $this->get('/website/'.$path)->status(), $role.' list '.$path);
                $this->assertSame($view ? 200 : 403, $this->get('/website/'.$path.'/'.$id)->status(), $role.' edit '.$path);
                $this->assertSame($manage ? 200 : 403, $this->get('/website/'.$path.'/create')->status(), $role.' create '.$path);
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                foreach ($queries as $query) {
                    $this->assertDoesNotMatchRegularExpression('/(?:from|join|into|update)\s+"?(?:public_patient_intakes|public_intake_[a-z_]+|patients|patient_[a-z_]+|visits|visit_[a-z_]+|queue_[a-z_]+|clinical_[a-z_]+|encounters|treatment_[a-z_]+|invoices|invoice_[a-z_]+|payments|payment_[a-z_]+|dispensary_[a-z_]+|stock_[a-z_]+|inventory_[a-z_]+|clinician_credentials)"?\b/i', $query['query']);
                }
                if (! $manage) {
                    $before = $this->rows();
                    unset($before['audit_logs']);
                    $audits = DB::table('audit_logs')->count();
                    foreach (['', '/'.$id, '/'.$id.'/archive', '/'.$id.'/unarchive'] as $suffix) {
                        $this->post('/website/'.$path.$suffix, $this->input($type))->assertForbidden();
                    }
                    $after = $this->rows();
                    unset($after['audit_logs']);
                    $this->assertSame($before, $after);
                    $this->assertSame($audits + 4, DB::table('audit_logs')->count());
                }
            }
        }
        $this->app['auth']->forgetGuards();
        $this->get('/website/doctor-profiles')->assertRedirect('/login');
    }

    public function test_hr_create_edit_independent_languages_history_slug_and_archive(): void
    {
        $actor = $this->actor('hr_manager');
        $this->selectBranch($actor);
        foreach (['doctor_profile' => 'doctor-profiles', 'career_posting' => 'careers'] as $type => $path) {
            $data = $this->input($type);
            $data['content'][$type === 'doctor_profile' ? 'display_name' : 'title'] = 'Synthetic manual draft';
            $this->post('/website/'.$path, $data)->assertRedirect();
            $item = DB::table('website_content_items')->where('type', $type)->first();
            $this->assertNull($item->branch_id);
            $this->assertSame(1, $item->lock_version);
            $this->post('/website/'.$path, $data)->assertRedirect();
            $this->assertSame(1, DB::table('website_content_revisions')->where('item_id', $item->id)->count());
            $bm = $this->input($type, ['lock_version' => 1, 'language' => 'bm']);
            app(S::class)->mutate($actor, $type, $item->public_id, 'save', $bm);
            $en = $this->input($type, ['lock_version' => 2]);
            app(S::class)->mutate($actor, $type, $item->public_id, 'save', $en);
            $this->assertSame($item->public_id, app(S::class)->mutate($actor, $type, $item->public_id, 'save', $en));
            // Same UUID with changed payload is refused; the original retry remains successful.
            $conflict = $en;
            $conflict['language'] = 'bm';
            $this->refuse(fn () => app(S::class)->mutate($actor, $type, $item->public_id, 'save', $conflict));
            $stale = $this->input($type, ['lock_version' => 1]);
            $this->refuse(fn () => app(S::class)->mutate($actor, $type, $item->public_id, 'save', $stale));
            $this->get('/website/'.$path.'/'.$item->public_id)->assertInertia(fn (Assert $p) => $p->component('Website/People')->where('people.drafts.en.number', 2)->where('people.drafts.bm.number', 1)->has('people.history', 3));
            foreach (['archive' => 3, 'unarchive' => 4] as $operation => $version) {
                $command = $this->input($type, ['lock_version' => $version]);
                app(S::class)->mutate($actor, $type, $item->public_id, $operation, $command);
                $this->assertSame($item->public_id, app(S::class)->mutate($actor, $type, $item->public_id, $operation, $command));
                $this->assertSame($item->slug, DB::table('website_content_items')->where('id', $item->id)->value('slug'));
                $this->assertSame(3, DB::table('website_content_revisions')->where('item_id', $item->id)->count());
                $this->assertSame($operation === 'archive', DB::table('website_content_items')->where('id', $item->id)->value('archived_at') !== null);
                if ($operation === 'archive') {
                    $this->assertCount(0, app(S::class)->page($actor, $type, null)['items']);
                    $this->assertCount(1, app(S::class)->page($actor, $type, null, ['archived' => true])['items']);
                    $this->refuse(fn () => app(S::class)->mutate($actor, $type, $item->public_id, 'save', $this->input($type, ['lock_version' => 4])));
                }
            }
        }
    }

    public function test_all_text_and_list_budgets_markup_control_bidi_and_zero_width(): void
    {
        foreach (['doctor_profile', 'career_posting'] as $type) {
            $base = $this->input($type)['content'];
            foreach ($type === 'doctor_profile' ? F::DOCTOR_TEXT : F::CAREER_TEXT as $field => $limit) {
                $value = [...$base, $field => str_repeat('a', $limit + 1)];
                $this->refuse(fn () => F::validate($type, $value));
                if (! in_array($field, ['apply_url', 'apply_email'], true)) {
                    $value[$field] = str_repeat('a', $limit);
                    if ($field === 'public_registration_text') {
                        $value['registration_display_reference'] = 'Synthetic approval';
                    }
                    $this->assertSame($limit, mb_strlen(F::validate($type, $value)[$field]));
                }
                foreach (['<b>text</b>', '**bold**', '[link](https://synthetic.test)', "hidden\u{200B}", "bidi\u{202E}", "null\0", "new\nline", "bad\u{2066}"] as $unsafe) {
                    $this->refuse(fn () => F::validate($type, [...$base, $field => $unsafe]));
                }
            }
            foreach ($type === 'doctor_profile' ? F::DOCTOR_LISTS : F::CAREER_LISTS as $field => [$count, $limit]) {
                $this->assertCount($count, F::validate($type, [...$base, $field => array_fill(0, $count, str_repeat('a', $limit))])[$field]);
                foreach ([array_fill(0, $count + 1, 'a'), [str_repeat('a', $limit + 1)], ['<script>unsafe</script>'], ["x\u{200D}"], ['not' => 'list']] as $invalid) {
                    $this->refuse(fn () => F::validate($type, [...$base, $field => $invalid]));
                }
            }
            foreach ([['unknown' => 'value'], ['sort_order' => 100001], ['sort_order' => 1.5], [$type === 'doctor_profile' ? 'include_on_website' : 'open' => 'yes']] as $invalid) {
                $this->refuse(fn () => F::validate($type, [...$base, ...$invalid]));
            }
        }
    }

    public function test_completeness_consent_registration_manual_and_career_rules(): void
    {
        $doctor = F::empty('doctor_profile');
        $this->assertContains('profile_consent_reference', F::missing('doctor_profile', $doctor));
        $this->refuse(fn () => F::validate('doctor_profile', [...$doctor, 'public_registration_text' => 'MMC synthetic']));
        foreach (F::DOCTOR_TEXT as $field => $limit) {
            $doctor[$field] = 'Manual synthetic entry';
        }
        foreach (F::DOCTOR_LISTS as $field => $limits) {
            $doctor[$field] = ['Manual synthetic entry'];
        }
        $this->assertSame([], F::missing('doctor_profile', F::validate('doctor_profile', $doctor)));
        $base = $this->input('career_posting')['content'];
        foreach (F::EMPLOYMENT as $type) {
            $this->assertSame($type, F::validate('career_posting', [...$base, 'employment_type' => $type])['employment_type']);
        }
        foreach ([['employment_type' => 'employee'], ['closing_date' => '2026-02-30'], ['closing_date' => '2026-10-22T10:00:00Z'], ['apply_email' => ''], ['apply_url' => 'https://synthetic.test/jobs'], ['apply_email' => 'invalid'], ['apply_email' => '', 'apply_url' => 'http://synthetic.test'], ['apply_email' => '', 'apply_url' => 'javascript:alert(1)'], ['apply_email' => '', 'apply_url' => 'https://user:secret@synthetic.test']] as $invalid) {
            $this->refuse(fn () => F::validate('career_posting', [...$base, ...$invalid]));
        }
        $this->assertSame('2028-02-29', F::validate('career_posting', [...$base, 'closing_date' => '2028-02-29'])['closing_date']);
        $this->assertSame('https://synthetic.test/jobs', F::validate('career_posting', [...$base, 'apply_email' => '', 'apply_url' => 'https://synthetic.test/jobs'])['apply_url']);
    }

    public function test_fresh_revocation_effective_branch_and_foreign_organisation_refused(): void
    {
        $actor = $this->actor('hr_manager');
        $this->selectBranch($actor);
        $id = app(S::class)->mutate($actor, 'doctor_profile', null, 'save', $this->input());
        Role::findByName('hr_manager')->revokePermissionTo(P::DOCTORS);
        foreach ([fn () => app(S::class)->mutate($actor, 'doctor_profile', null, 'save', $this->input()), fn () => app(S::class)->mutate($actor, 'doctor_profile', $id, 'archive', $this->input(extra: ['lock_version' => 1]))] as $action) {
            try {
                $action();
                $this->fail('Fresh revocation accepted.');
            } catch (AuthorizationException|HttpException $e) {
                $this->assertTrue(true);
            }
        }
        $director = $this->actor('director');
        $this->selectBranch($director);
        $foreignOrg = Organisation::query()->forceCreate(['code' => 'WEBSITE_SYNTHETIC', 'name' => 'Synthetic organisation', 'is_active' => true]);
        $foreign = Branch::query()->forceCreate(['organisation_id' => $foreignOrg->id, 'code' => 'SYNTHETIC', 'name' => 'Synthetic branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $this->refuse(fn () => app(S::class)->mutate($director, 'doctor_profile', null, 'save', $this->input(extra: ['branch_ids' => [$foreign->id]])));
        $this->get('/website/doctor-profiles?branch_id='.$foreign->id)->assertForbidden();
        $other = $this->actor('director', $foreign);
        $this->selectBranch($other, $foreign);
        $this->get('/website/doctor-profiles/'.$id)->assertNotFound();
        $this->post('/website/doctor-profiles/'.$id, $this->input(extra: ['expected_branch_id' => $foreign->id, 'branch_ids' => [$foreign->id], 'lock_version' => 1]))->assertNotFound();
        $this->postJson('/website/doctor-profiles', $this->input())->assertUnprocessable();
    }

    public function test_portraits_require_active_same_organisation_sanitised_consent_asset(): void
    {
        Storage::fake('website_media');
        $actor = $this->actor('hr_manager');
        $this->selectBranch($actor);
        $service = app(WebsiteDraftService::class);
        $base = ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'alt_en' => 'Synthetic portrait illustration', 'alt_bm' => 'Ilustrasi potret sintetik', 'rights_reference' => 'Synthetic rights', 'identifiable_person' => true, 'consent_reference' => 'Synthetic signed consent'];
        $service->upload($actor, $base, UploadedFile::fake()->image('synthetic.png', 80, 100));
        $asset = DB::table('website_media_assets')->first();
        $id = app(S::class)->mutate($actor, 'doctor_profile', null, 'save', $this->input(extra: ['portrait_public_id' => $asset->public_id]));
        $this->assertSame($asset->id, DB::table('website_content_revisions')->where('item_id', DB::table('website_content_items')->where('public_id', $id)->value('id'))->value('hero_asset_id'));
        $service->upload($actor, [...$base, 'idempotency_key' => (string) Str::uuid()], UploadedFile::fake()->image('another-synthetic.png', 80, 100));
        $second = DB::table('website_media_assets')->orderByDesc('id')->first();
        $this->refuse(fn () => app(S::class)->mutate($actor, 'doctor_profile', $id, 'save', $this->input(extra: ['language' => 'bm', 'lock_version' => 1, 'portrait_public_id' => $second->public_id])));
        $foreignOrg = Organisation::query()->forceCreate(['code' => 'PORTRAIT_SYNTHETIC', 'name' => 'Synthetic portrait organisation', 'is_active' => true]);
        $foreignBranch = Branch::query()->forceCreate(['organisation_id' => $foreignOrg->id, 'code' => 'PORTRAIT', 'name' => 'Synthetic portrait branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreignActor = $this->actor('hr_manager', $foreignBranch);
        $this->selectBranch($foreignActor, $foreignBranch);
        $service->upload($foreignActor, [...$base, 'expected_branch_id' => $foreignBranch->id, 'idempotency_key' => (string) Str::uuid()], UploadedFile::fake()->image('foreign-synthetic.png', 80, 100));
        $foreignPortrait = DB::table('website_media_assets')->where('organisation_id', $foreignOrg->id)->value('public_id');
        $this->selectBranch($actor);
        $this->refuse(fn () => app(S::class)->mutate($actor, 'doctor_profile', null, 'save', $this->input(extra: ['portrait_public_id' => $foreignPortrait])));
        $this->refuse(fn () => app(S::class)->mutate($actor, 'career_posting', null, 'save', $this->input('career_posting', ['portrait_public_id' => $asset->public_id])));
        $service->retire($actor, $asset->public_id, [...$base, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $this->refuse(fn () => app(S::class)->mutate($actor, 'doctor_profile', null, 'save', $this->input(extra: ['portrait_public_id' => $asset->public_id])));
        $this->assertSame($asset->public_id, app(S::class)->page($actor, 'doctor_profile', $id)['drafts']['en']['portraitPublicId']);
        $service->upload($actor, [...$base, 'idempotency_key' => (string) Str::uuid(), 'identifiable_person' => false, 'consent_reference' => ''], UploadedFile::fake()->image('synthetic.png'));
        $plain = DB::table('website_media_assets')->orderByDesc('id')->first();
        $this->refuse(fn () => app(S::class)->mutate($actor, 'doctor_profile', null, 'save', $this->input(extra: ['portrait_public_id' => $plain->public_id])));
        $this->refuse(fn () => app(S::class)->mutate($actor, 'doctor_profile', null, 'save', $this->input(extra: ['portrait_public_id' => (string) Str::uuid()])));
    }

    public function test_postgresql_immutability_type_constraint_and_existing_branch_profile_preserved(): void
    {
        $before = DB::table('website_content_items')->where('type', 'branch_public_profile')->orderBy('id')->get()->toJson();
        $actor = $this->actor('hr_manager');
        $this->selectBranch($actor);
        $id = app(S::class)->mutate($actor, 'doctor_profile', null, 'save', $this->input());
        $item = DB::table('website_content_items')->where('public_id', $id)->first();
        foreach ([fn () => DB::table('website_content_revisions')->where('item_id', $item->id)->update(['content' => '{}']), fn () => DB::table('website_content_revisions')->where('item_id', $item->id)->delete(), fn () => DB::statement('TRUNCATE website_content_revisions'), fn () => DB::table('website_content_items')->where('id', $item->id)->update(['slug' => 'changed', 'lock_version' => 2]), fn () => DB::table('website_content_items')->where('id', $item->id)->delete(), fn () => DB::table('website_content_items')->insert(['public_id' => Str::uuid(), 'organisation_id' => $actor->organisation_id, 'type' => 'little_shield', 'slug' => 'unauthorised', 'lock_version' => 1, 'created_at' => now()])] as $action) {
            try {
                DB::transaction($action);
                $this->fail('Database guard accepted mutation.');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertSame($before, DB::table('website_content_items')->where('type', 'branch_public_profile')->orderBy('id')->get()->toJson());
    }

    public function test_branch_associations_search_historical_choices_and_http_source_isolation(): void
    {
        $actor = $this->actor('hr_manager');
        $this->selectBranch($actor);
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '<>', $this->branch->id)->firstOrFail();
        foreach (['doctor_profile' => 'doctor-profiles', 'career_posting' => 'careers'] as $type => $path) {
            $data = $this->input($type);
            $key = $type === 'doctor_profile' ? 'display_name' : 'title';
            $data['content'][$key] = 'Synthetic searchable draft';
            $data['branch_ids'] = [$this->branch->id, $other->id];
            $id = app(S::class)->mutate($actor, $type, null, 'save', $data);
            $page = app(S::class)->page($actor, $type, $id, ['search' => 'searchable', 'branch_id' => $other->id]);
            $this->assertCount(1, $page['items']);
            $this->assertCount(2, $page['item']['branchIds']);
            $this->refuse(fn () => app(S::class)->mutate($actor, $type, $id, 'save', $this->input($type, ['lock_version' => 1, 'branch_ids' => []])));
            $this->refuse(fn () => app(S::class)->mutate($actor, $type, $id, 'save', $this->input($type, ['lock_version' => 1, 'branch_ids' => [$other->id, $other->id]])));
            app(S::class)->mutate($actor, $type, $id, 'save', $this->input($type, ['lock_version' => 1, 'branch_ids' => [$other->id]]));
            $page = app(S::class)->page($actor, $type, $id);
            $this->assertSame([$other->id], $page['item']['branchIds']);
            $this->assertCount(2, $page['history'][1]['branchIds']);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get('/website/'.$path.'/'.$id)->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/(?:from|join|into|update)\s+"?(?:public_patient_intakes|public_intake_[a-z_]+|patients|patient_[a-z_]+|visits|queue_[a-z_]+|clinical_[a-z_]+|encounters|treatment_[a-z_]+|invoices|invoice_[a-z_]+|payments|payment_[a-z_]+|dispensary_[a-z_]+|stock_[a-z_]+|inventory_[a-z_]+|clinician_credentials)"?\b/i', $query['query']);
            }
        }
        Role::findByName('hr_manager')->revokePermissionTo(P::VIEW);
        $this->get('/website/doctor-profiles')->assertForbidden();
    }

    private function rows(bool $unrelated = false): array
    {
        $out = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $row) {
            if ($unrelated && (in_array($row->tablename, ['website_content_items', 'website_content_revisions', 'website_content_item_branches', 'website_draft_commands', 'audit_logs'], true))) {
                continue;
            }
            $columns = Schema::getColumnListing($row->tablename);
            $q = DB::table($row->tablename);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $c) {
                $q->orderBy($c);
            }
            $out[$row->tablename] = $q->get()->toJson();
        }

        return $out;
    }

    public function test_all_unrelated_rows_identical_no_staff_sources_outbound_or_jobs_and_values_free_audit(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->finalizedFixture();
        $actor = $this->actor('hr_manager');
        $this->selectBranch($actor);
        $service = app(S::class);
        // Only the existing acting-user authority check may read identity/staff tables.
        DB::enableQueryLog();
        DB::flushQueryLog();
        DB::transaction(fn () => app(WebsiteAccess::class)->lock($actor, $this->branch->id, P::DOCTORS));
        $authority = array_map(fn ($q) => [$q['query'], $q['bindings']], DB::getQueryLog());
        DB::disableQueryLog();
        $before = $this->rows(true);
        $input = $this->input();
        $input['content']['display_name'] = 'MANUAL-CONTENT-SENTINEL';
        $id = null;
        $actions = [function () use ($service, $actor, $input, &$id) {
            $id = $service->mutate($actor, 'doctor_profile', null, 'save', $input);
        }, function () use ($service, $actor, &$id) {
            return $service->page($actor, 'doctor_profile', $id);
        }, function () use ($service, $actor, &$id) {
            return $service->mutate($actor, 'doctor_profile', $id, 'archive', $this->input(extra: ['lock_version' => 1]));
        }, function () use ($service, $actor, &$id) {
            return $service->mutate($actor, 'doctor_profile', $id, 'unarchive', $this->input(extra: ['lock_version' => 2]));
        }];
        $career = $this->input('career_posting');
        $career['content']['title'] = 'MANUAL-CONTENT-SENTINEL';
        $actions = [...$actions,
            function () use ($service, $actor, $career, &$id) {
                $id = $service->mutate($actor, 'career_posting', null, 'save', $career);
            },
            function () use ($service, $actor, &$id) {
                return $service->page($actor, 'career_posting', $id);
            },
            function () use ($service, $actor, &$id) {
                return $service->mutate($actor, 'career_posting', $id, 'archive', $this->input('career_posting', ['lock_version' => 1]));
            },
            function () use ($service, $actor, &$id) {
                return $service->mutate($actor, 'career_posting', $id, 'unarchive', $this->input('career_posting', ['lock_version' => 2]));
            },
        ];
        foreach ($actions as $action) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $action();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            foreach ($queries as $q) {
                $this->assertDoesNotMatchRegularExpression('/(?:from|join|into|update)\s+"?(?:public_patient_intakes|public_intake_[a-z_]+|patients|patient_[a-z_]+|visits|visit_[a-z_]+|queue_[a-z_]+|clinical_[a-z_]+|encounters|treatment_[a-z_]+|invoices|invoice_[a-z_]+|payments|payment_[a-z_]+|dispensary_[a-z_]+|stock_[a-z_]+|inventory_[a-z_]+|clinician_credentials|staff_schedules|staff_rosters)"?\b/i', $q['query']);
                if (preg_match('/(?:from|join)\s+"?(?:users|staff_profiles|staff_branch_assignments)"?\b/i', $q['query'])) {
                    $this->assertContains([$q['query'], $q['bindings']], $authority);
                }
            }
            $this->assertSame($before, $this->rows(true));
        }
        foreach (DB::table('audit_logs')->where('event', 'like', 'website.people.%')->get() as $audit) {
            $this->assertStringNotContainsString('MANUAL-CONTENT-SENTINEL', $audit->metadata);
            $this->assertSame(['field_names', 'item_id', 'language', 'revision_number', 'version'], collect(array_keys(json_decode($audit->metadata, true)))->sort()->values()->all());
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_audit_failure_rolls_back_every_action_and_additive_grants_replay(): void
    {
        $actor = $this->actor('hr_manager');
        $this->selectBranch($actor);
        $id = app(S::class)->mutate($actor, 'doctor_profile', null, 'save', $this->input());
        $migration = require database_path('migrations/2026_10_24_000400_grant_website_people_permissions.php');
        Role::findByName('marketing')->givePermissionTo('dashboard.view.own');
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $archived = app(S::class)->mutate($actor, 'doctor_profile', null, 'save', $this->input());
        app(S::class)->mutate($actor, 'doctor_profile', $archived, 'archive', $this->input(extra: ['lock_version' => 1]));
        $audit = \Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->andReturnNull();
        $this->app->instance(AuditRecorder::class, $audit);
        foreach ([['save', null, 0], ['save', $id, 1], ['archive', $id, 1], ['unarchive', $archived, 2]] as [$action, $target, $version]) {
            $before = $this->rows();
            try {
                app(S::class)->mutate($actor, 'doctor_profile', $target, $action, $this->input(extra: ['lock_version' => $version]));
                $this->fail('Audit failure released mutation.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Website audit could not be recorded.', $e->getMessage());
            }
            $this->assertSame($before, $this->rows());
        }
    }
}
