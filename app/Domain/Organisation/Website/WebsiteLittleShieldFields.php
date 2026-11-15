<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteLittleShieldFields
{
    public const TEXT = ['title' => 120, 'tagline' => 200, 'introduction' => 600, 'partnership_introduction' => 600, 'how_to_take_part' => 300];

    public const LISTS = ['overview_paragraphs' => [4, 1200], 'medical_safeguards' => [10, 250]];

    public const CARDS = ['programme_pillars' => [6, 100, 400], 'programme_flow_steps' => [8, 100, 400], 'partner_types' => [6, 100, 300]];

    /** @return array<string,mixed> */
    public static function empty(): array
    {
        return [...array_fill_keys(array_keys(self::TEXT), ''), ...array_fill_keys([...array_keys(self::LISTS), ...array_keys(self::CARDS)], []), 'cover_public_id' => null, 'gallery_public_ids' => [], 'include_on_website' => false];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(array $input): array
    {
        $out = self::empty();
        if (array_diff(array_keys($input), array_keys($out))) {
            self::refuse('content', 'Unknown content field.');
        }
        foreach (self::TEXT as $key => $limit) {
            $out[$key] = WebsiteServiceFields::text($input[$key] ?? '', 'content.'.$key, $limit);
        }
        foreach (self::LISTS as $key => [$count, $limit]) {
            $values = self::list($input[$key] ?? [], $key, $count);
            $out[$key] = array_map(fn ($value) => WebsiteServiceFields::text($value, 'content.'.$key, $limit), $values);
        }
        foreach (self::CARDS as $key => [$count, $title, $description]) {
            $values = self::list($input[$key] ?? [], $key, $count);
            $out[$key] = array_map(function ($value) use ($key, $title, $description): array {
                if (! is_array($value) || array_diff(array_keys($value), ['title', 'description'])) {
                    self::refuse('content.'.$key, 'Use a title and description only.');
                }

                return ['title' => WebsiteServiceFields::text($value['title'] ?? '', 'content.'.$key, $title), 'description' => WebsiteServiceFields::text($value['description'] ?? '', 'content.'.$key, $description)];
            }, $values);
        }
        $extra = Validator::make($input, ['cover_public_id' => ['nullable', 'uuid'], 'gallery_public_ids' => ['sometimes', 'array', 'max:8'], 'gallery_public_ids.*' => ['required', 'uuid', 'distinct'], 'include_on_website' => ['required', 'boolean']])->validate();
        $gallery = array_map(strtolower(...), self::list($extra['gallery_public_ids'] ?? [], 'gallery_public_ids', 8));
        if (count(array_unique($gallery)) !== count($gallery)) {
            self::refuse('content.gallery_public_ids', 'Use distinct poster assets in explicit order.');
        }
        $out['gallery_public_ids'] = $gallery;
        $out['cover_public_id'] = isset($extra['cover_public_id']) ? strtolower($extra['cover_public_id']) : null;
        $out['include_on_website'] = filter_var($extra['include_on_website'], FILTER_VALIDATE_BOOLEAN);

        return $out;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $key, int $count): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $count) {
            self::refuse('content.'.$key, "Use up to {$count} entries in explicit order.");
        }

        return $value;
    }

    /** @param array<string,mixed> $content
     * @param  list<int>  $branches
     * @return list<string>
     */
    public static function missing(array $content, array $branches): array
    {
        $missing = array_values(array_filter(['title', 'introduction'], fn ($key) => empty($content[$key])));
        foreach (['programme_pillars', 'programme_flow_steps'] as $key) {
            if (! array_filter($content[$key] ?? [], fn ($card) => ($card['title'] ?? '') !== '' && ($card['description'] ?? '') !== '')) {
                $missing[] = $key;
            }
        }
        if (! array_filter($content['medical_safeguards'] ?? [], fn ($value) => $value !== '')) {
            $missing[] = 'medical_safeguards';
        }
        if ($branches === []) {
            $missing[] = 'branch_ids';
        }

        return $missing;
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
