<?php

namespace App\Domain\Organisation\Website;

final class WebsitePermissions
{
    public const VIEW = 'website.content.view.organisation';

    public const MANAGE = 'website.content.manage.organisation';

    public const ASSETS = 'website.assets.manage.organisation';

    public const DOCTORS = 'website.doctor_profiles.manage.organisation';

    public const CAREERS = 'website.careers.manage.organisation';

    public const PREPARE = 'website.releases.prepare.organisation';

    public const APPROVE = 'website.releases.approve.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::PREPARE => ['director', 'marketing', 'administrator'], self::APPROVE => ['director'], self::DOCTORS => ['director', 'hr_manager'], self::CAREERS => ['director', 'hr_manager'], self::VIEW => ['director', 'marketing', 'hr_manager', 'administrator'], self::MANAGE => ['director', 'marketing', 'administrator'], self::ASSETS => ['director', 'marketing', 'hr_manager', 'administrator']];
    }
}
