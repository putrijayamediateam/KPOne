<?php

namespace App\Domain\Organisation\Website;

final class WebsiteArtifactFailure extends \RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('Website build refused: '.$failureCode);
    }
}
