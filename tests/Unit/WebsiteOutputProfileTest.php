<?php

namespace Tests\Unit;

use App\Domain\Organisation\Website\WebsiteOutputProfile;
use App\Domain\Organisation\Website\WebsiteReleaseJson;
use PHPUnit\Framework\TestCase;

class WebsiteOutputProfileTest extends TestCase
{
    public function test_every_fixed_foreground_background_pair_passes_wcag_contrast(): void
    {
        $definition = WebsiteOutputProfile::definition();
        $luminance = function (string $hex): float {
            $channels = array_map(fn ($part) => hexdec($part) / 255, str_split(substr($hex, 1), 2));
            $linear = array_map(fn ($v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $channels);

            return $linear[0] * 0.2126 + $linear[1] * 0.7152 + $linear[2] * 0.0722;
        };
        foreach ($definition['pairs'] as $pair) {
            $a = $luminance($definition['tokens'][$pair['foreground']]);
            $b = $luminance($definition['tokens'][$pair['background']]);
            $ratio = (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
            $minimum = $pair['use'] === 'text' ? 4.5 : 3.0;
            $this->assertGreaterThanOrEqual($minimum, $pair['minimum']);
            $this->assertGreaterThanOrEqual($pair['minimum'], $ratio, $pair['foreground'].'/'.$pair['background']);
        }
        $this->assertSame(hash('sha256', WebsiteReleaseJson::encode($definition)), WebsiteOutputProfile::frozen()['digest']);
        $this->assertSame('light', $definition['mode']);
    }
}
