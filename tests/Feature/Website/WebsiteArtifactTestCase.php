<?php

namespace Tests\Feature\Website;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsiteEditorialFields;
use App\Domain\Organisation\Website\WebsiteLittleShieldFields;
use App\Domain\Organisation\Website\WebsiteOutputPlanFields as Fields;
use App\Domain\Organisation\Website\WebsiteOutputPlanService as Plan;
use App\Domain\Organisation\Website\WebsiteOutputProfile;
use App\Domain\Organisation\Website\WebsitePeopleFields;
use App\Domain\Organisation\Website\WebsiteProfileFields;
use App\Domain\Organisation\Website\WebsiteReleaseJson as J;
use App\Domain\Organisation\Website\WebsiteReleaseService as S;
use App\Domain\Organisation\Website\WebsiteServiceFields;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestCase;

abstract class WebsiteArtifactTestCase extends BillingTestCase
{
    public function refreshDatabase(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('website_media');
        Storage::fake('website_artifacts');
    }

    protected function input(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    protected function failure(callable $call, ?string $field = null): array
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

    protected function profiles(): void
    {
        foreach (Branch::where('organisation_id', $this->organisation->id)->where('is_active', true)->get() as $b) {
            $content = [...WebsiteProfileFields::empty(), 'display_name' => 'Synthetic public branch', 'address_line_1' => 'Synthetic test address', 'postcode' => '00000', 'city' => 'Synthetic city', 'state' => 'Synthetic state', 'public_phone' => '+60000000000', 'opening_hours' => array_fill_keys(WebsiteProfileFields::DAYS, ['closed' => true, 'open' => '', 'close' => ''])];
            $this->source('branch_public_profile', $content, $b->id);
        }
    }

    protected function source(string $type, array $changes = [], ?int $branch = null): int
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

    protected function updateSource(int $id, array $changes): void
    {
        DB::transaction(function () use ($id, $changes): void {
            foreach (['en', 'bm'] as $language) {
                $row = DB::table('website_content_revisions')->where('item_id', $id)->where('language', $language)->orderByDesc('revision_number')->first();
                DB::table('website_content_revisions')->insert(['organisation_id' => $this->organisation->id, 'item_id' => $id, 'language' => $language, 'revision_number' => $row->revision_number + 1, 'content' => J::encode([...json_decode($row->content, true), ...$changes]), 'hero_asset_id' => $row->hero_asset_id, 'is_seed_copy' => false, 'created_by_user_id' => null, 'created_at' => now()]);
            }
        });
    }

    protected function little(array $extra = []): int
    {
        return $this->source('little_shield', ['title' => 'Synthetic programme', 'introduction' => 'Synthetic introduction', 'programme_pillars' => [['title' => 'Pillar', 'description' => 'Description']], 'programme_flow_steps' => [['title' => 'Step', 'description' => 'Description']], 'medical_safeguards' => ['Requires medical review'], 'include_on_website' => true, ...$extra]);
    }

    protected function prepare($actor, array $extra = []): int
    {
        return app(S::class)->mutate($actor, 'prepare', null, $this->input($extra));
    }

    protected function decision($actor, int $id, string $op, array $extra = []): int
    {
        $r = app(S::class)->page($actor, $id)['detail'];

        return app(S::class)->mutate($actor, $op, $id, $this->input(['lock_version' => $r['lockVersion'], 'manifest_hash' => $r['hash'], 'confirmation' => $r['number'], 'reason' => 'Synthetic reason', ...$extra]));
    }

    protected function asset($actor, int $width = 40): object
    {
        app(WebsiteDraftService::class)->upload($actor, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'alt_en' => 'Synthetic poster', 'alt_bm' => 'Poster sintetik', 'rights_reference' => 'PRIVATE_RIGHTS_SENTINEL', 'identifiable_person' => true, 'consent_reference' => 'PRIVATE_CONSENT_SENTINEL'], UploadedFile::fake()->image('synthetic.png', $width, 30));

        return DB::table('website_media_assets')->orderByDesc('id')->first();
    }

    protected function approved(): array
    {
        $this->profiles();
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $release = $this->prepare($actor);
        $this->decision($actor, $release, 'approve');

        return [$actor, $release];
    }

    protected function planInput(int $release, array $changes = []): array
    {
        $manifest = json_decode(DB::table('website_releases')->where('id', $release)->value('manifest'), true);
        $aliases = [];
        foreach (Fields::targets($manifest) as $i => $target) {
            $aliases[$target['key']] = $target['kind'].'-synthetic-'.$i;
        }

        return [...$this->input(), 'release_id' => $release, 'content' => ['public_origin' => 'https://clinic.test', 'aliases' => $aliases, 'pages' => ['home', 'branches', 'contact'], 'footer' => ['en' => 'Synthetic footer', 'bm' => 'Pengaki sintetik'], 'logo_public_id' => null], ...$changes];
    }

    protected function plan($actor, int $release, array $changes = []): int
    {
        return app(Plan::class)->mutate($actor, 'prepare', null, $this->planInput($release, $changes));
    }

    protected function planDecision($actor, int $id, string $op, array $changes = []): int
    {
        $p = DB::table('website_output_plans')->where('id', $id)->first();
        $c = DB::table('website_output_plan_controls')->where('plan_id', $id)->first();

        return app(Plan::class)->mutate($actor, $op, $id, [...$this->input(), 'release_id' => $p->release_id, 'lock_version' => $c->lock_version, 'plan_hash' => $p->plan_hash, 'confirmation' => $p->number, 'reason' => 'Synthetic private decision', ...$changes]);
    }

    protected function snapshot(bool $all = false): array
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

    protected function rich(bool $logo = true): array
    {
        [$actor] = $this->approved();
        $image = $this->asset($actor);
        $category = $this->source('service_category', ['name' => 'Wellness services', 'short_description' => 'Synthetic reviewed category', 'include_on_website' => true]);
        $uuid = DB::table('website_content_items')->where('id', $category)->value('public_id');
        $service = $this->source('public_service', ['name' => 'Synthetic wellbeing visit', 'short_summary' => 'A reviewed public service summary.', 'seo_title' => 'Synthetic wellbeing service', 'seo_description' => 'Reviewed synthetic service information.', 'parent_public_id' => $uuid, 'description_sections' => [['heading' => 'What happens', 'body' => 'Synthetic information only.']], 'what_to_expect' => ['A synthetic example'], 'suitable_for' => ['Review fixture only'], 'price_text' => 'RM 10', 'price_display_reference' => 'PRIVATE_PRICE_SENTINEL', 'cover_public_id' => $image->public_id, 'include_on_website' => true]);
        $serviceUuid = DB::table('website_content_items')->where('id', $service)->value('public_id');
        $doctor = $this->source('doctor_profile', ['display_name' => 'Dr Synthetic Profile', 'professional_title' => 'Resident doctor', 'qualifications' => ['Synthetic qualification'], 'areas_of_practice' => ['General care'], 'languages_spoken' => ['English', 'Bahasa Melayu'], 'biography' => 'Fictional public profile for review.', 'public_registration_text' => 'MMC TEST', 'registration_display_reference' => 'PRIVATE_REGISTRATION_SENTINEL', 'profile_consent_reference' => 'PRIVATE_PROFILE_CONSENT_SENTINEL', 'include_on_website' => true]);
        $this->source('career_posting', ['title' => 'Synthetic clinic vacancy', 'summary' => 'A fictional posting for layout review.', 'responsibilities' => ['Synthetic responsibility'], 'requirements' => ['Synthetic requirement'], 'location_note' => 'Synthetic branch', 'apply_email' => 'careers@clinic.test', 'open' => true]);
        $this->source('promotion', ['title' => 'Synthetic promotion', 'short_summary' => 'Reviewed promotional information.', 'offer_text' => 'Synthetic offer', 'offer_display_reference' => 'PRIVATE_OFFER_SENTINEL', 'description_sections' => [['heading' => 'Details', 'body' => 'Synthetic promotion details.']], 'terms' => ['Synthetic terms'], 'include_on_website' => true]);
        $this->source('activity', ['title' => 'Synthetic community activity', 'short_summary' => 'Fictional activity for review.', 'description_sections' => [['heading' => 'Overview', 'body' => 'Synthetic activity details.']], 'location_note' => 'Synthetic location', 'include_on_website' => true]);
        $this->source('faq', ['question' => 'How can I find a branch?', 'answer' => 'Use the branch information on this website.', 'linked_service_public_id' => $serviceUuid, 'include_on_website' => true]);
        $this->little();
        $release = $this->prepare($actor);
        $this->decision($actor, $release, 'approve');
        $input = $this->planInput($release);
        $input['content']['pages'] = array_keys(WebsiteOutputProfile::LABELS);
        if ($logo) {
            $input['content']['logo_public_id'] = $this->asset($actor, 45)->public_id;
        }
        $plan = app(Plan::class)->mutate($actor, 'prepare', null, $input);
        $this->planDecision($actor, $plan, 'approve');

        return [$actor, $release, $plan, $service, $doctor, $image];
    }

    protected function buildInput(int $release, int $plan, array $extra = []): array
    {
        $r = DB::table('website_releases')->where('id', $release)->first();
        $p = DB::table('website_output_plans')->where('id', $plan)->first();

        return ['expected_branch_id' => $this->branch->id, 'plan_id' => $plan, 'release_version' => DB::table('website_release_controls')->where('release_id', $release)->value('lock_version'), 'plan_version' => DB::table('website_output_plan_controls')->where('plan_id', $plan)->value('lock_version'), 'release_hash' => $r->manifest_hash, 'plan_hash' => $p->plan_hash, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }
}
