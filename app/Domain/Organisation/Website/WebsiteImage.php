<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class WebsiteImage
{
    public const MAX_BYTES = 5242880;

    public const MAX_DIMENSION = 4096;

    public const MAX_PIXELS = 12000000;

    /** @return array{content:string,mime:string,extension:string,width:int,height:int,bytes:int,sha256:string} */
    public static function sanitise(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            self::refuse('Choose a JPG, PNG or WebP image at most 5 MB.');
        }
        $raw = $file->getContent();
        $mime = $file->getMimeType();
        $size = @getimagesizefromstring($raw);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg','image/png' => 'png','image/webp' => 'webp',default => null
        };
        $allowedExtension = $ext === 'jpg' ? ['jpg', 'jpeg'] : [$ext];
        if (! $ext || ! $size || $size['mime'] !== $mime || $file->getClientMimeType() !== $mime || ! in_array(strtolower($file->getClientOriginalExtension()), $allowedExtension, true)) {
            self::refuse('The filename, declared type and actual image content must agree (JPG, PNG or WebP only).');
        }
        self::checkDimensions($size[0], $size[1]);
        $exif = null;
        if (preg_match('/<\?(?:php|=)|<script\b|<!doctype\b|<html\b/i', $raw)) {
            self::refuse('Embedded executable or HTML content is not allowed.');
        }
        if ($ext === 'jpg') {
            $exif = self::checkJpeg($raw);
        }
        if ($ext === 'webp' && (substr($raw, 0, 4) !== 'RIFF' || substr($raw, 8, 4) !== 'WEBP' || (unpack('Vlength', substr($raw, 4, 4))['length'] ?? 0) + 8 !== strlen($raw) || str_contains($raw, 'ANIM'))) {
            self::refuse('Use a single still WebP without trailing content.');
        }
        if ($ext === 'webp') {
            $exif = self::webpExif($raw);
        }
        if ($ext === 'png') {
            $offset = 8;
            $ended = false;
            while ($offset + 12 <= strlen($raw)) {
                $length = unpack('Nlength', substr($raw, $offset, 4));
                if ($length === false) {
                    self::refuse('PNG content is invalid.');
                }
                $length = $length['length'];
                $type = substr($raw, $offset + 4, 4);
                if ($length > strlen($raw) - $offset - 12) {
                    self::refuse('PNG content is invalid.');
                }
                $chunk = substr($raw, $offset + 4, 4 + $length);
                $crc = bin2hex(substr($raw, $offset + 8 + $length, 4));
                if (hash('crc32b', $chunk) !== $crc || $type === 'acTL') {
                    self::refuse('Use a valid still PNG.');
                }
                if ($type === 'eXIf') {
                    if ($exif !== null) {
                        self::refuse('Multiple EXIF chunks are not supported.');
                    }
                    $exif = substr($raw, $offset + 8, $length);
                }
                $offset += 12 + $length;
                if ($type === 'IEND') {
                    $ended = true;
                    break;
                }
            }
            if (! $ended || $offset !== strlen($raw)) {
                self::refuse('PNG has invalid or trailing content.');
            }
        }
        $orientation = $exif === null ? 1 : WebsiteImageOrientation::fromExif($exif);
        if ($ext === 'jpg' && $exif !== null && function_exists('exif_read_data')) {
            $native = @exif_read_data($file->getPathname(), 'IFD0', true);
            $nativeOrientation = $native['IFD0']['Orientation'] ?? null;
            if ($nativeOrientation !== null) {
                if (! is_int($nativeOrientation) || $nativeOrientation !== $orientation) {
                    self::refuse('Conflicting image EXIF orientation.');
                }
                $orientation = $nativeOrientation;
            }
        }
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            self::refuse('Image cannot be decoded.');
        }
        try {
            imagepalettetotruecolor($image);
            imagesavealpha($image, true);
            $image = WebsiteImageOrientation::upright($image, $orientation);
            $width = imagesx($image);
            $height = imagesy($image);
            self::checkDimensions($width, $height);
            ob_start();
            try {
                $ok = match ($ext) {
                    'jpg' => imagejpeg($image, null, 90),'png' => imagepng($image, null, 6),'webp' => imagewebp($image, null, 90)
                };
                $content = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! $ok || ! is_string($content) || $content === '' || strlen($content) > self::MAX_BYTES) {
                self::refuse('Sanitised image cannot be stored within the 5 MB limit.');
            }

            return ['content' => $content, 'mime' => $mime, 'extension' => $ext, 'width' => $width, 'height' => $height, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
        } finally {
            imagedestroy($image);
        }
    }

    private static function checkJpeg(string $raw): ?string
    {
        if (! str_starts_with($raw, "\xff\xd8")) {
            self::refuse('Invalid JPEG.');
        }
        $offset = 2;
        $exif = null;
        $length = strlen($raw);
        while ($offset < $length) {
            if (ord($raw[$offset++]) !== 255) {
                self::refuse('Invalid JPEG marker.');
            }
            while ($offset < $length && ord($raw[$offset]) === 255) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }
            $marker = ord($raw[$offset++]);
            if ($marker === 217) {
                if ($offset !== $length) {
                    self::refuse('JPEG has trailing content.');
                }

                return $exif;
            }
            if ($marker === 0 || $marker === 216) {
                self::refuse('Invalid JPEG marker.');
            }
            if ($marker === 1 || ($marker >= 208 && $marker <= 215)) {
                continue;
            }
            if ($offset + 2 > $length) {
                break;
            }
            $segment = ord($raw[$offset]) * 256 + ord($raw[$offset + 1]);
            if ($segment < 2 || $offset + $segment > $length) {
                self::refuse('Invalid JPEG segment.');
            }
            if ($marker === 225 && str_starts_with(substr($raw, $offset + 2, $segment - 2), 'Exif')) {
                if ($exif !== null || substr($raw, $offset + 2, 6) !== "Exif\0\0") {
                    self::refuse('Invalid or duplicate JPEG EXIF.');
                }
                $exif = substr($raw, $offset + 2, $segment - 2);
            }
            $offset += $segment;
            if ($marker === 218) {
                while ($offset < $length) {
                    if (ord($raw[$offset]) !== 255) {
                        $offset++;

                        continue;
                    }
                    $next = $offset + 1;
                    while ($next < $length && ord($raw[$next]) === 255) {
                        $next++;
                    }
                    if ($next >= $length) {
                        break;
                    }
                    $code = ord($raw[$next]);
                    if ($code === 0 || ($code >= 208 && $code <= 215)) {
                        $offset = $next + 1;

                        continue;
                    }
                    break;
                }
            }
        }
        self::refuse('JPEG has no complete end marker.');
    }

    private static function checkDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || max($width, $height) > self::MAX_DIMENSION || $width * $height > self::MAX_PIXELS) {
            self::refuse('Images must be at most 4096 pixels per side and 12 million pixels in total.');
        }
    }

    private static function webpExif(string $raw): ?string
    {
        $offset = 12;
        $exif = null;
        while ($offset < strlen($raw)) {
            if ($offset + 8 > strlen($raw)) {
                self::refuse('Invalid WebP chunk.');
            }
            $length = unpack('Vlength', substr($raw, $offset + 4, 4));
            if ($length === false || $length['length'] > strlen($raw) - $offset - 8) {
                self::refuse('Invalid WebP chunk length.');
            }
            $length = $length['length'];
            if (substr($raw, $offset, 4) === 'EXIF') {
                if ($exif !== null) {
                    self::refuse('Multiple EXIF chunks are not supported.');
                }
                $exif = substr($raw, $offset + 8, $length);
            }
            $offset += 8 + $length;
            if ($length % 2 !== 0) {
                if ($offset >= strlen($raw) || $raw[$offset] !== "\0") {
                    self::refuse('Invalid WebP chunk padding.');
                }
                $offset++;
            }
        }

        return $exif;
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages(['image' => $message]);
    }
}
