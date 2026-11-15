<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteServiceFields
{
    public const TYPES = ['service_category', 'public_service'];

    public const CATEGORY_TEXT = ['name' => 100, 'short_description' => 300];

    public const SERVICE_TEXT = ['name' => 120, 'short_summary' => 300, 'seo_title' => 70, 'seo_description' => 160, 'price_text' => 80, 'price_display_reference' => 200];

    public const LISTS = ['what_to_expect' => [8, 200], 'suitable_for' => [8, 200]];

    /** @return array<string,mixed> */
    public static function empty(string $type): array
    {
        return [...array_fill_keys(array_keys($type === 'service_category' ? self::CATEGORY_TEXT : self::SERVICE_TEXT), ''), ...($type === 'public_service' ? ['description_sections' => [], 'what_to_expect' => [], 'suitable_for' => []] : []), 'parent_public_id' => null, 'cover_public_id' => null, 'gallery_public_ids' => [], 'sort_order' => 0, 'include_on_website' => false];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(string $type, array $input): array
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        if (array_diff(array_keys($input), array_keys(self::empty($type)))) {
            self::refuse('content', 'Unknown content field.');
        }
        $out = self::empty($type);
        foreach ($type === 'service_category' ? self::CATEGORY_TEXT : self::SERVICE_TEXT as $field => $limit) {
            $out[$field] = self::text($input[$field] ?? '', 'content.'.$field, $limit);
        }
        if ($type === 'public_service') {
            foreach (self::LISTS as $field => [$count, $limit]) {
                $values = $input[$field] ?? [];
                if (! is_array($values) || ! array_is_list($values) || count($values) > $count) {
                    self::refuse('content.'.$field, "Use up to {$count} entries.");
                }
                $out[$field] = array_map(fn ($v) => self::text($v, 'content.'.$field, $limit), $values);
            }
            $sections = $input['description_sections'] ?? [];
            if (! is_array($sections) || ! array_is_list($sections) || count($sections) > 8) {
                self::refuse('content.description_sections', 'Use up to 8 description sections.');
            }
            $out['description_sections'] = array_map(function ($section): array {
                if (! is_array($section) || array_diff(array_keys($section), ['heading', 'body'])) {
                    self::refuse('content.description_sections', 'Use a heading and body only.');
                }

                return ['heading' => self::text($section['heading'] ?? '', 'content.description_sections', 100), 'body' => self::text($section['body'] ?? '', 'content.description_sections', 1500)];
            }, $sections);
            if ($out['price_text'] !== '' && $out['price_display_reference'] === '') {
                self::refuse('content.price_display_reference', 'Record who approved displaying this manual price text.');
            }
        }
        $extra = Validator::make($input, ['sort_order' => ['required', 'integer', 'between:-100000,100000'], 'include_on_website' => ['required', 'boolean'], 'parent_public_id' => ['nullable', 'uuid'], 'cover_public_id' => ['nullable', 'uuid'], 'gallery_public_ids' => ['sometimes', 'array', 'max:12'], 'gallery_public_ids.*' => ['required', 'uuid', 'distinct']])->validate();
        $gallery = $extra['gallery_public_ids'] ?? [];
        if (! array_is_list($gallery) || ($type === 'service_category' && $gallery !== [])) {
            self::refuse('content.gallery_public_ids', 'Categories have one optional cover only; use an ordered gallery list for services.');
        }
        $out['sort_order'] = (int) $extra['sort_order'];
        $out['include_on_website'] = filter_var($extra['include_on_website'], FILTER_VALIDATE_BOOLEAN);
        $out['parent_public_id'] = isset($extra['parent_public_id']) ? strtolower($extra['parent_public_id']) : null;
        $out['cover_public_id'] = isset($extra['cover_public_id']) ? strtolower($extra['cover_public_id']) : null;
        $out['gallery_public_ids'] = array_map(strtolower(...), $gallery);

        return $out;
    }

    public static function text(mixed $value, string $field, int $limit): string
    {
        $value = WebsiteProfileFields::cleanText($value, $field, $limit);
        if (preg_match('/(?:\*\*|__|`|!\[|\[[^\]]+\]\(|^\s{0,3}#{1,6}\s)/u', $value)) {
            self::refuse($field, 'Use plain text without markup.');
        }

        return $value;
    }

    /** @param array<string,mixed> $content
     * @param  list<int>  $branches
     * @return list<string>
     */
    public static function missing(string $type, array $content, array $branches = []): array
    {
        $fields = $type === 'service_category' ? ['name', 'short_description'] : ['name', 'short_summary', 'parent_public_id', 'seo_title', 'seo_description'];
        $missing = array_values(array_filter($fields, fn ($key) => empty($content[$key])));
        if ($type === 'public_service') {
            if (! array_filter($content['description_sections'] ?? [], fn ($section) => ($section['heading'] ?? '') !== '' && ($section['body'] ?? '') !== '')) {
                $missing[] = 'description_sections';
            }
            if ($branches === []) {
                $missing[] = 'branch_ids';
            }
        }

        return $missing;
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
