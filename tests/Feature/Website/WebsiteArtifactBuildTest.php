<?php

namespace Tests\Feature\Website;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Website\WebsiteArtifactBudget;
use App\Domain\Organisation\Website\WebsiteArtifactBuildService as Build;
use App\Domain\Organisation\Website\WebsiteArtifactFailure;
use App\Domain\Organisation\Website\WebsiteArtifactSource;
use App\Domain\Organisation\Website\WebsiteArtifactStore;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsitePermissions;
use App\Domain\Organisation\Website\WebsiteStaticRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class WebsiteArtifactBuildTest extends WebsiteArtifactTestCase
{
    public function test_rich_frozen_build_is_deterministic_and_the_private_index_verifies(): void
    {
        [$actor,$release,$plan] = $this->rich();
        $source = app(WebsiteArtifactSource::class)->read($actor->organisation_id, $release, $plan);
        app(WebsiteStaticRenderer::class)->render($source['dto']);
        $service = app(Build::class);
        $id = $service->build($actor, $release, $this->buildInput($release, $plan));
        $attempt = DB::table('website_artifact_attempts')->where('id', $id)->first();
        $this->assertSame('built', $attempt->state, $attempt->failure_code ?? '');
        $a = DB::table('website_artifacts')->where('id', $attempt->artifact_id)->first();
        $files = app(WebsiteArtifactStore::class)->verify($actor->organisation_id, $a->id);
        $this->assertArrayHasKey('service-detail/index.html', $files);
        $this->assertArrayHasKey('data/services.bm.json', $files);
        $this->assertArrayHasKey('bm/index.html', $files);
        $this->assertSame($a->file_count, count($files));
        $second = $service->build($actor, $release, $this->buildInput($release, $plan));
        $b = DB::table('website_artifacts')->where('attempt_id', $second)->first();
        $this->assertSame($a->artifact_hash, $b->artifact_hash);
        $this->assertSame($a->file_manifest, $b->file_manifest);
    }

    public function test_completion_manifest_uses_byte_order_even_with_a_locale_sorted_path_column(): void
    {
        DB::statement("CREATE COLLATION IF NOT EXISTS artifact_test_locale (provider = icu, locale = 'en-US')");
        DB::statement('ALTER TABLE website_artifact_files ALTER COLUMN path TYPE varchar(180) COLLATE artifact_test_locale');
        $paths = ['404.html', '_headers'];
        $localeOrder = DB::select("SELECT path FROM (VALUES ('404.html'), ('_headers')) AS files(path) ORDER BY path COLLATE artifact_test_locale");
        $this->assertNotSame($paths, array_column($localeOrder, 'path'));

        [$actor, $release, $plan] = $this->rich();
        $id = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
        $attempt = DB::table('website_artifact_attempts')->where('id', $id)->first();
        $this->assertSame('built', $attempt->state, $attempt->failure_code ?? '');
        $artifact = DB::table('website_artifacts')->where('id', $attempt->artifact_id)->first();
        $manifest = json_decode($artifact->file_manifest, true, 512, JSON_THROW_ON_ERROR);
        $byteOrder = DB::table('website_artifact_files')->where('artifact_id', $artifact->id)->orderByRaw('path COLLATE "C"')->pluck('path')->all();
        $this->assertSame($byteOrder, array_column($manifest, 'path'));
        $verifiedPaths = array_keys(app(WebsiteArtifactStore::class)->verify($actor->organisation_id, $artifact->id));
        sort($verifiedPaths, SORT_STRING);
        $this->assertSame($byteOrder, $verifiedPaths);
    }

    public function test_reader_isolation_renderer_purity_and_private_sentinels(): void
    {
        [$actor,$release,$plan] = $this->rich();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $source = app(WebsiteArtifactSource::class)->read($actor->organisation_id, $release, $plan);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $q) {
            $this->assertMatchesRegularExpression('/from "(?:website_releases|website_release_items|website_release_media|website_release_controls|website_output_plans|website_output_plan_assets|website_output_plan_controls)"/', $q['query']);
        }
        Http::preventStrayRequests();
        Storage::shouldReceive('disk')->never();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $files = app(WebsiteStaticRenderer::class)->render($source['dto']);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        Http::assertNothingSent();
        foreach ($files as $path => $file) {
            foreach (['PRIVATE_RIGHTS_SENTINEL', 'PRIVATE_CONSENT_SENTINEL', 'PRIVATE_PRICE_SENTINEL', 'PRIVATE_REGISTRATION_SENTINEL', 'PRIVATE_PROFILE_CONSENT_SENTINEL', 'PRIVATE_OFFER_SENTINEL', 'source_revision_id', 'revision_hash', 'created_by_user_id', 'encrypted_bytes', 'storage/app', 'registration_display_reference', 'price_display_reference'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $path.$file['bytes']);
            }
        }
    }

    public function test_generated_html_links_metadata_and_local_resources_are_complete(): void
    {
        [$actor,$release,$plan] = $this->rich();
        $source = app(WebsiteArtifactSource::class)->read($actor->organisation_id, $release, $plan);
        $files = app(WebsiteStaticRenderer::class)->render($source['dto']);
        foreach ($files as $path => $file) {
            if (! str_ends_with($path, '.html')) {
                continue;
            }
            libxml_use_internal_errors(true);
            libxml_clear_errors();
            $doc = new \DOMDocument;
            $this->assertTrue($doc->loadHTML($file['bytes']));
            $this->assertSame([], array_map(fn ($e) => trim($e->message), libxml_get_errors()), $path);
            $xpath = new \DOMXPath($doc);
            $this->assertSame(1, $xpath->query('//h1')->length, $path);
            $this->assertSame(str_starts_with($path, 'bm/') ? 'ms' : 'en', $doc->documentElement->getAttribute('lang'));
            $this->assertSame(2, $xpath->query('//link[@rel="alternate"]')->length);
            $this->assertSame('noindex,nofollow', $xpath->query('//meta[@name="robots"]')->item(0)->getAttribute('content'));
            $this->assertSame(0, $xpath->query('//script[not(@src)]|//*[@style]|//*[@onclick]|//form|//iframe')->length);
            foreach ($xpath->query('//img') as $image) {
                $this->assertTrue($image->hasAttribute('alt'));
            }
            foreach ($xpath->query('//*[@href or @src]') as $node) {
                if (in_array($node->nodeName, ['link', 'meta'], true)) {
                    continue;
                }
                foreach (['href', 'src'] as $attribute) {
                    if (! $node->hasAttribute($attribute)) {
                        continue;
                    }
                    $url = $node->getAttribute($attribute);
                    if (str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'https://')) {
                        continue;
                    }
                    $target = explode('?', explode('#', $url)[0])[0];
                    $parts = [];
                    foreach (explode('/', (dirname($path) === '.' ? '' : dirname($path).'/').$target) as $part) {
                        if ($part === '..') {
                            array_pop($parts);
                        } elseif ($part !== '.' && $part !== '') {
                            $parts[] = $part;
                        }
                    }
                    $resolved = implode('/', $parts);
                    if ($resolved === '' || str_ends_with($target, '/')) {
                        $resolved .= ($resolved === '' ? '' : '/').'index.html';
                    }
                    $this->assertArrayHasKey($resolved, $files, $path.' -> '.$url);
                }
            }
        }
        foreach (['sitemap.xml', 'sitemap-services.xml'] as $path) {
            $doc = new \DOMDocument;
            $this->assertTrue($doc->loadXML($files[$path]['bytes']));
        }
        $this->assertStringContainsString('Disallow: /', $files['robots.txt']['bytes']);
        $this->assertStringContainsString("default-src 'none'", $files['_headers']['bytes']);
        $this->assertStringNotContainsString('innerHTML', $files['assets/site.js']['bytes']);
        $this->assertDoesNotMatchRegularExpression('/\beval\s*\(/', $files['assets/site.js']['bytes']);
        $this->assertStringNotContainsString('https://', $files['assets/site.css']['bytes']);
        foreach ($source['dto']['design']['tokens'] as $token => $value) {
            $this->assertStringContainsString('--'.$token.': '.$value, $files['assets/site.css']['bytes']);
        }
        $this->assertStringNotContainsString('__', $files['assets/site.css']['bytes']);
    }

    public function test_build_changes_no_source_rows_and_live_edits_do_not_change_historical_output(): void
    {
        $this->finalizedFixture();
        [$actor,$release,$plan,$service,$doctor,$image] = $this->rich();
        $snapshot = function (): array {
            $out = [];
            foreach (Schema::getTableListing() as $table) {
                $base = basename(str_replace('.', '/', $table));
                if (str_starts_with($base, 'website_artifact') || $base === 'audit_logs') {
                    continue;
                }
                $q = DB::table($table);
                $cols = Schema::getColumnListing($table);
                foreach (in_array('id', $cols, true) ? ['id'] : $cols as $col) {
                    $q->orderBy($col);
                }
                $out[$table] = $q->get()->toJson();
            }

            return $out;
        };
        $before = $snapshot();
        $id = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
        $this->assertSame($before, $snapshot());
        $a = DB::table('website_artifacts')->where('attempt_id', $id)->first();
        $this->assertNotNull($a);
        $original = app(WebsiteArtifactStore::class)->verify($actor->organisation_id, $a->id);
        $this->updateSource($service, ['name' => 'LIVE_DRAFT_SENTINEL']);
        $actor->forceFill(['name' => 'LIVE_STAFF_SENTINEL'])->save();
        app(WebsiteDraftService::class)->retire($actor, $image->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $image->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame($original, app(WebsiteArtifactStore::class)->verify($actor->organisation_id, $a->id));
        $again = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
        $b = DB::table('website_artifacts')->where('attempt_id', $again)->first();
        $this->assertSame($a->artifact_hash, $b->artifact_hash);
        foreach (DB::table('audit_logs')->where('event', 'like', 'website.artifact.%')->get() as $log) {
            foreach (['LIVE_', 'PRIVATE_', 'clinic.test', 'Synthetic', 'stage_token', 'storage_key'] as $value) {
                $this->assertStringNotContainsString($value, $log->metadata);
            }
        }
    }

    public function test_every_role_http_build_access_and_fresh_revocation(): void
    {
        [$director,$release,$plan] = $this->rich(false);
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $allowed = in_array($role, ['director', 'marketing', 'administrator'], true);
            $this->assertSame($allowed, $actor->can(WebsitePermissions::PREPARE));
            $response = $this->post('/website/releases/'.$release.'/builds', $this->buildInput($release, $plan));
            if ($allowed) {
                $response->assertRedirect();
            } else {
                $response->assertForbidden();
            }
        }
        $this->selectBranch($director);
        $permission = Permission::findByName(WebsitePermissions::PREPARE);
        DB::table('role_has_permissions')->where('role_id', $director->roles()->first()->id)->where('permission_id', $permission->id)->delete();
        $this->post('/website/releases/'.$release.'/builds', $this->buildInput($release, $plan))->assertForbidden();
    }

    public function test_retry_conflict_stale_versions_and_source_withdrawal(): void
    {
        [$actor,$release,$plan] = $this->rich();
        $service = app(Build::class);
        $input = $this->buildInput($release, $plan);
        $id = $service->build($actor, $release, $input);
        $this->assertSame($id, $service->build($actor, $release, $input));
        $this->assertSame(1, DB::table('website_artifact_attempts')->count());
        $this->failure(fn () => $service->build($actor, $release, [...$input, 'plan_version' => 1]), 'idempotency_key');
        $this->planDecision($actor, $plan, 'withdraw');
        $this->failure(fn () => $service->build($actor, $release, $this->buildInput($release, $plan)), 'approval');
        $this->assertSame(1, DB::table('website_artifacts')->count());
        $this->assertSame($id, $service->build($actor, $release, $input));
    }

    public function test_budget_failures_refuse_without_any_partial_artifact(): void
    {
        [$actor,$release,$plan] = $this->rich();
        foreach ([
            new WebsiteArtifactBudget(1),
            new WebsiteArtifactBudget(2000, 1),
            new WebsiteArtifactBudget(2000, 262144000, -1),
            new WebsiteArtifactBudget(2000, 262144000, 30, 1),
        ] as $budget) {
            $this->app->instance(WebsiteArtifactBudget::class, $budget);
            $this->app->forgetInstance(Build::class);
            $id = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
            $this->assertSame('failed', DB::table('website_artifact_attempts')->where('id', $id)->value('state'));
            $this->assertSame(0, DB::table('website_artifacts')->count());
            $this->assertSame([], Storage::disk('website_artifacts')->allFiles());
        }
    }

    public function test_final_withdrawal_blocks_completion_and_staging_is_removed(): void
    {
        [$actor,$release,$plan] = $this->rich();
        $real = app(WebsiteStaticRenderer::class);
        $calls = 0;
        $fake = \Mockery::mock(WebsiteStaticRenderer::class)->makePartial();
        $fake->shouldReceive('recipe')->andReturn($real->recipe());
        $fake->shouldReceive('render')->andReturnUsing(function ($dto) use ($real, $actor, $plan, &$calls) {
            if (++$calls === 1) {
                $this->planDecision($actor, $plan, 'withdraw');
            }

            return $real->render($dto);
        });
        $this->app->instance(WebsiteStaticRenderer::class, $fake);
        $id = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
        $this->assertSame('failed', DB::table('website_artifact_attempts')->where('id', $id)->value('state'));
        $this->assertSame(0, DB::table('website_artifacts')->count());
        $this->assertSame([], Storage::disk('website_artifacts')->allFiles());
    }

    public function test_audit_failure_at_completion_rolls_back_header_index_and_counter(): void
    {
        [$actor,$release,$plan] = $this->rich();
        $original = app(AuditRecorder::class);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($original) {
            return $args[0] === 'website.artifact.built' ? null : $original->record(...$args);
        });
        $id = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
        $this->assertSame('failed', DB::table('website_artifact_attempts')->where('id', $id)->value('state'));
        $this->assertSame('audit', DB::table('website_artifact_attempts')->where('id', $id)->value('failure_code'));
        foreach (['website_artifacts', 'website_artifact_files', 'website_artifact_counters'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame([], Storage::disk('website_artifacts')->allFiles());
    }

    public function test_requested_audit_failure_leaves_no_attempt_or_files(): void
    {
        [$actor,$release,$plan] = $this->rich();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andReturnNull();
        try {
            app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
            $this->fail('Audit failure accepted');
        } catch (WebsiteArtifactFailure $e) {
            $this->assertSame('audit', $e->failureCode);
        }
        $this->assertSame(0, DB::table('website_artifact_attempts')->count());
        $this->assertSame([], Storage::disk('website_artifacts')->allFiles());
    }

    public function test_tampered_generated_bytes_are_refused_and_retained_media_are_references(): void
    {
        [$actor,$release,$plan] = $this->rich();
        $id = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
        $a = DB::table('website_artifacts')->where('attempt_id', $id)->first();
        $files = DB::table('website_artifact_files')->where('artifact_id', $a->id)->get();
        $this->assertNotEmpty($files->where('storage_kind', 'release_media')->all());
        $this->assertSame($files->where('storage_kind', 'generated')->count(), count(Storage::disk('website_artifacts')->allFiles()));
        $generated = $files->firstWhere('storage_kind', 'generated');
        $raw = Storage::disk('website_artifacts')->get($generated->storage_key);
        $this->assertStringNotContainsString('<html', $raw);
        Storage::disk('website_artifacts')->put($generated->storage_key, 'tampered');
        try {
            app(WebsiteArtifactStore::class)->verify($actor->organisation_id, $a->id);
            $this->fail('Tampered file accepted');
        } catch (WebsiteArtifactFailure $e) {
            $this->assertSame('integrity', $e->failureCode);
        }
    }

    public function test_stale_build_lease_is_reconciled_by_a_new_authorised_request(): void
    {
        [$actor,$release,$plan] = $this->rich();
        $token = (string) Str::uuid();
        $id = DB::table('website_artifact_attempts')->insertGetId(['organisation_id' => $actor->organisation_id, 'release_id' => $release, 'plan_id' => $plan, 'actor_user_id' => $actor->id, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'stage_token' => $token, 'state' => 'requested', 'lock_version' => 1, 'lease_until' => now()->utc()->subMinutes(5)->toIso8601String(), 'created_at' => now()->utc()->subMinutes(6)->toIso8601String()]);
        DB::table('website_artifact_attempts')->where('id', $id)->update(['state' => 'building', 'lock_version' => 2]);
        $disk = Storage::disk('website_artifacts');
        $disk->put('attempts/'.$token.'/orphan.enc', 'synthetic');
        $disk->put('unrelated/keep.enc', 'synthetic');
        app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
        $this->assertSame('failed', DB::table('website_artifact_attempts')->where('id', $id)->value('state'));
        $this->assertSame('interrupted', DB::table('website_artifact_attempts')->where('id', $id)->value('failure_code'));
        $this->assertFalse($disk->exists('attempts/'.$token.'/orphan.enc'));
        $this->assertTrue($disk->exists('unrelated/keep.enc'));
    }

    public function test_postgresql_refuses_immutable_changes_and_late_file_insertion(): void
    {
        [$actor,$release,$plan] = $this->rich();
        $id = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
        $a = DB::table('website_artifacts')->where('attempt_id', $id)->first();
        foreach (['website_artifacts', 'website_artifact_files', 'website_artifact_actions', 'website_artifact_attempts', 'website_artifact_counters'] as $table) {
            $count = DB::table($table)->count();
            foreach (['DELETE FROM '.$table, 'TRUNCATE '.$table.' CASCADE'] as $sql) {
                try {
                    DB::statement($sql);
                    $this->fail('Immutable statement accepted');
                } catch (QueryException $e) {
                    $this->assertSame('P0001', $e->errorInfo[0]);
                }
                $this->assertSame($count, DB::table($table)->count());
            }
        }
        try {
            DB::table('website_artifacts')->where('id', $a->id)->update(['number' => 'changed']);
            $this->fail('Update accepted');
        } catch (QueryException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
        }
        $file = (array) DB::table('website_artifact_files')->where('artifact_id', $a->id)->first();
        unset($file['id']);
        $file['path'] = 'late.html';
        try {
            DB::table('website_artifact_files')->insert($file);
            $this->fail('Late index append accepted');
        } catch (QueryException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
        }
    }

    public function test_source_approval_binding_branch_and_organisation_are_required(): void
    {
        [$actor,$release,$plan] = $this->rich(false);
        $input = $this->buildInput($release, $plan);
        try {
            app(Build::class)->build($actor, $release, [...$input, 'release_version' => 99]);
            $this->fail('Stale source accepted');
        } catch (WebsiteArtifactFailure $e) {
            $this->assertSame('version', $e->failureCode);
        }
        $this->failure(fn () => app(Build::class)->build($actor, $release, [...$input, 'expected_branch_id' => 999999]), 'branch');
        $unapproved = $this->plan($actor, $release);
        $this->failure(fn () => app(Build::class)->build($actor, $release, $this->buildInput($release, $unapproved)), 'approval');
        $this->planDecision($actor, $unapproved, 'refuse');
        $this->failure(fn () => app(Build::class)->build($actor, $release, $this->buildInput($release, $unapproved)), 'approval');
        $other = $this->prepare($actor);
        $this->decision($actor, $other, 'approve');
        $this->actingAs($actor)->post('/website/releases/'.$other.'/builds', $this->buildInput($release, $plan))->assertNotFound();
        $preparedPlan = $this->plan($actor, $other);
        $this->planDecision($actor, $preparedPlan, 'approve');
        $this->decision($actor, $other, 'withdraw');
        $this->failure(fn () => app(Build::class)->build($actor, $other, $this->buildInput($other, $preparedPlan)), 'approval');
        $this->assertSame(0, DB::table('website_artifacts')->count());
        try {
            app(WebsiteArtifactSource::class)->read($actor->organisation_id + 99999, $release, $plan);
            $this->fail('Foreign organisation accepted');
        } catch (WebsiteArtifactFailure $e) {
            $this->assertSame('approval', $e->failureCode);
        }
    }

    public function test_plan_only_logo_is_referenced_without_copying_and_integrity_helpers_refuse_tampering(): void
    {
        [$actor,$release,$plan] = $this->rich();
        $id = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
        $a = DB::table('website_artifacts')->where('attempt_id', $id)->first();
        $ref = DB::table('website_artifact_files')->where('artifact_id', $a->id)->where('storage_kind', 'plan_asset')->sole();
        $this->assertNull($ref->storage_key);
        $this->assertNull($ref->release_media_id);
        $this->assertNotNull($ref->plan_asset_id);
        $this->assertArrayHasKey($ref->path, app(WebsiteArtifactStore::class)->verify($actor->organisation_id, $a->id));
        $source = app(WebsiteArtifactSource::class);
        $method = new \ReflectionMethod($source, 'json');
        foreach (['website_releases' => 'manifest', 'website_output_plans' => 'content'] as $table => $column) {
            $row = DB::table($table)->where('id', $table === 'website_releases' ? $release : $plan)->first();
            $hash = $table === 'website_releases' ? $row->manifest_hash : $row->plan_hash;
            try {
                $method->invoke($source, $row->$column.' ', $hash);
                $this->fail('Tampered canonical evidence accepted');
            } catch (WebsiteArtifactFailure $e) {
                $this->assertSame('integrity', $e->failureCode);
            }
        }
        $row = DB::table('website_output_plan_assets')->where('id', $ref->plan_asset_id)->first();
        foreach (['encrypted_bytes' => 'tampered', 'sha256' => str_repeat('a', 64), 'bytes' => 1, 'mime' => 'text/html', 'width' => 1] as $field => $value) {
            $bad = clone $row;
            $bad->$field = $value;
            try {
                WebsiteArtifactSource::decodeMedia($bad);
                $this->fail('Tampered media accepted');
            } catch (WebsiteArtifactFailure $e) {
                $this->assertSame('integrity', $e->failureCode);
            }
        }
    }

    public function test_non_determinism_and_permission_revocation_during_render_release_nothing(): void
    {
        [$actor,$release,$plan] = $this->rich(false);
        $real = app(WebsiteStaticRenderer::class);
        foreach (['determinism', 'authority'] as $mode) {
            $calls = 0;
            $fake = \Mockery::mock(WebsiteStaticRenderer::class)->makePartial();
            $fake->shouldReceive('recipe')->andReturn($real->recipe());
            $fake->shouldReceive('render')->andReturnUsing(function ($dto) use ($real, $actor, $mode, &$calls) {
                $files = $real->render($dto);
                if ($mode === 'determinism') {
                    $files['index.html']['bytes'] .= (string) ++$calls;
                } elseif (++$calls === 1) {
                    $permission = Permission::findByName(WebsitePermissions::PREPARE);
                    DB::table('role_has_permissions')->where('role_id', $actor->roles()->first()->id)->where('permission_id', $permission->id)->delete();
                }

                return $files;
            });
            $this->app->instance(WebsiteStaticRenderer::class, $fake);
            $this->app->forgetInstance(Build::class);
            $id = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
            $this->assertSame('failed', DB::table('website_artifact_attempts')->where('id', $id)->value('state'));
            $this->assertSame(0, DB::table('website_artifacts')->count());
            $this->assertSame([], Storage::disk('website_artifacts')->allFiles());
        }
    }

    public function test_postgresql_deferred_completeness_and_terminal_versions_are_enforced(): void
    {
        [$actor,$release,$plan] = $this->rich(false);
        $id = app(Build::class)->build($actor, $release, $this->buildInput($release, $plan));
        $header = (array) DB::table('website_artifacts')->where('attempt_id', $id)->sole();
        $count = DB::table('website_artifact_attempts')->count();
        try {
            DB::transaction(function () use ($id, $header): void {
                $attempt = (array) DB::table('website_artifact_attempts')->where('id', $id)->first();
                unset($attempt['id']);
                $attempt = [...$attempt, 'idempotency_key' => (string) Str::uuid(), 'stage_token' => (string) Str::uuid(), 'state' => 'requested', 'lock_version' => 1, 'artifact_id' => null];
                $next = DB::table('website_artifact_attempts')->insertGetId($attempt);
                DB::table('website_artifact_attempts')->where('id', $next)->update(['state' => 'building', 'lock_version' => 2]);
                unset($header['id']);
                $copy = DB::table('website_artifacts')->insertGetId([...$header, 'attempt_id' => $next, 'number' => 'WEBBUILD/2026/999999']);
                DB::table('website_artifact_attempts')->where('id', $next)->update(['state' => 'built', 'lock_version' => 3, 'artifact_id' => $copy]);
                // No index rows: the deferred trigger must refuse commit, not leave a visible header.
            });
            $this->fail('Incomplete index accepted');
        } catch (\PDOException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
            $this->assertStringContainsString('Incomplete built artifact', $e->getMessage());
        }
        $this->assertSame($count, DB::table('website_artifact_attempts')->count());
        $this->assertSame(1, DB::table('website_artifacts')->count());
        foreach (['website_artifact_files' => ['mime' => 'text/plain'], 'website_artifact_actions' => ['action' => 'requested'], 'website_artifact_attempts' => ['lock_version' => 4], 'website_artifact_counters' => ['last_number' => 0]] as $table => $changes) {
            $before = DB::table($table)->orderBy('id')->get()->toJson();
            try {
                DB::table($table)->update($changes);
                $this->fail('Invalid change accepted');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
            $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }
}
