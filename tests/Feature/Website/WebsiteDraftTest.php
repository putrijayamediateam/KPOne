<?php

namespace Tests\Feature\Website;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Website\WebsiteDraftSeeds;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsiteImage;
use App\Domain\Organisation\Website\WebsitePermissions as P;
use App\Domain\Organisation\Website\WebsiteProfileFields as F;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Billing\BillingTestCase;

class WebsiteDraftTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('website_media');
    }

    private function saveInput(array $overrides = []): array
    {
        return array_replace(['expected_branch_id' => $this->branch->id, 'lock_version' => DB::table('website_content_items')->where('branch_id', $this->branch->id)->value('lock_version'), 'idempotency_key' => (string) Str::uuid(), 'language' => 'en', 'content' => F::empty(), 'hero_asset_public_id' => null], $overrides);
    }

    private function uploadInput(array $overrides = []): array
    {
        return array_replace(['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'alt_en' => 'Synthetic public courtyard', 'alt_bm' => 'Halaman awam sintetik', 'rights_reference' => 'Synthetic owner illustration', 'identifiable_person' => false, 'consent_reference' => ''], $overrides);
    }

    private function imageFile(string $extension = 'png', ?string $raw = null): UploadedFile
    {
        if ($raw === null) {
            $image = imagecreatetruecolor(64, 48);
            imagefill($image, 0, 0, imagecolorallocate($image, 30, 150, 130));
            ob_start();
            match ($extension) {
                'jpg' => imagejpeg($image), 'webp' => imagewebp($image), default => imagepng($image)
            };
            $raw = ob_get_clean();
            imagedestroy($image);
        }
        $path = tempnam(sys_get_temp_dir(), 'w1a-image-');
        file_put_contents($path, $raw);

        return new UploadedFile($path, 'synthetic.'.$extension, match ($extension) {
            'jpg' => 'image/jpeg', 'webp' => 'image/webp', default => 'image/png'
        }, null, true);
    }

    private function refuses(callable $action, string $key = ''): void
    {
        try {
            $action();
            $this->fail('Expected validation refusal.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
            if ($key !== '') {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }
    }

    public function test_exact_catalogue_grants_and_all_routes_for_every_role(): void
    {
        $service = app(WebsiteDraftService::class);
        $owner = $this->actor('director');
        $this->selectBranch($owner);
        $service->upload($owner, $this->uploadInput(), $this->imageFile());
        $asset = DB::table('website_media_assets')->first();
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $view = in_array($role, ['director', 'marketing', 'hr_manager', 'administrator'], true);
            $manage = in_array($role, ['director', 'marketing', 'administrator'], true);
            foreach ([P::VIEW => $view, P::MANAGE => $manage, P::ASSETS => $view] as $permission => $allowed) {
                $this->assertSame($allowed, $actor->can($permission), "$role $permission");
            }
            $this->get('/website/branch-profiles')->assertStatus($view ? 200 : 403);
            $this->get('/website/media')->assertStatus($view ? 200 : 403);
            $this->get('/website/media/'.$asset->public_id.'/image')->assertStatus($view ? 200 : 403);
            $this->post('/website/branch-profiles', $this->saveInput())->assertStatus($manage ? 302 : 403);
            if (! $view) {
                $this->post('/website/media', [...$this->uploadInput(), 'image' => $this->imageFile()])->assertForbidden();
                $this->post('/website/media/'.$asset->public_id.'/retire', ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
            }
        }
    }

    public function test_anonymous_access_and_no_public_media_storage(): void
    {
        foreach (['/website/branch-profiles', '/website/media', '/website/media/'.Str::uuid().'/image'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->assertFalse(config('filesystems.disks.website_media.serve'));
        $this->assertStringNotContainsString('/public/', config('filesystems.disks.website_media.root'));
    }

    public function test_fresh_revocation_is_enforced_even_with_loaded_actor_relations(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $actor->load('roles.permissions');
        $this->assertTrue($actor->can(P::MANAGE));
        Role::findByName('marketing')->revokePermissionTo(P::MANAGE);
        try {
            app(WebsiteDraftService::class)->save($actor, $this->saveInput());
            $this->fail('Revoked actor saved.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('website_draft_commands')->count());
        }
        Role::findByName('marketing')->revokePermissionTo(P::VIEW);
        try {
            app(WebsiteDraftService::class)->profile($actor);
            $this->fail('Revoked actor viewed.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_revision_append_retry_conflict_stale_and_language_independence(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $service = app(WebsiteDraftService::class);
        $before = $service->profile($actor);
        $input = $this->saveInput(['content' => [...F::empty(), 'display_name' => 'Synthetic public EN']]);
        $service->save($actor, $input);
        $service->save($actor, $input);
        $after = $service->profile($actor);
        $this->assertSame($before['lockVersion'] + 1, $after['lockVersion']);
        $this->assertSame('Synthetic public EN', $after['drafts']['en']['content']['display_name']);
        $this->assertEqualsWithDelta(now()->timestamp, CarbonImmutable::parse($after['drafts']['en']['createdAt'])->timestamp, 2);
        $this->assertSame($before['drafts']['bm'], $after['drafts']['bm']);
        $this->assertSame(1, DB::table('website_draft_commands')->count());
        $this->refuses(fn () => $service->save($actor, [...$input, 'language' => 'bm']), 'idempotency_key');
        $this->refuses(fn () => $service->save($actor, [...$input, 'idempotency_key' => (string) Str::uuid()]), 'version');
        $service->save($actor, $this->saveInput(['language' => 'bm', 'content' => [...F::empty(), 'display_name' => 'Klinik awam sintetik']]));
        $this->assertSame('Synthetic public EN', $service->profile($actor)['drafts']['en']['content']['display_name']);
        $this->assertSame('Klinik awam sintetik', $service->profile($actor)['drafts']['bm']['content']['display_name']);
    }

    public function test_seeds_copy_only_public_address_map_are_unreviewed_and_replay_safe(): void
    {
        $before = DB::table('website_content_revisions')->orderBy('id')->get()->toJson();
        WebsiteDraftSeeds::seed();
        $this->assertSame($before, DB::table('website_content_revisions')->orderBy('id')->get()->toJson());
        $this->assertSame(Branch::count() * 2, DB::table('website_content_revisions')->count());
        foreach (DB::table('website_content_revisions')->get() as $revision) {
            $this->assertTrue($revision->is_seed_copy);
            $content = json_decode($revision->content, true);
            $branch = Branch::findOrFail(DB::table('website_content_items')->where('id', $revision->item_id)->value('branch_id'));
            $this->assertSame($branch->public_address ?? '', $content['address_line_1']);
            $this->assertSame($branch->public_map_url ?? '', $content['map_url']);
            $this->assertEquals([...F::empty(), 'address_line_1' => $branch->public_address ?? '', 'map_url' => $branch->public_map_url ?? ''], $content);
        }
    }

    public function test_completeness_and_all_plain_text_field_budgets_and_hidden_characters(): void
    {
        $content = F::empty();
        $this->assertNotEmpty(F::missing($content));
        foreach (['display_name', 'address_line_1', 'postcode', 'city', 'state', 'public_phone'] as $field) {
            $content[$field] = 'Synthetic';
        }
        foreach (F::DAYS as $day) {
            $content['opening_hours'][$day]['closed'] = true;
        }
        $this->assertSame([], F::missing($content));
        foreach (F::LIMITS as $field => $limit) {
            $this->refuses(fn () => F::validate([...F::empty(), $field => str_repeat('a', $limit + 1)]), 'content.'.$field);
            foreach (["\n", "\x00", "\u{202E}", "\u{200B}", "\u{2066}", '<script>x</script>'] as $bad) {
                $this->refuses(fn () => F::validate([...F::empty(), $field => 'A'.$bad.'B']), 'content.'.$field);
            }
        }
        $this->refuses(fn () => F::validate([...F::empty(), 'document_footer' => 'not authorised']), 'content');
        foreach (['javascript:alert(1)', 'http://maps.test/path', 'data:text/html,x', 'ftp://maps.test', 'https://user:secret@maps.test/'] as $url) {
            $this->refuses(fn () => F::validate([...F::empty(), 'map_url' => $url]), 'content.map_url');
        }
        $this->assertSame('https://maps.test/?a=1&b=2', F::validate([...F::empty(), 'map_url' => 'https://maps.test/?a=1&b=2'])['map_url']);
        $this->refuses(fn () => F::validate([...F::empty(), 'public_email' => 'invalid']), 'content.public_email');
        $this->refuses(fn () => F::validate([...F::empty(), 'public_phone' => 'letters']), 'content.public_phone');
    }

    public function test_opening_hours_and_bounded_contact_blocks(): void
    {
        foreach ([['closed' => true, 'open' => '08:00', 'close' => '17:00'], ['closed' => false, 'open' => '25:00', 'close' => '26:00'], ['closed' => false, 'open' => '20:00', 'close' => '08:00'], ['closed' => false, 'open' => '08:00', 'close' => ''], ['closed' => 'yes', 'open' => '', 'close' => '']] as $row) {
            $content = F::empty();
            $content['opening_hours']['mon'] = $row;
            $this->refuses(fn () => F::validate($content));
        }
        $content = F::empty();
        $content['opening_hours']['mon'] = ['closed' => false, 'open' => '08:00', 'close' => '17:00'];
        $this->assertSame($content, F::validate($content));
        foreach ([[...array_fill(0, 4, ['label' => 'A', 'text' => 'B'])], [['label' => str_repeat('A', 61), 'text' => 'B']], [['label' => 'A', 'text' => str_repeat('B', 301)]], [['label' => 'A', 'text' => 'B', 'html' => 'x']]] as $blocks) {
            $this->refuses(fn () => F::validate([...F::empty(), 'contact_blocks' => $blocks]));
        }
    }

    public function test_whatsapp_strict_wa01_parity(): void
    {
        foreach (['012345678' => '6012345678', '0123456789' => '60123456789', '+60 (12) 345-6789' => '60123456789', '60123456789' => '60123456789', '+65 8123 4567' => '6581234567', '' => ''] as $input => $expected) {
            $this->assertSame($expected, F::whatsapp((string) $input));
        }
        foreach (['03 1234 5678', '01112345678', '+60012345678', '012****789', '0123456789 ext 2', '6512345678', '+0123456789', '012..3456789', '+60 3 12345678', '+60+123456789'] as $input) {
            $this->refuses(fn () => F::whatsapp($input));
        }
    }

    public function test_media_all_formats_are_reencoded_metadata_stripped_original_not_retained_and_hash_stable(): void
    {
        $actor = $this->actor('hr_manager');
        $this->selectBranch($actor);
        $service = app(WebsiteDraftService::class);
        foreach (['jpg', 'png', 'webp'] as $extension) {
            $file = $this->imageFile($extension);
            $raw = $file->getContent();
            if ($extension === 'jpg') {
                // Valid EXIF TIFF containing a GPS IFD and image description, entirely synthetic.
                $tiff = 'II'.pack('vV', 42, 8).pack('v', 2).pack('vvVV', 0x010E, 2, 16, 38).pack('vvVV', 0x8825, 4, 1, 54).pack('V', 0).'Synthetic GPS'."\0\0\0".pack('v', 1).pack('vvVV', 1, 2, 2, 78).pack('V', 0);
                $exif = "Exif\0\0".$tiff;
                $raw = substr($raw, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($raw, 2);
                $file = $this->imageFile($extension, $raw);
                $this->assertArrayHasKey('GPS', exif_read_data($file->getPathname(), null, true));
            } elseif ($extension === 'png') {
                $chunk = "tEXtDescription\0Synthetic GPS metadata";
                $raw = substr($raw, 0, 33).pack('N', strlen($chunk) - 4).$chunk.hex2bin(hash('crc32b', $chunk)).substr($raw, 33);
                $file = $this->imageFile($extension, $raw);
            }
            $sanitised = WebsiteImage::sanitise($file);
            $this->assertSame($sanitised['sha256'], WebsiteImage::sanitise($file)['sha256']);
            $input = $this->uploadInput();
            $service->upload($actor, $input, $file);
            $service->upload($actor, $input, $file);
            $asset = DB::table('website_media_assets')->orderByDesc('id')->first();
            $stored = Storage::disk('website_media')->get($asset->path);
            $this->assertSame($sanitised['content'], $stored);
            $this->assertSame(hash('sha256', $stored), $asset->sha256);
            $this->assertStringNotContainsString('Synthetic GPS', $stored);
            if ($extension === 'jpg') {
                $tmp = tempnam(sys_get_temp_dir(), 'w1a-exif-');
                file_put_contents($tmp, $stored);
                $this->assertArrayNotHasKey('GPS', exif_read_data($tmp, null, true));
                unlink($tmp);
            }
            $this->assertSame(['content', 'mime'], array_keys($service->image($actor, $asset->public_id)));
            $this->get('/website/media/'.$asset->public_id.'/image')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderContains('Cache-Control', 'no-store');
        }
        $this->assertCount(3, Storage::disk('website_media')->allFiles());
        $this->assertSame(3, DB::table('website_media_assets')->count());
    }

    public function test_media_rejects_forbidden_mislabelled_polyglots_and_size_limits(): void
    {
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"/>', '<html>bad</html>', '%PDF-1.7', 'GIF89a'] as $raw) {
            $this->refuses(fn () => WebsiteImage::sanitise($this->imageFile('png', $raw)), 'image');
        }
        $png = $this->imageFile()->getContent();
        $jpeg = $this->imageFile('jpg')->getContent();
        $webp = $this->imageFile('webp')->getContent();
        foreach ([['jpg', $png], ['png', $png.'<script>bad</script>'], ['jpg', $jpeg.'payload'."\xff\xd9"], ['webp', $webp.'x'], ['png', str_repeat('x', WebsiteImage::MAX_BYTES + 1)]] as [$extension, $raw]) {
            $this->refuses(fn () => WebsiteImage::sanitise($this->imageFile($extension, $raw)), 'image');
        }
        $this->refuses(fn () => WebsiteImage::sanitise(new UploadedFile($this->imageFile()->getPathname(), 'synthetic.png', 'image/jpeg', null, true)), 'image');
        // Valid dimension headers suffice: refusal occurs before GD decode/allocation.
        $ihdr = 'IHDR'.pack('NNCCCCC', 4000, 4000, 8, 2, 0, 0, 0);
        $large = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.hex2bin(hash('crc32b', $ihdr)).substr($png, 33);
        $this->refuses(fn () => WebsiteImage::sanitise($this->imageFile('png', $large)), 'image');
        $ihdr = 'IHDR'.pack('NNCCCCC', 4097, 1, 8, 2, 0, 0, 0);
        $this->refuses(fn () => WebsiteImage::sanitise($this->imageFile('png', "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.hex2bin(hash('crc32b', $ihdr)).substr($png, 33))), 'image');
    }

    public function test_rights_consent_retirement_attachment_and_private_history(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $service = app(WebsiteDraftService::class);
        foreach (['alt_en', 'alt_bm', 'rights_reference'] as $key) {
            $this->refuses(fn () => $service->upload($actor, $this->uploadInput([$key => ' ']), $this->imageFile()), $key);
        }
        $this->refuses(fn () => $service->upload($actor, $this->uploadInput(['identifiable_person' => true]), $this->imageFile()), 'consent_reference');
        $input = $this->uploadInput(['identifiable_person' => true, 'consent_reference' => 'Synthetic consent reference']);
        $file = $this->imageFile();
        $service->upload($actor, $input, $file);
        $this->refuses(fn () => $service->upload($actor, [...$input, 'alt_en' => 'Different'], $file), 'idempotency_key');
        $asset = DB::table('website_media_assets')->first();
        $service->save($actor, $this->saveInput(['hero_asset_public_id' => $asset->public_id]));
        $retire = ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()];
        $service->retire($actor, $asset->public_id, $retire);
        $service->retire($actor, $asset->public_id, $retire);
        $this->refuses(fn () => $service->retire($actor, $asset->public_id, [...$retire, 'idempotency_key' => (string) Str::uuid()]), 'version');
        $this->refuses(fn () => $service->save($actor, $this->saveInput(['hero_asset_public_id' => $asset->public_id])), 'hero_asset_public_id');
        $this->assertSame($asset->public_id, $service->profile($actor)['drafts']['en']['heroAssetPublicId']);
        $this->assertSame([], $service->profile($actor)['assets']);
        $this->assertTrue($service->media($actor)['assets'][0]['retired']);
        $this->assertNotEmpty($service->image($actor, $asset->public_id)['content']);
    }

    public function test_postgresql_refuses_revision_command_update_delete_truncate_and_asset_mutation(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $service = app(WebsiteDraftService::class);
        $service->upload($actor, $this->uploadInput(), $this->imageFile());
        $asset = DB::table('website_media_assets')->first();
        $service->save($actor, $this->saveInput(['hero_asset_public_id' => $asset->public_id]));
        foreach (['UPDATE website_content_revisions SET revision_number = 100', 'DELETE FROM website_content_revisions', 'TRUNCATE website_content_revisions', "UPDATE website_draft_commands SET operation = 'other'", 'DELETE FROM website_draft_commands', 'TRUNCATE website_draft_commands', "UPDATE website_media_assets SET alt_en = 'changed'", 'DELETE FROM website_media_assets', 'TRUNCATE website_media_assets CASCADE'] as $sql) {
            DB::beginTransaction();
            try {
                DB::statement($sql);
                $this->fail('Immutable record was changed: '.$sql);
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_organisation_and_stale_branch_isolation(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $service = app(WebsiteDraftService::class);
        $foreignOrg = Organisation::query()->create(['code' => 'SYNTHETIC_WEB', 'name' => 'Synthetic organisation', 'is_active' => true]);
        $foreign = new Branch;
        $foreign->forceFill(['organisation_id' => $foreignOrg->id, 'code' => 'WEB', 'name' => 'Synthetic branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $foreignActor = $this->actor('marketing', $foreign);
        $this->selectBranch($foreignActor, $foreign);
        $service->upload($foreignActor, $this->uploadInput(['expected_branch_id' => $foreign->id]), $this->imageFile());
        $asset = DB::table('website_media_assets')->first();
        $this->selectBranch($actor);
        $this->refuses(fn () => $service->save($actor, $this->saveInput(['hero_asset_public_id' => $asset->public_id])), 'hero_asset_public_id');
        $this->refuses(fn () => $service->save($actor, $this->saveInput(['expected_branch_id' => $foreign->id])), 'expected_branch_id');
        $this->get('/website/media/'.$asset->public_id.'/image')->assertNotFound();
        $this->assertSame([], $service->media($actor)['assets']);
        $this->assertSame([], $service->profile($actor)['assets']);
        $this->post('/website/media/'.$asset->public_id.'/retire', ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_audit_failure_rolls_back_each_mutation_and_removes_staged_file(): void
    {
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $service = app(WebsiteDraftService::class);
        $service->upload($actor, $this->uploadInput(), $this->imageFile());
        $asset = DB::table('website_media_assets')->first();
        $before = $this->allRows();
        $files = Storage::disk('website_media')->allFiles();
        $audit = \Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->app->instance(AuditRecorder::class, $audit);
        $service = app(WebsiteDraftService::class);
        foreach ([fn () => $service->save($actor, $this->saveInput()), fn () => $service->upload($actor, $this->uploadInput(), $this->imageFile()), fn () => $service->retire($actor, $asset->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])] as $action) {
            try {
                $action();
                $this->fail('Audit failure accepted.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Synthetic audit failure', $e->getMessage());
            }
            $this->assertSame($before, $this->allRows());
            $this->assertSame($files, Storage::disk('website_media')->allFiles());
        }
    }

    private function allRows(bool $unrelated = false): array
    {
        $rows = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $row) {
            if ($unrelated && (str_starts_with($row->tablename, 'website_') || $row->tablename === 'audit_logs')) {
                continue;
            }
            $columns = Schema::getColumnListing($row->tablename);
            $query = DB::table($row->tablename);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $query->orderBy($column);
            }
            $rows[$row->tablename] = $query->get()->toJson();
        }

        return $rows;
    }

    public function test_all_unrelated_rows_unchanged_and_no_operational_queries_or_personal_content(): void
    {
        $this->finalizedFixture();
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $service = app(WebsiteDraftService::class);
        $before = $this->allRows(true);
        $actions = [fn () => $service->save($actor, $this->saveInput(['content' => [...F::empty(), 'display_name' => 'Synthetic content sentinel']])), fn () => $service->upload($actor, $this->uploadInput(), $this->imageFile()), fn () => $service->profile($actor), fn () => $service->media($actor), fn () => $service->image($actor, DB::table('website_media_assets')->value('public_id')), fn () => $service->retire($actor, DB::table('website_media_assets')->value('public_id'), ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])];
        foreach ($actions as $action) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $action();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/(?:from|join|into|update)\s+"?(?:patients|patient_[a-z_]+|visits|visit_[a-z_]+|queue_[a-z_]+|clinical_[a-z_]+|encounters|treatment_[a-z_]+|invoices|invoice_[a-z_]+|payments|payment_allocations|payment_reversals|dispensary_[a-z_]+|stock_[a-z_]+|inventory_[a-z_]+)"?\b/i', $query['query']);
            }
            $this->assertSame($before, $this->allRows(true));
        }
        $this->get('/website/branch-profiles')->assertInertia(fn (Assert $page) => $page->component('Website/Profiles')->missing('patients')->missing('letterhead')->missing('clinical'));
        foreach (DB::table('audit_logs')->where('event', 'like', 'website.%')->get() as $audit) {
            $this->assertStringNotContainsString('Synthetic content sentinel', $audit->metadata);
            $this->assertStringNotContainsString('Synthetic public courtyard', $audit->metadata);
            $this->assertStringNotContainsString('Synthetic owner illustration', $audit->metadata);
            $keys = array_keys(json_decode($audit->metadata, true));
            sort($keys);
            $expected = $audit->event === 'website.draft.saved' ? ['field_names', 'item_id', 'language', 'revision_number'] : ['asset_id', 'field_names', 'version'];
            sort($expected);
            $this->assertSame($expected, $keys);
        }
    }

    public function test_additive_grants_replay_without_revoking_custom_grants_and_down_keeps_evidence(): void
    {
        $migration = require database_path('migrations/2026_10_24_000200_grant_website_draft_permissions.php');
        $role = Role::findByName('marketing');
        $role->givePermissionTo('dashboard.view.own');
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->assertSame([P::ASSETS, P::MANAGE, P::VIEW], DB::table('permissions')->where('name', 'like', 'website.%')->orderBy('name')->pluck('name')->all());
    }
}
