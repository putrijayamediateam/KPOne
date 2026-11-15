<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class WebsiteMediaCapture
{
    /** @return array{asset:\stdClass,bytes:string,filename:string} */
    public static function capture(int $organisation, int|string $reference): array
    {
        $asset = DB::table('website_media_assets')->where('organisation_id', $organisation)->where(is_int($reference) ? 'id' : 'public_id', $reference)->lockForUpdate()->first();
        if (! $asset || $asset->retired_at !== null || trim($asset->rights_reference) === '' || ($asset->identifiable_person && trim($asset->consent_reference) === '') || trim($asset->alt_en) === '' || trim($asset->alt_bm) === '') {
            throw new \RuntimeException;
        }
        $bytes = Storage::disk('website_media')->get($asset->path);
        $size = @getimagesizefromstring($bytes);
        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            throw new \RuntimeException;
        }
        imagedestroy($decoded);
        if (! hash_equals($asset->sha256, hash('sha256', $bytes)) || strlen($bytes) !== $asset->bytes || ! $size || $size[0] !== $asset->width || $size[1] !== $asset->height || $size['mime'] !== $asset->mime) {
            throw new \RuntimeException;
        }
        $extension = match ($asset->mime) {
            'image/jpeg' => 'jpg','image/png' => 'png','image/webp' => 'webp',default => throw new \RuntimeException
        };
        $filename = $asset->sha256.'.'.$extension;

        return ['asset' => $asset, 'bytes' => $bytes, 'filename' => $filename];
    }
}
