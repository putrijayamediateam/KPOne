<?php

namespace Tests\Feature\Website;

use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsiteImage;
use App\Domain\Organisation\Website\WebsiteImageOrientation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\WebsiteOrientationImages as Images;

class WebsiteImageOrientationTest extends VisitTestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    private function file(string $extension, string $raw): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'w1a-orientation-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $raw);

        return new UploadedFile($path, 'synthetic.'.$extension, match ($extension) {
            'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'
        }, null, true);
    }

    public static function orientations(): iterable
    {
        // Expected output quadrants (TL, TR, BL, BR), independently specified by EXIF semantics.
        $patterns = [1 => 'ABCD', 2 => 'BADC', 3 => 'DCBA', 4 => 'CDAB', 5 => 'ACBD', 6 => 'CADB', 7 => 'DBCA', 8 => 'BDAC'];
        foreach (['jpg', 'png', 'webp'] as $extension) {
            foreach ($patterns as $orientation => $pattern) {
                yield "$extension orientation $orientation" => [$extension, $orientation, $pattern];
            }
        }
    }

    #[DataProvider('orientations')]
    public function test_orientation_pixels_final_dimensions_and_retained_metadata_hash(string $extension, int $orientation, string $pattern): void
    {
        Storage::fake('website_media');
        $actor = $this->actor('marketing');
        $this->selectBranch($actor);
        $file = $this->file($extension, Images::withExif($extension, Images::exif($orientation)));
        if ($extension === 'jpg') {
            $before = exif_read_data($file->getPathname(), null, true);
            $this->assertSame($orientation, $before['IFD0']['Orientation']);
            $this->assertArrayHasKey('GPS', $before);
        }
        app(WebsiteDraftService::class)->upload($actor, [
            'expected_branch_id' => $this->branch->id, 'lock_version' => 0,
            'idempotency_key' => (string) Str::uuid(), 'alt_en' => 'Synthetic quadrant pattern',
            'alt_bm' => 'Corak kuadran sintetik', 'rights_reference' => 'Synthetic test illustration',
            'identifiable_person' => false, 'consent_reference' => '',
        ], $file);
        $asset = DB::table('website_media_assets')->sole();
        $retained = Storage::disk('website_media')->get($asset->path);
        $pixels = imagecreatefromstring($retained);
        $width = $orientation >= 5 ? 80 : 120;
        $height = $orientation >= 5 ? 120 : 80;
        $this->assertSame($width, imagesx($pixels));
        $this->assertSame($height, imagesy($pixels));
        $this->assertSame($width, $asset->width);
        $this->assertSame($height, $asset->height);
        $this->assertLessThanOrEqual(WebsiteImage::MAX_DIMENSION, max($width, $height));
        $this->assertLessThanOrEqual(WebsiteImage::MAX_PIXELS, $width * $height);
        $colours = ['A' => [230, 20, 20], 'B' => [20, 210, 20], 'C' => [20, 20, 230], 'D' => [230, 210, 20]];
        foreach (str_split($pattern) as $index => $quadrant) {
            $x = (int) ($width * ($index % 2 ? 0.75 : 0.25));
            $y = (int) ($height * ($index >= 2 ? 0.75 : 0.25));
            $actual = imagecolorsforindex($pixels, imagecolorat($pixels, $x, $y));
            foreach (['red', 'green', 'blue'] as $channel => $key) {
                $this->assertLessThan(25, abs($actual[$key] - $colours[$quadrant][$channel]), "Orientation $orientation quadrant $index $key");
            }
        }
        imagedestroy($pixels);
        $this->assertSame(hash('sha256', $retained), $asset->sha256);
        $this->assertSame(strlen($retained), $asset->bytes);
        $this->assertCount(1, Storage::disk('website_media')->allFiles());
        $this->assertStringNotContainsString("Exif\0\0", $retained);
        if ($extension === 'jpg') {
            $after = exif_read_data($this->file('jpg', $retained)->getPathname(), null, true);
            $this->assertArrayNotHasKey('GPS', $after);
            $this->assertArrayNotHasKey('IFD0', $after);
        } else {
            $this->assertStringNotContainsString($extension === 'png' ? 'eXIf' : 'EXIF', $retained);
        }
    }

    public function test_bounded_fallback_reader_supports_both_byte_orders_and_optional_exif_header(): void
    {
        foreach ([true, false] as $little) {
            for ($orientation = 1; $orientation <= 8; $orientation++) {
                $tiff = Images::exif($orientation, $little);
                $this->assertSame($orientation, WebsiteImageOrientation::fromExif($tiff));
                $this->assertSame($orientation, WebsiteImageOrientation::fromExif("Exif\0\0".$tiff));
                $this->assertSame($orientation >= 5 ? 80 : 120, WebsiteImage::sanitise($this->file('jpg', Images::withExif('jpg', $tiff)))['width']);
            }
        }
        $this->assertSame(1, WebsiteImageOrientation::fromExif('II'.pack('vVvV', 42, 8, 0, 0)));
    }

    public static function malformed(): iterable
    {
        $valid = Images::exif(1);
        yield 'empty EXIF' => [''];
        yield 'short TIFF' => ['II'];
        yield 'invalid byte order' => ['ZZ'.substr($valid, 2)];
        yield 'invalid magic' => [substr_replace($valid, pack('v', 43), 2, 2)];
        yield 'out-of-bounds IFD' => [substr_replace($valid, pack('V', 0xFFFFFFFF), 4, 4)];
        yield 'IFD within header' => [substr_replace($valid, pack('V', 2), 4, 4)];
        yield 'truncated entries' => [substr($valid, 0, 21)];
        yield 'excess entry count' => [substr_replace($valid, pack('v', 65535), 8, 2)];
        yield 'wrong tag type' => [substr_replace($valid, pack('v', 4), 12, 2)];
        yield 'wrong tag count' => [substr_replace($valid, pack('V', 0xFFFFFFFF), 14, 4)];
        yield 'orientation zero' => [Images::exif(0)];
        yield 'orientation nine' => [Images::exif(9)];
        yield 'orientation 65535' => [Images::exif(65535)];
        yield 'duplicate orientation' => [substr_replace($valid, substr($valid, 10, 12), 22, 12)];
    }

    #[DataProvider('malformed')]
    public function test_malformed_exif_refuses_safely_in_every_supported_container(string $tiff): void
    {
        foreach (['jpg', 'png', 'webp'] as $extension) {
            try {
                WebsiteImage::sanitise($this->file($extension, Images::withExif($extension, $tiff)));
                $this->fail('Malformed orientation accepted: '.$extension);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('image', $e->errors());
            }
        }
    }
}
