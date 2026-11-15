<?php

namespace Tests\Feature\Website;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Website\WebsiteAccess;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsiteEditorialFields;
use App\Domain\Organisation\Website\WebsiteLittleShieldFields;
use App\Domain\Organisation\Website\WebsiteOutputPlanFields as Fields;
use App\Domain\Organisation\Website\WebsiteOutputPlanService as Plan;
use App\Domain\Organisation\Website\WebsiteOutputProfile;
use App\Domain\Organisation\Website\WebsitePeopleFields;
use App\Domain\Organisation\Website\WebsitePermissions as P;
use App\Domain\Organisation\Website\WebsiteProfileFields;
use App\Domain\Organisation\Website\WebsiteReleaseJson as J;
use App\Domain\Organisation\Website\WebsiteReleaseService as S;
use App\Domain\Organisation\Website\WebsiteServiceFields;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Billing\BillingTestCase;

class WebsiteOutputPlanTest extends BillingTestCase
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

    private function approved(): array
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $release = $this->prepare($actor);
        $this->decision($actor, $release, 'approve');

        return [$actor, $release];
    }

    private function planInput(int $release, array $changes = []): array
    {
        $manifest = json_decode(DB::table('website_releases')->where('id', $release)->value('manifest'), true);
        $aliases = [];
        foreach (Fields::targets($manifest) as $i => $target) {
            $aliases[$target['key']] = $target['kind'].'-synthetic-'.$i;
        }

        return [...$this->input(), 'release_id' => $release, 'content' => ['public_origin' => 'https://clinic.test', 'aliases' => $aliases, 'pages' => ['home', 'branches', 'contact'], 'footer' => ['en' => 'Synthetic footer', 'bm' => 'Pengaki sintetik'], 'logo_public_id' => null], ...$changes];
    }

    private function plan($actor, int $release, array $changes = []): int
    {
        return app(Plan::class)->mutate($actor, 'prepare', null, $this->planInput($release, $changes));
    }

    private function planDecision($actor, int $id, string $op, array $changes = []): int
    {
        $p = DB::table('website_output_plans')->where('id', $id)->first();
        $c = DB::table('website_output_plan_controls')->where('plan_id', $id)->first();

        return app(Plan::class)->mutate($actor, $op, $id, [...$this->input(), 'release_id' => $p->release_id, 'lock_version' => $c->lock_version, 'plan_hash' => $p->plan_hash, 'confirmation' => $p->number, 'reason' => 'Synthetic private decision', ...$changes]);
    }

    private function snapshot(bool $all = false): array
    {
        $out = [];
        foreach (Schema::getTableListing() as $table) {
            if (! $all && (str_starts_with(basename(str_replace('.', '/', $table)), 'website_output_plan') || basename(str_replace('.', '/', $table)) === 'audit_logs')) {
                continue;
            }
            $cols = Schema::getColumnListing($table);
            $q = DB::table($table);
            foreach (in_array('id', $cols, true) ? ['id'] : $cols as $column) {
                $q->orderBy($column);
            }$out[$table] = $q->get()->toJson();
        }

        return $out;
    }

    public function test_exact_existing_grants_and_every_catalogue_role_http_access(): void
    {
        [$director,$release] = $this->approved();
        $id = $this->plan($director, $release);
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $allowed = in_array($role, ['director', 'marketing', 'administrator'], true);
            $this->assertSame($allowed, $actor->can(P::PREPARE));
            $this->assertSame($role === 'director', $actor->can(P::APPROVE));
            foreach (['/website/releases/output-plans', '/website/releases/output-plans/'.$id, '/website/releases/'.$release.'/output-plans'] as $path) {
                $response = $this->get($path);
                if ($allowed) {
                    $response->assertOk();
                } else {
                    $response->assertForbidden();
                }
            }
            if ($allowed) {
                $this->post('/website/releases/'.$release.'/output-plans', $this->planInput($release))->assertRedirect();
            } else {
                $this->post('/website/releases/'.$release.'/output-plans', $this->planInput($release))->assertForbidden();
            }
            if ($role !== 'director') {
                foreach (['approve', 'refuse', 'withdraw'] as $op) {
                    $this->post('/website/releases/output-plans/'.$id.'/'.$op, $this->planInput($release))->assertForbidden();
                }
            }
        }
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->app['auth']->forgetGuards();
        $this->get('/website/releases/output-plans')->assertRedirect('/login');
    }

    public function test_release_state_at_creation_guard_and_fresh_revocation_scope(): void
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $r = $this->prepare($actor);
        $this->failure(fn () => $this->plan($actor, $r), 'release_id');
        $this->decision($actor, $r, 'refuse');
        $this->failure(fn () => $this->plan($actor, $r), 'release_id');
        $r = $this->prepare($actor);
        $this->decision($actor, $r, 'approve');
        $id = $this->plan($actor, $r);
        $this->decision($actor, $r, 'withdraw');
        $this->failure(fn () => $this->plan($actor, $r), 'release_id');
        $raw = (array) DB::table('website_output_plans')->where('id', $id)->first();
        unset($raw['id']);
        $raw['number'] = 'WEBPLAN/2026/900001';
        try {
            DB::table('website_output_plans')->insert($raw);
            $this->fail('Insert accepted withdrawn release');
        } catch (QueryException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
        }
        $other = Organisation::create(['code' => 'PLAN_OTHER', 'name' => 'Synthetic other']);
        $branch = (new Branch)->forceFill(['organisation_id' => $other->id, 'code' => 'PLAN_OTHER', 'name' => 'Synthetic other', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $branch->save();
        $foreign = $this->actor('director', $branch);
        $this->selectBranch($foreign, $branch);
        $this->get('/website/releases/output-plans/'.$id)->assertNotFound();
        $this->post('/website/releases/'.$r.'/output-plans', $this->planInput($r, ['expected_branch_id' => $branch->id]))->assertNotFound();
        $this->selectBranch($actor);
        $this->withSession([BranchAccessService::SESSION_KEY => $branch->id])->get('/website/releases/output-plans')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('outputPlans.branchId', $this->branch->id));
        $this->postJson('/website/releases/'.$r.'/output-plans', $this->planInput($r, ['expected_branch_id' => $branch->id]))->assertUnprocessable()->assertJsonValidationErrors('expected_branch_id');
        $this->selectBranch($actor);
        DB::table('role_has_permissions')->where('role_id', $actor->roles()->first()->id)->where('permission_id', Permission::findByName(P::PREPARE)->id)->delete();
        $this->get('/website/releases/output-plans/'.$id)->assertForbidden();
        $this->post('/website/releases/'.$r.'/output-plans', $this->planInput($r))->assertForbidden();
    }

    public function test_all_alias_errors_coverage_duplicates_and_unknown_keys_are_returned_without_number(): void
    {
        [$actor,$r] = $this->approved();
        $input = $this->planInput($r);
        $keys = array_keys($input['content']['aliases']);
        $input['content']['aliases'] = [$keys[0] => 'duplicate', $keys[1] => 'duplicate', $keys[2] => 'Bad--alias', 'item-unknown' => 'unknown'];
        $errors = $this->failure(fn () => app(Plan::class)->mutate($actor, 'prepare', null, $input));
        $this->assertCount(4, $errors);
        $input['content']['aliases'] = [];
        $errors = $this->failure(fn () => app(Plan::class)->mutate($actor, 'prepare', null, $input));
        $this->assertCount(count($keys), $errors);
        foreach (['ab', '-abc', 'abc-', 'abc--def', 'ABC', 'has space', 'abc/def', 'abc?def', 'abc.def', str_repeat('a', 61), "a\u{202e}bc", '<b>bad</b>', '**bad**'] as $bad) {
            $input = $this->planInput($r);
            $input['content']['aliases'][$keys[0]] = $bad;
            $this->failure(fn () => app(Plan::class)->mutate($actor, 'prepare', null, $input), 'aliases');
        }
        $this->assertSame(0, DB::table('website_output_plans')->count());
        $this->assertSame(0, DB::table('website_output_plan_counters')->count());
        $category = $this->source('service_category', ['name' => 'Synthetic category', 'short_description' => 'Summary', 'include_on_website' => true]);
        $publicId = DB::table('website_content_items')->where('id', $category)->value('public_id');
        $this->source('public_service', ['name' => 'Synthetic service', 'short_summary' => 'Summary', 'seo_title' => 'Synthetic', 'seo_description' => 'Synthetic description', 'parent_public_id' => $publicId, 'description_sections' => [['heading' => 'Heading', 'body' => 'Body']], 'include_on_website' => true]);
        $r2 = $this->prepare($actor);
        $this->decision($actor, $r2, 'approve');
        $input = $this->planInput($r2);
        $this->assertCount(5, $input['content']['aliases']);
        $this->assertIsInt($this->plan($actor, $r2));
    }

    public function test_origin_pages_footer_profiles_and_all_input_bounds(): void
    {
        [$actor,$r] = $this->approved();
        foreach (['http://clinic.test', 'https://clinic.test/', 'https://clinic.test/path', 'https://clinic.test?q=x', 'https://clinic.test#x', 'https://a@clinic.test', 'https://clinic.test:443', 'https://localhost', 'https://127.0.0.1', 'https://bad_host.test', 'https://-bad.test', str_repeat('x', 101), "https://clinic.test\n"] as $origin) {
            $i = $this->planInput($r);
            $i['content']['public_origin'] = $origin;
            $this->failure(fn () => app(Plan::class)->mutate($actor, 'prepare', null, $i), 'public_origin');
        }
        foreach ([[], ['doctors'], ['about'], ['home', 'home'], [['nested']]] as $pages) {
            $i = $this->planInput($r);
            $i['content']['pages'] = $pages;
            $this->failure(fn () => app(Plan::class)->mutate($actor, 'prepare', null, $i), 'pages');
        }
        foreach ([str_repeat('x', 201), '<b>text</b>', "bad\u{200b}", '**markup**', "bad\x01"] as $footer) {
            $i = $this->planInput($r);
            $i['content']['footer']['en'] = $footer;
            $this->failure(fn () => app(Plan::class)->mutate($actor, 'prepare', null, $i), 'footer');
        }
        $i = $this->planInput($r);
        $i['content']['design_profile'] = 'custom';
        $this->failure(fn () => app(Plan::class)->mutate($actor, 'prepare', null, $i), 'design_profile');
        $i = $this->planInput($r);
        $i['content']['public_origin'] = 'HTTPS://CLINIC.TEST';
        $i['content']['footer'] = ['en' => str_repeat('x', 200), 'bm' => ''];
        $id = app(Plan::class)->mutate($actor, 'prepare', null, $i);
        $c = json_decode(DB::table('website_output_plans')->where('id', $id)->value('content'), true);
        $this->assertSame('https://clinic.test', $c['public_origin']);
        $this->assertSame(WebsiteOutputProfile::LANGUAGE, $c['language_policy']);
        $this->assertSame(J::encode(WebsiteOutputProfile::frozen()), J::encode($c['design_profile']));
        $this->assertSame(['home', 'branches', 'contact'], array_keys(WebsiteOutputProfile::LABELS) === [] ? [] : $c['pages']);
    }

    public function test_canonical_hash_retry_conflict_states_versions_and_release_withdrawal(): void
    {
        [$actor,$r] = $this->approved();
        $s = app(Plan::class);
        $i = $this->planInput($r);
        $id = $s->mutate($actor, 'prepare', null, $i);
        $this->assertSame($id, $s->mutate($actor, 'prepare', null, $i));
        $this->failure(fn () => $s->mutate($actor, 'prepare', null, [...$i, 'lock_version' => 1]), 'idempotency_key');
        $second = $this->plan($actor, $r);
        $p = DB::table('website_output_plans')->where('id', $id)->first();
        $this->assertSame($p->plan_hash, DB::table('website_output_plans')->where('id', $second)->value('plan_hash'));
        $this->assertSame(hash('sha256', $p->content), $p->plan_hash);
        $this->assertSame($p->content, J::encode(json_decode($p->content, true)));
        $this->failure(fn () => $this->planDecision($actor, $id, 'approve', ['confirmation' => 'wrong']), 'confirmation');
        $this->failure(fn () => $this->planDecision($actor, $id, 'approve', ['lock_version' => 0]), 'version');
        $this->failure(fn () => $this->planDecision($actor, $id, 'approve', ['plan_hash' => str_repeat('0', 64)]), 'version');
        $approve = [...$this->input(), 'release_id' => $r, 'lock_version' => 1, 'plan_hash' => $p->plan_hash, 'confirmation' => $p->number];
        $s->mutate($actor, 'approve', $id, $approve);
        $this->assertSame($id, $s->mutate($actor, 'approve', $id, $approve));
        $this->assertTrue($s->page($actor, null, $id)['usable']);
        $this->failure(fn () => $this->planDecision($actor, $id, 'approve'), 'state');
        $rows = DB::table('website_output_plans')->orderBy('id')->get()->toJson();
        $controls = DB::table('website_output_plan_controls')->orderBy('plan_id')->get()->toJson();
        $this->decision($actor, $r, 'withdraw');
        $this->assertFalse($s->page($actor, null, $id)['usable']);
        $this->assertSame($rows, DB::table('website_output_plans')->orderBy('id')->get()->toJson());
        $this->assertSame($controls, DB::table('website_output_plan_controls')->orderBy('plan_id')->get()->toJson());
        $this->failure(fn () => $this->planDecision($actor, $second, 'approve'), 'release_id');
        foreach (['', str_repeat('x', 301), "bad\u{202e}", '<b>bad</b>'] as $reason) {
            $this->failure(fn () => $this->planDecision($actor, $id, 'withdraw', ['reason' => $reason]));
        }
        $this->planDecision($actor, $id, 'withdraw');
        $this->planDecision($actor, $second, 'refuse');
        $this->failure(fn () => $this->planDecision($actor, $id, 'approve'), 'release_id');
    }

    public function test_logo_validation_encrypted_retention_and_no_live_fallback(): void
    {
        [$actor,$r] = $this->approved();
        $asset = $this->asset($actor);
        $i = $this->planInput($r);
        $i['content']['logo_public_id'] = $asset->public_id;
        $id = app(Plan::class)->mutate($actor, 'prepare', null, $i);
        $stored = DB::table('website_output_plan_assets')->where('plan_id', $id)->first();
        $bytes = base64_decode(Crypt::decryptString($stored->encrypted_bytes));
        $this->assertSame($asset->sha256, hash('sha256', $bytes));
        $this->assertSame($asset->bytes, strlen($bytes));
        $this->assertNotSame($bytes, $stored->encrypted_bytes);
        $before = DB::table('website_output_plans')->where('id', $id)->value('content');
        foreach (['retired_at' => now(), 'rights_reference' => '', 'alt_en' => '', 'alt_bm' => '', 'consent_reference' => '', 'organisation_id' => Organisation::create(['code' => 'PLAN_MEDIA_OTHER', 'name' => 'Synthetic other'])->id, 'sha256' => str_repeat('0', 64)] as $field => $value) {
            DB::statement('ALTER TABLE website_media_assets DISABLE TRIGGER USER');
            try {
                DB::table('website_media_assets')->where('id', $asset->id)->update([$field => $value]);
            } finally {
                DB::statement('ALTER TABLE website_media_assets ENABLE TRIGGER USER');
            }
            $this->failure(fn () => app(Plan::class)->mutate($actor, 'prepare', null, [...$i, 'idempotency_key' => (string) Str::uuid()]), 'logo');
            $this->assertSame($before, app(Plan::class)->page($actor, null, $id)['detail'] ? DB::table('website_output_plans')->where('id', $id)->value('content') : null);
            DB::statement('ALTER TABLE website_media_assets DISABLE TRIGGER USER');
            try {
                DB::table('website_media_assets')->where('id', $asset->id)->update([$field => $asset->$field]);
            } finally {
                DB::statement('ALTER TABLE website_media_assets ENABLE TRIGGER USER');
            }
        }
        Storage::disk('website_media')->delete($asset->path);
        $this->failure(fn () => app(Plan::class)->mutate($actor, 'prepare', null, [...$i, 'idempotency_key' => (string) Str::uuid()]), 'logo');
        $this->assertSame($before, DB::table('website_output_plans')->where('id', $id)->value('content'));
    }

    public function test_postgresql_immutability_guarded_insert_and_deferred_completeness(): void
    {
        [$actor,$r] = $this->approved();
        $id = $this->plan($actor, $r);
        $before = $this->snapshot(true);
        foreach (['website_output_plans', 'website_output_plan_assets', 'website_output_plan_actions', 'website_output_plan_commands'] as $table) {
            foreach (["UPDATE {$table} SET id=id", "DELETE FROM {$table}", "TRUNCATE {$table} CASCADE"] as $sql) {
                try {
                    DB::statement($sql);
                    $this->fail('Immutable output plan changed');
                } catch (QueryException $e) {
                    $this->assertSame('P0001', $e->errorInfo[0]);
                }$this->assertSame($before, $this->snapshot(true));
            }
        }
        foreach (['DELETE FROM website_output_plan_controls', 'TRUNCATE website_output_plan_controls CASCADE', 'DELETE FROM website_output_plan_counters', 'TRUNCATE website_output_plan_counters CASCADE', 'UPDATE website_output_plan_counters SET last_number=0', "UPDATE website_output_plan_controls SET state='withdrawn',lock_version=2"] as $sql) {
            try {
                DB::statement($sql);
                $this->fail('Guard bypassed');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
        }
        $raw = (array) DB::table('website_output_plans')->where('id', $id)->first();
        unset($raw['id']);
        $raw['number'] = 'WEBPLAN/2026/900002';
        try {
            DB::transaction(fn () => DB::table('website_output_plans')->insert($raw));
            $this->fail('Incomplete evidence accepted');
        } catch (\PDOException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
        }$this->assertSame($before, $this->snapshot(true));
    }

    public function test_audit_failure_rolls_back_every_action_and_metadata_is_values_free(): void
    {
        [$actor,$r] = $this->approved();
        $id = $this->plan($actor, $r);
        $original = app(AuditRecorder::class);
        foreach (['prepare', 'approve', 'refuse', 'withdraw'] as $op) {
            if ($op === 'withdraw') {
                $this->planDecision($actor, $id, 'approve');
            }$before = $this->snapshot(true);
            $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andReturnNull();
            $this->app->forgetInstance(Plan::class);
            try {
                if ($op === 'prepare') {
                    $this->plan($actor, $r);
                } else {
                    $this->planDecision($actor, $id, $op);
                }$this->fail('Audit failure accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('audit', $e->getMessage());
            }$this->assertSame($before, $this->snapshot(true));
            $this->app->instance(AuditRecorder::class, $original);
            $this->app->forgetInstance(Plan::class);
        }
        foreach (DB::table('audit_logs')->where('event', 'like', 'website.output_plan.%')->get() as $a) {
            $keys = array_keys(json_decode($a->metadata, true));
            sort($keys);
            $this->assertSame(['action', 'counts', 'hash_prefix', 'number', 'plan_id', 'release_id'], $keys);
            foreach (['clinic.test', 'Synthetic footer', 'Synthetic private decision', 'Synthetic Visit'] as $value) {
                $this->assertStringNotContainsString($value, $a->metadata);
            }
        }
    }

    public function test_query_boundaries_full_row_invariance_private_headers_and_page_names(): void
    {
        $this->finalizedFixture();
        [$actor,$r] = $this->approved();
        $this->asset($actor);
        $s = app(Plan::class);
        $before = $this->snapshot();
        Http::preventStrayRequests();
        Queue::fake();
        DB::enableQueryLog();
        DB::flushQueryLog();
        DB::transaction(fn () => app(WebsiteAccess::class)->lock($actor, $this->branch->id, P::PREPARE));
        $authority = array_map(fn ($q) => [$q['query'], $q['bindings']], DB::getQueryLog());
        DB::disableQueryLog();
        $id = null;
        foreach ([function () use ($actor, $r, &$id) {
            $id = $this->plan($actor, $r);
        }, function () use ($s, $actor, &$id) {
            $s->page($actor, null, $id);
        }, function () use ($actor, &$id) {
            $this->planDecision($actor, $id, 'approve');
        }, function () use ($actor, &$id) {
            $this->planDecision($actor, $id, 'withdraw');
        }, function () use ($actor, $r) {
            $id2 = $this->plan($actor, $r);
            $this->planDecision($actor, $id2, 'refuse');
        }] as $call) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $call();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $names = 0;
            foreach ($queries as $q) {
                $this->assertDoesNotMatchRegularExpression('/(?:from|join|into|update)\s+"?(?:website_content_[a-z_]+|website_draft_[a-z_]+|business_[a-z_]+|patients|patient_[a-z_]+|visits|visit_[a-z_]+|queue_[a-z_]+|clinical_[a-z_]+|encounters|invoices|invoice_[a-z_]+|payments|payment_[a-z_]+|dispensary_[a-z_]+|stock_[a-z_]+|inventory_[a-z_]+|clinician_credentials)"?\b/i', $q['query']);
                if (preg_match('/(?:from|join)\s+"?(?:users|staff_profiles|staff_branch_assignments)"?\b/i', $q['query'])) {
                    if (preg_match('/\Aselect "name", "id" from "users" where "organisation_id" = \? and "id" in \(\?(?:, \?)*\)\z/', $q['query'])) {
                        $names++;
                        $this->assertSame($actor->organisation_id, $q['bindings'][0]);
                        $this->assertSame([$actor->id], array_slice($q['bindings'], 1));
                    } else {
                        $this->assertContains([$q['query'], $q['bindings']], $authority);
                    }
                }
            }$this->assertLessThanOrEqual(1, $names);
            $this->assertSame($before, $this->snapshot());
        }
        $page = $s->page($actor, null, $id);
        $this->assertSame($actor->name, $page['plans'][0]['creator']);
        foreach ($page['detail']['actions'] as $a) {
            $this->assertSame($actor->name, $a['actor']);
        }
        $this->get('/website/releases/output-plans/'.$id)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_numbers_follow_local_year_and_concurrent_preparations_are_unique(): void
    {
        [$actor,$r] = $this->approved();
        $this->travelTo(CarbonImmutable::parse('2026-12-31T15:59:59Z'));
        $id = $this->plan($actor, $r);
        $this->assertSame('WEBPLAN/2026/000001', DB::table('website_output_plans')->where('id', $id)->value('number'));
        $this->travelTo(CarbonImmutable::parse('2026-12-31T16:00:00Z'));
        $id = $this->plan($actor, $r);
        $this->assertSame('WEBPLAN/2027/000001', DB::table('website_output_plans')->where('id', $id)->value('number'));
        $this->travelBack();
        $dir = sys_get_temp_dir().'/plan-concurrent-'.Str::uuid();
        mkdir($dir);
        DB::purge();
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Fork failed');
            }if ($pid === 0) {
                try {
                    $id = $this->plan($actor, $r);
                    file_put_contents($dir.'/'.$i, (string) $id);
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents($dir.'/'.$i, $e->getMessage());
                    exit(1);
                }
            }$children[] = $pid;
        }foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), implode(' ', array_map('file_get_contents', glob($dir.'/*'))));
        }DB::purge();
        $ids = array_map('file_get_contents', glob($dir.'/*'));
        $this->assertCount(2, array_unique($ids));
        $this->assertSame(4, DB::table('website_output_plans')->count());
    }

    public function test_detail_alias_names_come_only_from_the_bound_frozen_manifest(): void
    {
        [$actor] = $this->approved();
        $category = $this->source('service_category', ['name' => 'Frozen synthetic category', 'short_description' => 'Summary', 'include_on_website' => true]);
        $publicId = DB::table('website_content_items')->where('id', $category)->value('public_id');
        $service = $this->source('public_service', ['name' => 'Frozen synthetic service', 'short_summary' => 'Summary', 'seo_title' => 'Synthetic', 'seo_description' => 'Synthetic description', 'parent_public_id' => $publicId, 'description_sections' => [['heading' => 'Heading', 'body' => 'Body']], 'include_on_website' => true]);
        $release = $this->prepare($actor);
        $this->decision($actor, $release, 'approve');
        $id = $this->plan($actor, $release);
        $this->updateSource($category, ['name' => 'Live draft category sentinel']);
        $this->updateSource($service, ['name' => 'Live draft service sentinel']);
        $before = $this->snapshot(true);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = app(Plan::class)->page($actor, null, $id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/website_content_|website_draft_|business_details/i', $query['query']);
        }
        $rows = $page['detail']['aliasRows'];
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertSame(match ($row['kind']) {
                'branch' => 'Synthetic public branch',
                'category' => 'Frozen synthetic category',
                'service' => 'Frozen synthetic service',
            }, $row['name']);
            $this->assertSame(J::encode($page['detail']['content']['aliases'][$row['key']]), J::encode(['kind' => $row['kind'], 'alias' => $row['alias']]));
        }
        $this->get('/website/releases/output-plans/'.$id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('outputPlans.detail.aliasRows', $rows));
        $this->assertSame($before, $this->snapshot(true));
    }
}
