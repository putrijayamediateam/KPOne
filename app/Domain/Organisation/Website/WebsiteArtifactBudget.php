<?php

namespace App\Domain\Organisation\Website;

final class WebsiteArtifactBudget
{
    public const FILES = 2000;

    public const BYTES = 250 * 1024 * 1024;

    public const SECONDS = 30;

    public const MEMORY = 512 * 1024 * 1024;

    public function __construct(private int $maxFiles = self::FILES, private int $maxBytes = self::BYTES, private float $maxSeconds = self::SECONDS, private int $maxMemory = self::MEMORY) {}

    /** @param array<string,array{bytes:string,mime:string}> $files */
    public function check(array $files, float $started): void
    {
        $this->fileCount(count($files));
        $this->bytes(array_sum(array_map(fn ($f) => strlen($f['bytes']), $files)));
        $this->checkpoint($started);
    }

    public function fileCount(int $count): void
    {
        if ($count > $this->maxFiles) {
            throw new WebsiteArtifactFailure('file_limit');
        }
    }

    public function bytes(int $bytes): void
    {
        if ($bytes > $this->maxBytes) {
            throw new WebsiteArtifactFailure('byte_limit');
        }
        if (memory_get_usage(true) > $this->maxMemory) {
            throw new WebsiteArtifactFailure('memory_limit');
        }
    }

    public function checkpoint(float $started): void
    {
        $this->bytes(0);
        if (microtime(true) - $started > $this->maxSeconds) {
            throw new WebsiteArtifactFailure('deadline');
        }
    }
}
