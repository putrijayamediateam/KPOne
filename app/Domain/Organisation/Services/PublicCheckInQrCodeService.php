<?php

namespace App\Domain\Organisation\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class PublicCheckInQrCodeService
{
    public function svg(#[\SensitiveParameter] string $url): string
    {
        $renderer = new ImageRenderer(new RendererStyle(320, 2), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }
}
