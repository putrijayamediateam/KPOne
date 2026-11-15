<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WebsiteOutputPlanFields
{
    public const PAGE_TYPES = ['home' => 'branch_public_profile', 'branches' => 'branch_public_profile', 'contact' => 'branch_public_profile', 'doctors' => 'doctor_profile', 'services' => 'public_service', 'promotions' => 'promotion', 'activities' => 'activity', 'careers' => 'career_posting', 'little-shield' => 'little_shield', 'faqs' => 'faq'];

    /** @param array<string,mixed> $manifest
     * @return list<array{key:string,kind:string,name:string}> */
    public static function targets(array $manifest): array
    {
        $out = [];
        foreach ($manifest['items'] as $item) {
            if ($item['language'] !== 'en') {
                continue;
            }
            $kind = match ($item['type']) {
                'branch_public_profile' => 'branch','service_category' => 'category','public_service' => 'service',default => null
            };
            if ($kind) {
                $out[] = ['key' => $item['key'], 'kind' => $kind, 'name' => $item['projection']['name'] ?? $item['projection']['display_name']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,array{kind:string,alias:string}>  $aliases
     * @param  list<array{key:string,kind:string,name:string}>  $targets
     * @return list<array{key:string,kind:string,alias:string,name:string}>
     */
    public static function aliasRows(array $aliases, array $targets): array
    {
        $names = array_column($targets, 'name', 'key');
        $rows = [];
        foreach ($aliases as $key => $alias) {
            $rows[] = ['key' => $key, 'kind' => $alias['kind'], 'alias' => $alias['alias'], 'name' => $names[$key] ?? 'Unknown item'];
        }

        return $rows;
    }

    /** @param array<string,mixed> $manifest
     * @return list<string> */
    public static function available(array $manifest): array
    {
        $types = array_column($manifest['items'], 'type');

        return array_values(array_filter(array_keys(WebsiteOutputProfile::LABELS), fn ($page) => in_array(self::PAGE_TYPES[$page], $types, true)));
    }

    /** @param array<string,mixed> $input
     * @param array<string,mixed> $manifest
     * @return array<string,mixed> */
    public static function validate(array $input, array $manifest): array
    {
        $errors = [];
        $text = function (mixed $value, string $field, int $max) use (&$errors): string {
            try {
                return WebsiteServiceFields::text($value, $field, $max);
            } catch (ValidationException $e) {
                $errors = [...$errors, ...$e->errors()];

                return '';
            }
        };
        foreach (array_diff(array_keys($input), ['public_origin', 'aliases', 'pages', 'footer', 'logo_public_id']) as $key) {
            $errors['content.'.$key] = ['Unknown plan field.'];
        }
        $origin = strtolower($text($input['public_origin'] ?? '', 'content.public_origin', 100));
        $url = parse_url($origin);
        if (! $url || ($url['scheme'] ?? '') !== 'https' || array_diff(array_keys($url), ['scheme', 'host']) || ! isset($url['host']) || ! str_contains($url['host'], '.') || ! filter_var($url['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || filter_var($url['host'], FILTER_VALIDATE_IP)) {
            $errors['content.public_origin'] = ['Use an HTTPS DNS host only, without path, port, user info, query or fragment.'];
        }
        $aliases = $input['aliases'] ?? [];
        if (! is_array($aliases)) {
            $errors['content.aliases'] = ['Supply aliases keyed by frozen item key.'];
            $aliases = [];
        }
        $targets = self::targets($manifest);
        $keys = array_column($targets, 'key');
        $out = [];
        $used = [];
        foreach (array_diff(array_keys($aliases), $keys) as $key) {
            $errors['content.aliases.'.$key] = ['Unknown frozen item key.'];
        }
        foreach ($targets as $target) {
            $key = $target['key'];
            $field = 'content.aliases.'.$key;
            $alias = $text($aliases[$key] ?? '', $field, 60);
            if (! preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $alias) || strlen($alias) < 3) {
                $errors[$field] = ['Enter an alias of 3 to 60 lowercase letters, digits and single internal hyphens.'];

                continue;
            }
            $duplicate = $used[$target['kind']][$alias] ?? null;
            if ($duplicate) {
                $errors[$field] = ['Duplicate alias within '.$target['kind'].'.'];
                $errors['content.aliases.'.$duplicate] = ['Duplicate alias within '.$target['kind'].'.'];
            }
            $used[$target['kind']][$alias] = $key;
            $out[$key] = ['kind' => $target['kind'], 'alias' => $alias];
        }
        $pages = $input['pages'] ?? [];
        if (! is_array($pages) || ! array_is_list($pages) || $pages === [] || count($pages) > 10 || count(array_filter($pages, 'is_string')) !== count($pages) || count(array_unique($pages)) !== count($pages)) {
            $errors['content.pages'] = ['Choose a non-empty, distinct list of available pages.'];
            $pages = [];
        }
        foreach ($pages as $page) {
            if (! in_array($page, self::available($manifest), true)) {
                $errors['content.pages.'.$page] = ['This page has no included content or is not allowed.'];
            }
        }
        $pages = array_values(array_intersect(array_keys(WebsiteOutputProfile::LABELS), $pages));
        $footer = $input['footer'] ?? [];
        if (! is_array($footer) || array_diff(array_keys($footer), ['en', 'bm'])) {
            $errors['content.footer'] = ['Use English and Bahasa Melayu footer lines only.'];
            $footer = [];
        }
        $footer = ['en' => $text($footer['en'] ?? '', 'content.footer.en', 200), 'bm' => $text($footer['bm'] ?? '', 'content.footer.bm', 200)];
        $logo = $input['logo_public_id'] ?? null;
        if ($logo !== null && (! is_string($logo) || ! Str::isUuid($logo))) {
            $errors['content.logo_public_id'] = ['Choose a media public id or none.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['public_origin' => $origin, 'aliases' => $out, 'pages' => $pages, 'footer' => $footer, 'logo_public_id' => $logo];
    }
}
