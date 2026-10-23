<?php

namespace App\Domain\Organisation\Website;

final class WebsiteOutputProfile
{
    public const LANGUAGE = 'en-root-bm-v1';

    public const LABELS = [
        'home' => ['en' => 'Home', 'bm' => 'Laman utama'], 'branches' => ['en' => 'Branches', 'bm' => 'Cawangan'],
        'doctors' => ['en' => 'Doctors', 'bm' => 'Doktor'], 'services' => ['en' => 'Services', 'bm' => 'Perkhidmatan'],
        'promotions' => ['en' => 'Promotions', 'bm' => 'Promosi'], 'activities' => ['en' => 'Activities', 'bm' => 'Aktiviti'],
        'careers' => ['en' => 'Careers', 'bm' => 'Kerjaya'], 'little-shield' => ['en' => 'Little Shield', 'bm' => 'Little Shield'],
        'faqs' => ['en' => 'FAQs', 'bm' => 'Soalan lazim'], 'contact' => ['en' => 'Contact', 'bm' => 'Hubungi kami'],
    ];

    /** @return array<string,mixed> */
    public static function definition(): array
    {
        return ['id' => 'kp-soft-v1', 'version' => 1, 'mode' => 'light', 'font' => 'Aptos, Segoe UI, -apple-system, BlinkMacSystemFont, Helvetica Neue, Arial, sans-serif',
            'tokens' => ['white' => '#ffffff', 'ink' => '#18181b', 'soft' => '#fff1f7', 'pink' => '#ff46a5', 'brand' => '#be185d', 'border' => '#71717a'],
            'pairs' => [
                ['foreground' => 'ink', 'background' => 'white', 'use' => 'text', 'minimum' => 4.5],
                ['foreground' => 'ink', 'background' => 'soft', 'use' => 'text', 'minimum' => 4.5],
                ['foreground' => 'brand', 'background' => 'white', 'use' => 'text', 'minimum' => 4.5],
                ['foreground' => 'brand', 'background' => 'soft', 'use' => 'text', 'minimum' => 4.5],
                ['foreground' => 'white', 'background' => 'brand', 'use' => 'text', 'minimum' => 4.5],
                ['foreground' => 'ink', 'background' => 'pink', 'use' => 'text', 'minimum' => 4.5],
                ['foreground' => 'border', 'background' => 'white', 'use' => 'ui-border', 'minimum' => 3.0],
                ['foreground' => 'border', 'background' => 'soft', 'use' => 'ui-border', 'minimum' => 3.0],
            ]];
    }

    /** @return array<string,mixed> */
    public static function frozen(): array
    {
        $definition = self::definition();

        return [...$definition, 'digest' => hash('sha256', WebsiteReleaseJson::encode($definition))];
    }
}
