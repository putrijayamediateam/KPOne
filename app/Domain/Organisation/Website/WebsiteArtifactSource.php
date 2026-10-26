<?php

namespace App\Domain\Organisation\Website;

use App\Domain\Patient\Services\WhatsAppNumber;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class WebsiteArtifactSource
{
    public function __construct(private WebsiteArtifactBudget $budget) {}

    /**
     * Authority and source/control locks belong to the build adapter. No live rows are read here.
     *
     * @return array{dto:array<string,mixed>,media:array<string,array{kind:string,sha256:string}>,release:\stdClass,plan:\stdClass}
     */
    public function read(int $organisation, int $releaseId, int $planId): array
    {
        $release = DB::table('website_releases')->where('organisation_id', $organisation)->where('id', $releaseId)->first(['id', 'number', 'manifest', 'manifest_hash']);
        $plan = DB::table('website_output_plans')->where('organisation_id', $organisation)->where('id', $planId)->where('release_id', $releaseId)->first(['id', 'release_id', 'release_number', 'release_manifest_hash', 'number', 'content', 'plan_hash']);
        if (! $release || ! $plan || DB::table('website_release_controls')->where('release_id', $releaseId)->value('state') !== 'approved' || DB::table('website_output_plan_controls')->where('plan_id', $planId)->value('state') !== 'approved') {
            throw new WebsiteArtifactFailure('approval');
        }
        $manifest = $this->json($release->manifest, $release->manifest_hash);
        $content = $this->json($plan->content, $plan->plan_hash);
        if ($plan->release_number !== $release->number || $plan->release_manifest_hash !== $release->manifest_hash || ($content['release'] ?? null) !== ['manifest_hash' => $release->manifest_hash, 'number' => $release->number]) {
            throw new WebsiteArtifactFailure('integrity');
        }
        if (($content['schema_version'] ?? null) !== 1 || ($content['language_policy'] ?? '') !== WebsiteOutputProfile::LANGUAGE || WebsiteReleaseJson::encode($content['design_profile'] ?? []) !== WebsiteReleaseJson::encode(WebsiteOutputProfile::frozen())) {
            throw new WebsiteArtifactFailure('profile');
        }
        $typed = WebsiteOutputPlanFields::validate(['public_origin' => $content['public_origin'] ?? '', 'aliases' => array_map(fn ($a) => $a['alias'], $content['aliases'] ?? []), 'pages' => $content['pages'] ?? [], 'footer' => $content['footer'] ?? [], 'logo_public_id' => null], $manifest);
        if (WebsiteReleaseJson::encode($content['navigation'] ?? []) !== WebsiteReleaseJson::encode(array_intersect_key(WebsiteOutputProfile::LABELS, array_flip($typed['pages'])))) {
            throw new WebsiteArtifactFailure('profile');
        }
        $branchCount = count(array_filter($manifest['items'], fn ($i) => $i['type'] === 'branch_public_profile' && $i['language'] === 'en'));
        $hasServices = in_array('services', $typed['pages'], true);
        $extraLogo = $content['logo'] !== null && ! in_array($content['logo']['sha256'], array_column($manifest['media'], 'sha256'), true) ? 1 : 0;
        $this->budget->fileCount(2 * (count($typed['pages']) + 1 + (in_array('branches', $typed['pages'], true) ? $branchCount : 0) + ($hasServices ? 1 : 0)) + 6 + ($hasServices ? 2 : 0) + count($manifest['media']) + $extraLogo);
        $branchAliases = [];
        foreach ($manifest['items'] as $item) {
            if ($item['type'] === 'branch_public_profile' && $item['language'] === 'en') {
                if (count($item['projection']['branches'] ?? []) !== 1) {
                    throw new WebsiteArtifactFailure('integrity');
                }
                $branchKey = $item['projection']['branches'][0];
                if (isset($branchAliases[$branchKey])) {
                    throw new WebsiteArtifactFailure('integrity');
                }
                $branchAliases[$branchKey] = $this->alias($content, $item['key'], 'branch');
            }
        }
        $expected = [];
        foreach ($manifest['items'] as $entry) {
            $expected[$entry['key'].':'.$entry['language']] = $entry;
        }
        $rows = DB::table('website_release_items')->where('release_id', $releaseId)->orderBy('item_key')->orderBy('language')->get(['item_key', 'type', 'language', 'projection', 'content_hash']);
        if (count($expected) !== $rows->count() || count($manifest['items']) !== $rows->count()) {
            throw new WebsiteArtifactFailure('integrity');
        }
        $mediaRows = DB::table('website_release_media')->where('release_id', $releaseId)->orderBy('filename')->get(['id', 'filename', 'sha256', 'mime', 'bytes', 'width', 'height', 'alt_en', 'alt_bm']);
        if (count($manifest['media']) !== $mediaRows->count()) {
            throw new WebsiteArtifactFailure('integrity');
        }
        $this->budget->bytes((int) $mediaRows->sum('bytes'));
        $images = [];
        $references = [];
        foreach ($mediaRows as $row) {
            if ($row->filename !== $row->sha256.'.'.self::extension($row->mime)) {
                throw new WebsiteArtifactFailure('integrity');
            }
            $metadata = array_intersect_key((array) $row, array_flip(['filename', 'sha256', 'mime', 'bytes', 'width', 'height', 'alt_en', 'alt_bm']));
            $entry = array_values(array_filter($manifest['media'], fn ($m) => $m['filename'] === $row->filename));
            if (count($entry) !== 1 || WebsiteReleaseJson::encode($entry[0]) !== WebsiteReleaseJson::encode($metadata)) {
                throw new WebsiteArtifactFailure('integrity');
            }
            $row->encrypted_bytes = DB::table('website_release_media')->where('release_id', $releaseId)->where('id', $row->id)->value('encrypted_bytes');
            $images['assets/img/'.$row->filename] = self::decodeMedia($row);
            unset($row->encrypted_bytes);
            $this->budget->bytes(0);
            $references['assets/img/'.$row->filename] = ['kind' => 'release_media', 'sha256' => $row->sha256];
        }
        $logo = null;
        $logos = DB::table('website_output_plan_assets')->where('plan_id', $planId)->get(['id', 'sha256', 'mime', 'bytes', 'width', 'height', 'alt_en', 'alt_bm', 'encrypted_bytes']);
        if ($content['logo'] !== null) {
            if ($logos->count() !== 1) {
                throw new WebsiteArtifactFailure('integrity');
            }
            $row = $logos->first();
            $metadata = array_intersect_key((array) $row, array_flip(['sha256', 'mime', 'bytes', 'width', 'height', 'alt_en', 'alt_bm']));
            if (WebsiteReleaseJson::encode($content['logo']) !== WebsiteReleaseJson::encode($metadata)) {
                throw new WebsiteArtifactFailure('integrity');
            }
            $ext = self::extension($row->mime);
            $path = 'assets/img/'.$row->sha256.'.'.$ext;
            $image = self::decodeMedia($row);
            if (isset($images[$path]) && $images[$path]['bytes'] !== $image['bytes']) {
                throw new WebsiteArtifactFailure('integrity');
            }
            $images[$path] = $image;
            $references[$path] ??= ['kind' => 'plan_asset', 'sha256' => $row->sha256];
            $logo = ['path' => $path, 'width' => $row->width, 'height' => $row->height, 'en' => $row->alt_en, 'bm' => $row->alt_bm];
        } elseif ($logos->isNotEmpty()) {
            throw new WebsiteArtifactFailure('integrity');
        }
        $items = ['en' => [], 'bm' => []];
        foreach ($rows as $row) {
            $entry = $expected[$row->item_key.':'.$row->language] ?? null;
            $projection = json_decode($row->projection, true, 512, JSON_THROW_ON_ERROR);
            if (! $entry || $entry['type'] !== $row->type || ! isset(WebsiteReleaseProjection::FIELDS[$row->type]) || $entry['content_hash'] !== $row->content_hash || hash('sha256', WebsiteReleaseJson::encode($projection)) !== $row->content_hash || WebsiteReleaseJson::encode($entry['projection']) !== WebsiteReleaseJson::encode($projection) || ! isset($items[$row->language])) {
                throw new WebsiteArtifactFailure('integrity');
            }
            $public = WebsiteReleaseProjection::content($row->type, $projection);
            foreach (WebsiteReleaseProjection::FIELDS[$row->type] as $field) {
                if (! array_key_exists($field, $public)) {
                    continue;
                }
                if (in_array($field, ['qualifications', 'areas_of_practice', 'languages_spoken', 'responsibilities', 'requirements', 'overview_paragraphs', 'medical_safeguards', 'terms', 'what_to_expect', 'suitable_for'], true)) {
                    if (! is_array($public[$field]) || ! array_is_list($public[$field]) || count(array_filter($public[$field], 'is_string')) !== count($public[$field])) {
                        throw new WebsiteArtifactFailure('integrity');
                    }
                } elseif ($field === 'sort_order') {
                    if (! is_int($public[$field])) {
                        throw new WebsiteArtifactFailure('integrity');
                    }
                } elseif (! is_string($public[$field]) && $public[$field] !== null) {
                    throw new WebsiteArtifactFailure('integrity');
                }
            }
            foreach (['description_sections', 'programme_pillars', 'programme_flow_steps', 'partner_types', 'contact_blocks'] as $field) {
                foreach ($public[$field] ?? [] as $section) {
                    if (count(array_filter($section, 'is_string')) !== count($section)) {
                        throw new WebsiteArtifactFailure('integrity');
                    }
                }
            }
            $public['branches'] = array_map(fn ($key) => $branchAliases[$key] ?? throw new WebsiteArtifactFailure('integrity'), $projection['branches'] ?? []);
            foreach (['parent' => 'category', 'linked_service' => 'service'] as $field => $kind) {
                if (isset($projection[$field])) {
                    $public[$field] = $this->alias($content, $projection[$field], $kind);
                }
            }
            $public['media'] = [];
            foreach ($projection['media'] ?? [] as $image) {
                $path = 'assets/img/'.$image['filename'];
                if (! isset($images[$path]) || ! is_string($image['alt']) || trim($image['alt']) === '') {
                    throw new WebsiteArtifactFailure('integrity');
                }
                $public['media'][] = ['path' => $path, 'alt' => $image['alt'], 'width' => $images[$path]['width'], 'height' => $images[$path]['height']];
            }
            if (in_array($row->type, ['branch_public_profile', 'service_category', 'public_service'], true)) {
                $public['alias'] = $this->alias($content, $row->item_key, match ($row->type) {
                    'branch_public_profile' => 'branch','service_category' => 'category',default => 'service'
                });
            }
            if ($row->type === 'branch_public_profile') {
                $number = WhatsAppNumber::normalize($public['whatsapp_number'] ?? '');
                $public['whatsapp_url'] = $number ? 'https://wa.me/'.$number : '';
            }
            foreach (['map_url', 'apply_url'] as $field) {
                if (! empty($public[$field])) {
                    $u = parse_url($public[$field]);
                    if (! $u || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['pass']) || preg_match('/[\x00-\x20\x7f]/', $public[$field])) {
                        throw new WebsiteArtifactFailure('links');
                    }
                }
            }
            foreach (['public_email', 'apply_email'] as $field) {
                if (! empty($public[$field]) && ! filter_var($public[$field], FILTER_VALIDATE_EMAIL)) {
                    throw new WebsiteArtifactFailure('links');
                }
            }
            $items[$row->language][] = ['key' => $row->item_key, 'type' => $row->type, 'content' => $public];
        }
        foreach ($items as &$language) {
            usort($language, fn ($a, $b) => [$a['content']['sort_order'] ?? 0, $a['key']] <=> [$b['content']['sort_order'] ?? 0, $b['key']]);
            foreach ($language as $i => &$item) {
                $item['content']['anchor'] = str_replace('_', '-', $item['type']).'-'.($i + 1);
                unset($item['key']);
            }
            unset($item);
        }
        unset($language);
        ksort($images);

        return ['dto' => ['origin' => $typed['public_origin'], 'pages' => $typed['pages'], 'footer' => $typed['footer'], 'navigation' => $content['navigation'], 'design' => $content['design_profile'], 'items' => $items, 'images' => $images, 'logo' => $logo], 'media' => $references, 'release' => (object) ['number' => $release->number, 'manifest_hash' => $release->manifest_hash], 'plan' => (object) ['number' => $plan->number, 'plan_hash' => $plan->plan_hash]];
    }

    /** @return array<string,mixed> */
    private function json(string $json, string $hash): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! hash_equals($hash, hash('sha256', $json)) || WebsiteReleaseJson::encode($data) !== $json) {
                throw new WebsiteArtifactFailure('integrity');
            }

            return $data;
        } catch (\Throwable) {
            throw new WebsiteArtifactFailure('integrity');
        }
    }

    /** @param array<string,mixed> $plan */
    private function alias(array $plan, string $key, string $kind): string
    {
        $a = $plan['aliases'][$key] ?? null;
        if (! $a || $a['kind'] !== $kind) {
            throw new WebsiteArtifactFailure('integrity');
        }

        return $a['alias'];
    }

    /** @return array{bytes:string,mime:string,width:int,height:int} */
    public static function decodeMedia(\stdClass $row): array
    {
        try {
            $bytes = base64_decode(Crypt::decryptString($row->encrypted_bytes), true);
            $size = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes) || strlen($bytes) !== $row->bytes || hash('sha256', $bytes) !== $row->sha256 || ! $size || $size[0] !== $row->width || $size[1] !== $row->height || $size['mime'] !== $row->mime) {
                throw new WebsiteArtifactFailure('integrity');
            }
            self::extension($row->mime);

            return ['bytes' => $bytes, 'mime' => $row->mime, 'width' => $row->width, 'height' => $row->height];
        } catch (\Throwable) {
            throw new WebsiteArtifactFailure('integrity');
        }
    }

    public static function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg','image/png' => 'png','image/webp' => 'webp',default => throw new WebsiteArtifactFailure('integrity')
        };
    }
}
