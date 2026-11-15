<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class WebsiteDraftSeeds
{
    public static function seed(): void
    {
        if (! Schema::hasTable('website_content_items')) {
            return;
        }
        foreach (DB::table('branches')->orderBy('id')->get(['id', 'organisation_id', 'code', 'public_address', 'public_map_url']) as $branch) {
            DB::transaction(function () use ($branch): void {
                DB::table('organisations')->where('id', $branch->organisation_id)->lockForUpdate()->first();
                $item = DB::table('website_content_items')->where('branch_id', $branch->id)->first();
                if ($item) {
                    return;
                }
                $id = DB::table('website_content_items')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'type' => 'branch_public_profile', 'slug' => Str::lower($branch->code), 'lock_version' => 1, 'created_at' => now()->utc()->toIso8601String()]);
                foreach (['en', 'bm'] as $language) {
                    $content = WebsiteProfileFields::empty();
                    $content['address_line_1'] = $branch->public_address ?? '';
                    $content['map_url'] = $branch->public_map_url ?? '';
                    DB::table('website_content_revisions')->insert(['organisation_id' => $branch->organisation_id, 'item_id' => $id, 'language' => $language, 'revision_number' => 1, 'content' => json_encode($content, JSON_THROW_ON_ERROR), 'hero_asset_id' => null, 'is_seed_copy' => true, 'created_by_user_id' => null, 'created_at' => now()->utc()->toIso8601String()]);
                }
            });
        }
    }
}
