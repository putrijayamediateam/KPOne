<?php

namespace Tests\Support;

use App\Domain\HR\Attendance\Photos\ClockPhotoService;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/**
 * HR-PHOTO test helper: a small synthetic JPEG of random noise (never a face, never a real photo), different on every
 * call so the "used before" rule does not trip, and the capture token the camera would take first.
 */
final class ClockPhotoFixture
{
    public static function upload(int $width = 64, int $height = 64): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('photo.jpg', self::bytes($width, $height));
    }

    public static function bytes(int $width = 64, int $height = 64): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($i = 0; $i < 40; $i++) {
            $colour = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagefilledrectangle($image, random_int(0, $width - 1), random_int(0, $height - 1), random_int(0, $width - 1), random_int(0, $height - 1), (int) $colour);
        }
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** The token the camera takes when it opens; posts as the signed-in test user. */
    public static function token(TestCase $test, string $kind): string
    {
        // The one-time notice first (seeing it again changes nothing), then the token.
        $test->postJson(route('hr.attendance.photo-notice'), ['version' => ClockPhotoService::NOTICE_VERSION])->assertOk();
        /** @var TestResponse $response */
        $response = $test->postJson(route('hr.attendance.capture'), ['kind' => $kind]);
        $response->assertOk();

        return (string) $response->json('token');
    }

    /** @return array{photo: UploadedFile, capture_token: string} */
    public static function press(TestCase $test, string $kind): array
    {
        return ['photo' => self::upload(), 'capture_token' => self::token($test, $kind)];
    }
}
