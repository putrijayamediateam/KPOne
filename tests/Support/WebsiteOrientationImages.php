<?php

namespace Tests\Support;

/** Synthetic non-square quadrant pattern; no personal images or metadata. */
final class WebsiteOrientationImages
{
    public static function exif(int $orientation, bool $little = true): string
    {
        $short = $little ? 'v' : 'n';
        $long = $little ? 'V' : 'N';

        return ($little ? 'II' : 'MM').pack($short.$long, 42, 8)
            .pack($short, 2)
            .pack($short.$short.$long, 0x0112, 3, 1).pack($short, $orientation)."\0\0"
            .pack($short.$short.$long.$long, 0x8825, 4, 1, 38).pack($long, 0)
            .pack($short, 1).pack($short.$short.$long, 1, 2, 2)."N\0\0\0".pack($long, 0);
    }

    public static function plain(string $extension): string
    {
        $image = imagecreatetruecolor(120, 80);
        foreach ([[0, 0, 59, 39, 230, 20, 20], [60, 0, 119, 39, 20, 210, 20], [0, 40, 59, 79, 20, 20, 230], [60, 40, 119, 79, 230, 210, 20]] as [$x1, $y1, $x2, $y2, $r, $g, $b]) {
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, imagecolorallocate($image, $r, $g, $b));
        }
        ob_start();
        match ($extension) {
            'jpg' => imagejpeg($image, null, 100), 'png' => imagepng($image), 'webp' => imagewebp($image, null, 100)
        };
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public static function withExif(string $extension, string $exif): string
    {
        $raw = self::plain($extension);
        if ($extension === 'jpg') {
            $exif = "Exif\0\0".$exif;

            return substr($raw, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($raw, 2);
        }
        if ($extension === 'png') {
            $chunk = 'eXIf'.$exif;

            return substr($raw, 0, 33).pack('N', strlen($exif)).$chunk.hex2bin(hash('crc32b', $chunk)).substr($raw, 33);
        }
        // Extended WebP with the EXIF feature flag and an explicit 120 × 80 canvas.
        $vp8x = 'VP8X'.pack('V', 10)."\x08\0\0\0".substr(pack('V', 119), 0, 3).substr(pack('V', 79), 0, 3);
        $chunks = $vp8x.substr($raw, 12).'EXIF'.pack('V', strlen($exif)).$exif.(strlen($exif) % 2 ? "\0" : '');

        return 'RIFF'.pack('V', strlen($chunks) + 4).'WEBP'.$chunks;
    }
}
