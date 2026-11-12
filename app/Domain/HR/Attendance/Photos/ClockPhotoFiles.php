<?php

namespace App\Domain\HR\Attendance\Photos;

use App\Domain\Organisation\Website\WebsiteImageOrientation;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * HR-PHOTO: checks a clock photo by its content. The camera sends a JPEG (or PNG); it is decoded and re-encoded, which
 * drops every EXIF field (camera, GPS location, time) and anything after the image data, scaled to at most 1280 px on
 * its long edge, with a 240 px thumbnail for lists. The upload is held to 2 MB, and so is each re-encoded copy.
 */
final class ClockPhotoFiles
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_EDGE = 1280;

    public const THUMB_EDGE = 240;

    /** About a 16 MP photo; larger is refused before decoding (a 16 MP decode is about 64 MB), Suresh m7. */
    public const MAX_SOURCE_PIXELS = 16_000_000;

    public const NEEDED = 'Take a photo to clock in.';

    public const UNREADABLE = "This photo can't be read. Take it again.";

    /** @return array{content:string, thumb:string, width:int, height:int, bytes:int, thumb_bytes:int, sha256:string} */
    public static function prepare(mixed $file, string $field = 'photo'): array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages([$field => self::NEEDED]);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([$field => 'This photo is over 2 MB. Take it again.']);
        }

        return self::fromString((string) $file->getContent(), $field);
    }

    /** @return array{content:string, thumb:string, width:int, height:int, bytes:int, thumb_bytes:int, sha256:string} */
    public static function fromString(string $raw, string $field = 'photo'): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw ValidationException::withMessages([$field => $raw === '' ? self::NEEDED : 'This photo is over 2 MB. Take it again.']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages([$field => self::UNREADABLE]);
        }
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages([$field => 'Photos cannot be taken on this server. Ask HR to add your time as a correction.']);
        }
        $size = @getimagesizefromstring($raw);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            throw ValidationException::withMessages([$field => self::UNREADABLE]);
        }
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw ValidationException::withMessages([$field => self::UNREADABLE]);
        }
        try {
            imagepalettetotruecolor($image);
            // A phone file carries its rotation in EXIF, which the re-encode drops; turn it upright first (Suresh m1).
            $image = WebsiteImageOrientation::upright($image, self::orientation($raw, $mime));
            $full = self::scaled($image, self::MAX_EDGE, $field);
            $thumb = self::scaled($image, self::THUMB_EDGE, $field);
            try {
                $width = imagesx($full);
                $height = imagesy($full);
                $content = self::jpeg($full, 82, $field);
                $thumbContent = self::jpeg($thumb, 78, $field);
            } finally {
                if ($full !== $image) {
                    imagedestroy($full);
                }
                if ($thumb !== $image) {
                    imagedestroy($thumb);
                }
            }
        } finally {
            imagedestroy($image);
        }

        return ['content' => $content, 'thumb' => $thumbContent, 'width' => $width, 'height' => $height, 'bytes' => strlen($content),
            'thumb_bytes' => strlen($thumbContent), 'sha256' => hash('sha256', $content)];
    }

    private static function orientation(string $raw, string $mime): int
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 1;
        }
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return 1;
        }
        fwrite($stream, $raw);
        rewind($stream);
        $exif = @exif_read_data($stream, 'IFD0');
        fclose($stream);
        $value = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($value) && $value >= 1 && $value <= 8 ? $value : 1;
    }

    private static function scaled(\GdImage $image, int $edge, string $field): \GdImage
    {
        $long = max(imagesx($image), imagesy($image));
        if ($long <= $edge) {
            return $image;
        }
        $scaled = imagescale($image, max(1, (int) round(imagesx($image) * $edge / $long)), max(1, (int) round(imagesy($image) * $edge / $long)), IMG_BICUBIC);
        if ($scaled === false) {
            throw ValidationException::withMessages([$field => self::UNREADABLE]);
        }

        return $scaled;
    }

    private static function jpeg(\GdImage $image, int $quality, string $field): string
    {
        ob_start();
        try {
            $ok = imagejpeg($image, null, $quality);
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (! $ok || ! is_string($content) || $content === '' || strlen($content) > self::MAX_BYTES) {
            throw ValidationException::withMessages([$field => self::UNREADABLE]);
        }

        return $content;
    }
}
