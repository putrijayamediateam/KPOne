<?php

namespace App\Domain\HR\Attendance\Photos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * HR-PHOTO daily backstop. Deletes capture tokens a day after they ran out (a used token stays while its photo does),
 * and any photo file that no row points at and that is over a day old: a file whose delete failed after the attendance
 * purge, or one left by a crash between writing the file and the commit. A day's margin keeps it clear of a press in
 * progress.
 */
final class ClockPhotoBackstop
{
    /** @return array{tokens: int, files: int} */
    public function run(): array
    {
        $tokens = DB::table('hr_clock_capture_tokens as t')->whereRaw("t.expires_at < now() - interval '1 day' - interval '1 minute'")
            ->whereNotExists(fn ($q) => $q->from('hr_clock_photos as p')->whereColumn('p.capture_token_id', 't.id'))->delete();
        $disk = Storage::disk(ClockPhotoService::DISK);
        $cutoff = now()->subDay()->getTimestamp();
        $files = 0;
        foreach ($disk->allFiles('hr-clock-photos') as $path) {
            if ($disk->lastModified($path) >= $cutoff) {
                continue;
            }
            $known = DB::table('hr_clock_photos')->where('stored_path', $path)->orWhere('thumb_path', $path)->exists();
            if (! $known && $disk->delete($path)) {
                $files++;
            }
        }

        return ['tokens' => $tokens, 'files' => $files];
    }
}
