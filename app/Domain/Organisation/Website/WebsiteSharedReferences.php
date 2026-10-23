<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Facades\DB;

final class WebsiteSharedReferences
{
    /** @return list<string> */
    public static function fields(string $type): array
    {
        return match ($type) {
            'service_category' => ['parent_public_id'],
            'public_service' => ['_branch_ids', 'parent_public_id'],
            'faq' => ['linked_service_public_id'],
            default => ['_branch_ids'],
        };
    }

    /** @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    private static function values(string $type, array $content): array
    {
        $values = [];
        foreach (self::fields($type) as $key) {
            $value = $content[$key] ?? ($key === '_branch_ids' ? [] : null);
            if ($key === '_branch_ids') {
                $value = array_map('intval', $value);
                sort($value);
            } elseif (is_string($value)) {
                $value = strtolower($value);
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /** Item is already locked by the caller; authority and content validation stay in its service.
     * @param  array<string,mixed>  $content
     * @param  list<int>  $branches
     * @return array{number:int,languages:list<string>}
     */
    public static function append(\stdClass $item, string $language, array $content, array $branches, ?int $hero, int $actor): array
    {
        $content = [...$content, '_branch_ids' => $branches];
        $other = DB::table('website_content_revisions')->where('item_id', $item->id)->where('language', '<>', $language)->orderByDesc('revision_number')->first();
        $number = self::insert($item, $language, $content, $hero, $actor);
        $languages = [$language];
        if ($other) {
            $saved = json_decode($other->content, true, 512, JSON_THROW_ON_ERROR);
            if (self::values($item->type, $saved) !== self::values($item->type, $content)) {
                foreach (self::fields($item->type) as $key) {
                    $saved[$key] = $content[$key] ?? null;
                }
                self::insert($item, $other->language, $saved, $other->hero_asset_id, $actor);
                $languages[] = $other->language;
            }
        }
        // Fail before returning even inside an enclosing application/test transaction.
        DB::select('SELECT website_assert_shared_references(?)', [$item->id]);

        return ['number' => $number, 'languages' => $languages];
    }

    /** @param array<string,mixed> $content */
    private static function insert(\stdClass $item, string $language, array $content, ?int $hero, int $actor): int
    {
        $number = (int) DB::table('website_content_revisions')->where('item_id', $item->id)->where('language', $language)->max('revision_number') + 1;
        DB::table('website_content_revisions')->insert(['organisation_id' => $item->organisation_id, 'item_id' => $item->id, 'language' => $language, 'revision_number' => $number, 'content' => json_encode($content, JSON_THROW_ON_ERROR), 'hero_asset_id' => $hero, 'is_seed_copy' => false, 'created_by_user_id' => $actor, 'created_at' => now()->utc()->toIso8601String()]);

        return $number;
    }

    /** @return array{sharedReferencesDiverge:bool,danglingServiceLink:bool} */
    public static function notices(?\stdClass $item): array
    {
        $contents = $item ? DB::table('website_content_revisions')->where('item_id', $item->id)->orderByDesc('revision_number')->get()->unique('language')->map(fn ($r) => json_decode($r->content, true, 512, JSON_THROW_ON_ERROR))->values()->all() : [];
        $diverge = count($contents) === 2 && self::values($item->type, $contents[0]) !== self::values($item->type, $contents[1]);
        $dangling = false;
        if ($item?->type === 'faq') {
            foreach ($contents as $content) {
                $id = $content['linked_service_public_id'] ?? null;
                if ($id && ! DB::table('website_content_items')->where('public_id', $id)->where('organisation_id', $item->organisation_id)->where('type', 'public_service')->whereNull('archived_at')->exists()) {
                    $dangling = true;
                }
            }
        }

        return ['sharedReferencesDiverge' => $diverge, 'danglingServiceLink' => $dangling];
    }

    public static function activeFaqCount(string $service): int
    {
        return (int) DB::selectOne('SELECT website_active_faq_count(?) AS total', [$service])->total;
    }
}
