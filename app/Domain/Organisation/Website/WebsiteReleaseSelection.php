<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WebsiteReleaseSelection
{
    /** @return array<string,mixed> */
    public function capture(int $organisation): array
    {
        $items = DB::table('website_content_items')->where('organisation_id', $organisation)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
        $branches = DB::table('branches')->where('organisation_id', $organisation)->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
        $keys = [];
        $branchKeys = [];
        $selected = [];
        $omissions = [];
        $errors = [];
        foreach ($items as $item) {
            $keys[$item->public_id] = 'item-'.substr(hash('sha256', $item->public_id), 0, 20);
        }
        foreach ($branches as $i => $branch) {
            $branchKeys[$branch->id] = 'branch-'.substr(hash('sha256', $organisation.':'.$branch->id), 0, 20);
            if (! $items->contains(fn ($item) => $item->type === 'branch_public_profile' && $item->branch_id === $branch->id)) {
                $errors['prepare.branch-'.$branch->id.'.en.profile'] = 'Active branch needs a complete English and Bahasa Melayu public profile.';
            }
        }
        foreach ($items as $uuid => $item) {
            if (! array_key_exists($item->type, WebsiteReleaseProjection::FIELDS)) {
                continue;
            }
            $rows = [];
            $contents = [];
            foreach (['en', 'bm'] as $language) {
                $row = DB::table('website_content_revisions')->where('item_id', $item->id)->where('language', $language)->orderByDesc('revision_number')->lockForUpdate()->first();
                $rows[$language] = $row;
                $contents[$language] = $row ? json_decode($row->content, true, 512, JSON_THROW_ON_ERROR) : [];
            }
            $key = $keys[$uuid];
            $flag = $item->type === 'career_posting' ? 'open' : 'include_on_website';
            $required = $item->type === 'branch_public_profile';
            if ($item->archived_at !== null || ($required && ! isset($branchKeys[$item->branch_id]))) {
                $omissions[] = ['key' => $key, 'type' => $item->type, 'reason' => $item->archived_at !== null ? 'archived' : 'inactive branch'];

                continue;
            }
            if (! $required && ! ($contents['en'][$flag] ?? false) && ! ($contents['bm'][$flag] ?? false)) {
                $omissions[] = ['key' => $key, 'type' => $item->type, 'reason' => 'not included in either saved language'];

                continue;
            }
            foreach (['en', 'bm'] as $language) {
                $c = $contents[$language];
                $prefix = 'prepare.'.$key.'.'.$language.'.';
                if (! $rows[$language]) {
                    $errors[$prefix.'revision'] = 'Missing saved '.$language.' revision for '.$item->type.'.';
                }
                if (! $required && ! ($c[$flag] ?? false)) {
                    $errors[$prefix.$flag] = 'Inclusion must be selected in both saved languages.';
                }
                $missing = match ($item->type) {
                    'branch_public_profile' => WebsiteProfileFields::missing($c),
                    'doctor_profile','career_posting' => WebsitePeopleFields::missing($item->type, $c),
                    'service_category','public_service' => WebsiteServiceFields::missing($item->type, $c, $c['_branch_ids'] ?? []),
                    'promotion','activity','faq' => WebsiteEditorialFields::missing($item->type, $c, $c['_branch_ids'] ?? []),
                    'little_shield' => WebsiteLittleShieldFields::missing($c, $c['_branch_ids'] ?? []),
                };
                foreach ($missing as $field) {
                    $errors[$prefix.$field] = 'Required saved field: '.$field.' ('.$item->type.').';
                }
                foreach (['public_registration_text' => 'registration_display_reference', 'price_text' => 'price_display_reference', 'offer_text' => 'offer_display_reference'] as $text => $reference) {
                    if (($c[$text] ?? '') !== '' && trim($c[$reference] ?? '') === '') {
                        $errors[$prefix.$reference] = 'Manual public text needs its display-approval reference.';
                    }
                }
                foreach ($c['_branch_ids'] ?? [] as $branchId) {
                    if (! isset($branchKeys[$branchId])) {
                        $errors[$prefix.'branches'] = 'A coverage branch is inactive, missing or foreign.';
                    }
                }
            }
            foreach ($required ? [] : WebsiteSharedReferences::fields($item->type) as $field) {
                $a = $contents['en'][$field] ?? ($field === '_branch_ids' ? [] : null);
                $b = $contents['bm'][$field] ?? ($field === '_branch_ids' ? [] : null);
                if (is_array($a) && is_array($b)) {
                    sort($a);
                    sort($b);
                }
                if ($a !== $b) {
                    foreach (['en', 'bm'] as $language) {
                        $errors['prepare.'.$key.'.'.$language.'.'.$field] = 'Shared references differ between saved languages.';
                    }
                }
            }
            $selected[$uuid] = ['item' => $item, 'rows' => $rows, 'contents' => $contents, 'key' => $key];
        }
        $frozen = [];
        $media = [];
        foreach ($selected as $uuid => $entry) {
            $item = $entry['item'];
            $key = $entry['key'];
            foreach (['en', 'bm'] as $language) {
                $c = $entry['contents'][$language] ?? [];
                $prefix = 'prepare.'.$key.'.'.$language.'.';
                $projection = WebsiteReleaseProjection::content($item->type, $c);
                $projection['branches'] = $item->type === 'branch_public_profile' ? [$branchKeys[$item->branch_id]] : array_values(array_map(fn ($id) => $branchKeys[$id] ?? 'invalid', $c['_branch_ids'] ?? []));
                sort($projection['branches']);
                foreach (['parent_public_id', 'linked_service_public_id'] as $field) {
                    $ref = $c[$field] ?? null;
                    if ($ref) {
                        $target = $selected[$ref] ?? null;
                        $expected = $field === 'parent_public_id' ? 'service_category' : 'public_service';
                        if (! $target || $target['item']->type !== $expected || $ref === $uuid || ($item->type === 'service_category' && ! empty($target['contents'][$language]['parent_public_id']))) {
                            $errors[$prefix.$field] = 'Dependency must be included, complete, active, same-organisation and valid: '.$field.'.';
                        } else {
                            $projection[$field === 'parent_public_id' ? 'parent' : 'linked_service'] = $target['key'];
                        }
                    }
                }
                $assetRefs = [];
                if ($entry['rows'][$language]?->hero_asset_id) {
                    $assetRefs['hero'] = (int) $entry['rows'][$language]->hero_asset_id;
                }
                if (! empty($c['cover_public_id'])) {
                    $assetRefs['cover'] = $c['cover_public_id'];
                }
                foreach ($c['gallery_public_ids'] ?? [] as $i => $ref) {
                    $assetRefs['gallery-'.$i] = $ref;
                }
                $projection['media'] = [];
                foreach ($assetRefs as $position => $ref) {
                    try {
                        ['asset' => $asset, 'bytes' => $bytes, 'filename' => $filename] = WebsiteMediaCapture::capture($organisation, $ref);
                        $media[$filename] ??= ['asset' => $asset, 'bytes' => $bytes, 'filename' => $filename];
                        $projection['media'][] = ['position' => $position, 'filename' => $filename, 'alt' => $language === 'en' ? $asset->alt_en : $asset->alt_bm];
                    } catch (\Throwable) {
                        $errors[$prefix.'media.'.$position] = 'Media is unavailable, retired, foreign, corrupt, hash-mismatched or lacks required rights/consent/alt text.';
                    }
                }
                if ($entry['rows'][$language]) {
                    $frozen[] = ['key' => $key, 'type' => $item->type, 'language' => $language, 'source_item_id' => $item->id, 'source_revision_id' => $entry['rows'][$language]->id, 'revision_hash' => hash('sha256', WebsiteReleaseJson::encode($c)), 'projection' => $projection, 'content_hash' => hash('sha256', WebsiteReleaseJson::encode($projection))];
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        ksort($media);
        $manifest = ['schema_version' => 1, 'omissions' => $omissions, 'items' => array_map(fn ($r) => array_intersect_key($r, array_flip(['key', 'type', 'language', 'projection', 'content_hash'])), $frozen), 'sources' => array_map(fn ($r) => array_intersect_key($r, array_flip(['key', 'language', 'source_revision_id', 'revision_hash'])), $frozen), 'media' => array_values(array_map(fn ($r) => ['filename' => $r['filename'], 'sha256' => $r['asset']->sha256, 'mime' => $r['asset']->mime, 'bytes' => $r['asset']->bytes, 'width' => $r['asset']->width, 'height' => $r['asset']->height, 'alt_en' => $r['asset']->alt_en, 'alt_bm' => $r['asset']->alt_bm], $media))];

        return ['manifest' => WebsiteReleaseJson::encode($manifest), 'items' => $frozen, 'media' => $media, 'omissions' => $omissions];
    }
}
