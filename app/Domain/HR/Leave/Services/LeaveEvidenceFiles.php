<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\Organisation\Website\WebsiteImageOrientation;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * HR-2b: checks an uploaded MC by its content, never its name. A photo (JPEG or PNG) is decoded and re-encoded, which
 * drops every EXIF field (camera, GPS location, time) and anything after the image data; a large phone photo is
 * scaled down to a readable 2400 px on its long edge. A PDF is kept as uploaded and is only ever downloaded.
 */
final class LeaveEvidenceFiles
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_EDGE = 2400;

    private const MAX_SOURCE_PIXELS = 50_000_000;

    /** @return array{content:string, kind:'jpeg'|'png'|'pdf', bytes:int, sha256:string} */
    public static function prepare(mixed $file, string $field = 'file'): array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages([$field => 'Use a JPG, PNG or PDF file.']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([$field => 'This file is over 5 MB.']);
        }
        $raw = (string) $file->getContent();
        if ($raw === '') {
            throw ValidationException::withMessages([$field => 'Use a JPG, PNG or PDF file.']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);

        $prepared = match ($mime) {
            'application/pdf' => self::pdf($raw, $field),
            'image/jpeg' => self::photo($raw, 'jpeg', $file, $field),
            'image/png' => self::photo($raw, 'png', $file, $field),
            default => throw ValidationException::withMessages([$field => 'Use a JPG, PNG or PDF file.']),
        };

        return [...$prepared, 'bytes' => strlen($prepared['content']), 'sha256' => hash('sha256', $prepared['content'])];
    }

    /** @return array{content:string, kind:'pdf'} */
    private static function pdf(string $raw, string $field): array
    {
        // A real PDF starts with its header and ends with an end-of-file marker; anything else is refused.
        if (! str_starts_with($raw, '%PDF-') || ! str_contains(substr($raw, -1024), '%%EOF')) {
            throw ValidationException::withMessages([$field => 'Use a JPG, PNG or PDF file.']);
        }

        return ['content' => $raw, 'kind' => 'pdf'];
    }

    /**
     * @param  'jpeg'|'png'  $kind
     * @return array{content:string, kind:'jpeg'|'png'}
     */
    private static function photo(string $raw, string $kind, UploadedFile $file, string $field): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages([$field => 'Photos cannot be accepted on this server. Use a PDF.']);
        }
        $size = @getimagesizefromstring($raw);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            throw ValidationException::withMessages([$field => 'Use a JPG, PNG or PDF file.']);
        }
        $orientation = 1;
        if ($kind === 'jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file->getPathname(), 'IFD0');
            $value = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;
            $orientation = is_int($value) && $value >= 1 && $value <= 8 ? $value : 1;
        }
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw ValidationException::withMessages([$field => 'Use a JPG, PNG or PDF file.']);
        }
        try {
            imagepalettetotruecolor($image);
            $image = WebsiteImageOrientation::upright($image, $orientation);
            $long = max(imagesx($image), imagesy($image));
            if ($long > self::MAX_EDGE) {
                $scaled = imagescale($image, (int) round(imagesx($image) * self::MAX_EDGE / $long), (int) round(imagesy($image) * self::MAX_EDGE / $long), IMG_BICUBIC);
                if ($scaled === false) {
                    throw ValidationException::withMessages([$field => 'Use a JPG, PNG or PDF file.']);
                }
                imagedestroy($image);
                $image = $scaled;
            }
            if ($kind === 'png') {
                imagesavealpha($image, true);
            }
            ob_start();
            try {
                $ok = $kind === 'jpeg' ? imagejpeg($image, null, 85) : imagepng($image, null, 6);
                $content = ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($image);
        }
        if (! $ok || ! is_string($content) || $content === '' || strlen($content) > self::MAX_BYTES) {
            throw ValidationException::withMessages([$field => 'This file is over 5 MB.']);
        }

        return ['content' => $content, 'kind' => $kind];
    }
}
