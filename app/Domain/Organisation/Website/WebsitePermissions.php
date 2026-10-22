<?php

namespace App\Domain\Organisation\Website;

final class WebsitePermissions
{
    public const VIEW = 'website.content.view.organisation';

    public const MANAGE = 'website.content.manage.organisation';

    public const ASSETS = 'website.assets.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::VIEW => ['director', 'marketing', 'hr_manager', 'administrator'], self::MANAGE => ['director', 'marketing', 'administrator'], self::ASSETS => ['director', 'marketing', 'hr_manager', 'administrator']];
    }
}
