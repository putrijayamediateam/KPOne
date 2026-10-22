<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteEditorialFields
{
    public const TYPES = ['promotion', 'activity', 'faq'];

    public const TEXT = [
        'promotion' => ['title' => 120, 'short_summary' => 300, 'offer_text' => 80, 'offer_display_reference' => 200],
        'activity' => ['title' => 120, 'short_summary' => 300, 'location_note' => 150],
        'faq' => ['question' => 200, 'answer' => 1500],
    ];

    public const DATES = ['promotion' => ['valid_from', 'valid_until'], 'activity' => ['starts_on', 'ends_on'], 'faq' => []];

    /** @return array<string,mixed> */
    public static function empty(string $type): array
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        return [...array_fill_keys(array_keys(self::TEXT[$type]), ''), ...array_fill_keys(self::DATES[$type], ''), ...($type === 'faq' ? ['linked_service_public_id' => null] : ['description_sections' => [], 'cover_public_id' => null]), ...($type === 'promotion' ? ['terms' => []] : []), ...($type === 'activity' ? ['gallery_public_ids' => []] : []), 'sort_order' => 0, 'include_on_website' => false];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(string $type, array $input): array
    {
        $out = self::empty($type);
        if (array_diff(array_keys($input), array_keys($out))) {
            self::refuse('content', 'Unknown content field.');
        }
        foreach (self::TEXT[$type] as $key => $limit) {
            $out[$key] = WebsiteServiceFields::text($input[$key] ?? '', 'content.'.$key, $limit);
        }
        foreach (self::DATES[$type] as $key) {
            $date = WebsiteServiceFields::text($input[$key] ?? '', 'content.'.$key, 10);
            Validator::make([$key => $date], [$key => ['nullable', 'date_format:Y-m-d']])->validate();
            $out[$key] = $date;
        }
        if (self::DATES[$type] !== []) {
            [$start, $end] = self::DATES[$type];
            if ($out[$start] !== '' && $out[$end] !== '' && $out[$end] < $out[$start]) {
                self::refuse('content.'.$end, 'The end date must not be before the start date.');
            }
        }
        if ($type !== 'faq') {
            $sections = $input['description_sections'] ?? [];
            if (! is_array($sections) || ! array_is_list($sections) || count($sections) > 6) {
                self::refuse('content.description_sections', 'Use up to 6 sections.');
            }
            $out['description_sections'] = array_map(function ($section): array {
                if (! is_array($section) || array_diff(array_keys($section), ['heading', 'body'])) {
                    self::refuse('content.description_sections', 'Use a heading and body only.');
                }

                return ['heading' => WebsiteServiceFields::text($section['heading'] ?? '', 'content.description_sections', 100), 'body' => WebsiteServiceFields::text($section['body'] ?? '', 'content.description_sections', 1500)];
            }, $sections);
        }
        if ($type === 'promotion') {
            $terms = $input['terms'] ?? [];
            if (! is_array($terms) || ! array_is_list($terms) || count($terms) > 8) {
                self::refuse('content.terms', 'Use up to 8 terms.');
            }
            $out['terms'] = array_map(fn ($v) => WebsiteServiceFields::text($v, 'content.terms', 200), $terms);
            if ($out['offer_text'] !== '' && $out['offer_display_reference'] === '') {
                self::refuse('content.offer_display_reference', 'Record who approved displaying this manual offer text.');
            }
        }
        $extra = Validator::make($input, ['sort_order' => ['required', 'integer', 'between:-100000,100000'], 'include_on_website' => ['required', 'boolean'], ...($type === 'faq' ? ['linked_service_public_id' => ['nullable', 'uuid']] : ['cover_public_id' => ['nullable', 'uuid']]), ...($type === 'activity' ? ['gallery_public_ids' => ['sometimes', 'array', 'max:12'], 'gallery_public_ids.*' => ['required', 'uuid', 'distinct']] : [])])->validate();
        $out['sort_order'] = (int) $extra['sort_order'];
        $out['include_on_website'] = filter_var($extra['include_on_website'], FILTER_VALIDATE_BOOLEAN);
        $ref = $type === 'faq' ? 'linked_service_public_id' : 'cover_public_id';
        $out[$ref] = isset($extra[$ref]) ? strtolower($extra[$ref]) : null;
        if ($type === 'activity') {
            $gallery = array_map(strtolower(...), $extra['gallery_public_ids'] ?? []);
            if (! array_is_list($gallery) || count(array_unique($gallery)) !== count($gallery)) {
                self::refuse('content.gallery_public_ids', 'Use distinct assets in explicit order.');
            }
            $out['gallery_public_ids'] = $gallery;
        }

        return $out;
    }

    /** @param array<string,mixed> $content
     * @param  list<int>  $branches
     * @return list<string>
     */
    public static function missing(string $type, array $content, array $branches = []): array
    {
        $missing = array_values(array_filter($type === 'faq' ? ['question', 'answer'] : ['title', 'short_summary'], fn ($key) => empty($content[$key])));
        if ($type !== 'faq') {
            if (! array_filter($content['description_sections'] ?? [], fn ($s) => ($s['heading'] ?? '') !== '' && ($s['body'] ?? '') !== '')) {
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
