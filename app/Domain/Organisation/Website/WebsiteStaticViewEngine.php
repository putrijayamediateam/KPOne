<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Contracts\View\Engine;

/** In-memory, trusted Blade programs. The constructor never accepts content from a DTO. */
final class WebsiteStaticViewEngine implements Engine
{
    /** @param array<string,\Closure> $programs */
    public function __construct(private array $programs) {}

    /** @param array<string,mixed> $data */
    public function get($path, array $data = []): string
    {
        $program = $this->programs[$path] ?? throw new WebsiteArtifactFailure('render');
        $level = ob_get_level();
        ob_start();
        try {
            $program($data);
            $result = ob_get_clean();

            return $result === false ? throw new WebsiteArtifactFailure('render') : $result;
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
