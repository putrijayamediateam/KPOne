<?php

namespace App\Domain\HR\Payroll\Services;

use RuntimeException;
use ZipArchive;

/**
 * PDPA 14 (owner, 2026-11-11): a downloaded payroll file is wrapped in a zip whose one entry is encrypted with AES-256
 * under a password the downloader types. The password is used here and nowhere else: it is never stored, logged,
 * flashed or audited. The zip is built in a private temporary file that is always deleted, and there is never a plain
 * fallback: a server whose zip library cannot encrypt refuses the download.
 */
final class ProtectedZip
{
    public const MIN_PASSWORD = 12;

    public static function supported(): bool
    {
        return class_exists(ZipArchive::class) && defined('ZipArchive::EM_AES_256')
            && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true);
    }

    /** @return string the zip's bytes */
    public static function make(string $entryName, string $content, string $password): string
    {
        if (! self::supported()) {
            throw new RuntimeException('AES-256 zip encryption is not available on this server.');
        }
        $path = tempnam(sys_get_temp_dir(), 'kpone-zip-');
        if ($path === false) {
            throw new RuntimeException('No temporary file for the protected zip.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true || ! $zip->addFromString($entryName, $content)
                || ! $zip->setEncryptionName($entryName, ZipArchive::EM_AES_256, $password) || ! $zip->close()) {
                throw new RuntimeException('The protected zip could not be built.');
            }
            $bytes = file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('The protected zip could not be read back.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }
}
