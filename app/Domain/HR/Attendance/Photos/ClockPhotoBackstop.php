<?php

namespace App\Domain\HR\Attendance\Photos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * HR-PHOTO daily backstop. Deletes capture tokens a day after they ran out (a used token stays while its photo does),
 * and any photo file that no row points at and that is over a day old: a file whose delete failed after the attendance
 * purge, or one left by a crash between writing the file and the commit. A day's margin keeps it clear of a press in
 * progress. The sweep lists the files once and checks their rows 500 at a time; with photos kept 7 years that is a few
 * hundred thousand files at most, read once a night.
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
        // Old enough first (a stat each), then one query per 500 paths, not one per file (Suresh, M1).
        $old = array_values(array_filter($disk->allFiles('hr-clock-photos'), fn (string $path): bool => $disk->lastModified($path) < $cutoff));
        foreach (array_chunk($old, 500) as $paths) {
            $known = DB::table('hr_clock_photos')->whereIn('stored_path', $paths)->pluck('stored_path')
                ->merge(DB::table('hr_clock_photos')->whereIn('thumb_path', $paths)->pluck('thumb_path'))->flip();
            foreach ($paths as $path) {
                if (! isset($known[$path]) && $disk->delete($path)) {
                    $files++;
                }
            }
        }

        return ['tokens' => $tokens, 'files' => $files];
    }
}
