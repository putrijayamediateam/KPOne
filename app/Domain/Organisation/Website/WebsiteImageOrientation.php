<?php

namespace App\Domain\Organisation\Website;

use GdImage;
use Illuminate\Validation\ValidationException;

/** Bounded primary-image TIFF orientation reader, also used without PHP EXIF support. */
final class WebsiteImageOrientation
{
    public static function fromExif(string $data): int
    {
        if (str_starts_with($data, "Exif\0\0")) {
            $data = substr($data, 6);
        }
        if (strlen($data) < 8 || ! in_array(substr($data, 0, 2), ['II', 'MM'], true)) {
            self::refuse();
        }
        $little = substr($data, 0, 2) === 'II';
        if (self::unsigned($data, 2, 2, $little) !== 42) {
            self::refuse();
        }
        $ifd = self::unsigned($data, 4, 4, $little);
        if ($ifd < 8 || $ifd > strlen($data) - 2) {
            self::refuse();
        }
        $count = self::unsigned($data, $ifd, 2, $little);
        // Include the mandatory next-IFD pointer; never follow thumbnails or GPS pointers.
        if ($count * 12 + 6 > strlen($data) - $ifd) {
            self::refuse();
        }
        $orientation = null;
        for ($index = 0; $index < $count; $index++) {
            $entry = $ifd + 2 + $index * 12;
            if (self::unsigned($data, $entry, 2, $little) !== 0x0112) {
                continue;
            }
            if ($orientation !== null || self::unsigned($data, $entry + 2, 2, $little) !== 3 || self::unsigned($data, $entry + 4, 4, $little) !== 1) {
                self::refuse();
            }
            $orientation = self::unsigned($data, $entry + 8, 2, $little);
            if ($orientation < 1 || $orientation > 8) {
                self::refuse();
            }
        }

        return $orientation ?? 1;
    }

    private static function unsigned(string $data, int $offset, int $bytes, bool $little): int
    {
        if ($offset < 0 || $offset > strlen($data) - $bytes) {
            self::refuse();
        }
        $format = $bytes === 2 ? ($little ? 'v' : 'n') : ($little ? 'V' : 'N');
        $value = unpack($format.'value', substr($data, $offset, $bytes));
        if ($value === false) {
            self::refuse();
        }

        return $value['value'];
    }

    /** Takes ownership of the old image only when returning a successfully rotated replacement. */
    public static function upright(GdImage $image, int $orientation): GdImage
    {
        if ($orientation < 1 || $orientation > 8) {
            self::refuse();
        }
        if (in_array($orientation, [2, 5, 7], true) && ! imageflip($image, IMG_FLIP_HORIZONTAL)) {
            self::refuse();
        }
        if ($orientation === 4 && ! imageflip($image, IMG_FLIP_VERTICAL)) {
            self::refuse();
        }
        $angle = match ($orientation) {
            3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
        };
        if ($angle === 0) {
            return $image;
        }
        $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
        if ($background === false) {
            self::refuse();
        }
        $rotated = imagerotate($image, $angle, $background);
        if ($rotated === false) {
            self::refuse();
        }
        imagesavealpha($rotated, true);
        imagedestroy($image);

        return $rotated;
    }

    private static function refuse(): never
    {
        throw ValidationException::withMessages(['image' => 'Image EXIF orientation is malformed, conflicting or outside 1–8. Choose a valid image.']);
    }
}
