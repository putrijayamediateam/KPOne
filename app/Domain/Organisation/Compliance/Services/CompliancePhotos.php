<?php

namespace App\Domain\Organisation\Compliance\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-11c audit and task photos: images only (a PDF is refused here, though the shared file check accepts one), encrypted
 * under a random name, the original file name never kept. Files are removed by the daily purge once the clinic sets a
 * number of days; the rows stay. Nothing here reads or writes patient data.
 */
final class CompliancePhotos
{
    public const DISK = 'local';

    public const MAX_PER_OWNER = 3;

    /**
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $file
     * @return array{content:string, kind:string, bytes:int, sha256:string}
     */
    public static function imageOnly(array $file, string $field = 'photo'): array
    {
        if ($file['kind'] === 'pdf') {
            throw ValidationException::withMessages([$field => 'Use a JPG or PNG photo, not a PDF.']);
        }

        return $file;
    }

    public static function newPath(string $folder): string
    {
        return 'compliance/'.$folder.'/'.Str::uuid()->toString();
    }

    public function put(string $path, string $content): void
    {
        if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($content))) {
            throw ValidationException::withMessages(['photo' => "This photo couldn't be saved. Try again."]);
        }
    }

    public function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** @return array{content:string, mime:string, filename:string} */
    public function read(object $photo): array
    {
        $path = data_get($photo, 'stored_path');
        $stored = is_string($path) && Storage::disk(self::DISK)->exists($path) ? Storage::disk(self::DISK)->get($path) : null;
        abort_if(! is_string($stored), 404);
        try {
            $content = Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('Clinic compliance photo could not be decrypted', ['photo_id' => data_get($photo, 'id')]);
            abort(404);
        }
        $kind = (string) data_get($photo, 'kind');

        return ['content' => $content, 'mime' => $kind === 'png' ? 'image/png' : 'image/jpeg', 'filename' => 'photo-'.substr((string) data_get($photo, 'public_id'), 0, 8).'.'.($kind === 'png' ? 'png' : 'jpg')];
    }

    /**
     * Deletes photo files past the clinic's retention (kept until a number of days is set). Open audits and open or done
     * tasks keep their photos; the rows stay, marked purged. Returns how many files were removed.
     */
    public function purgeExpired(): int
    {
        $count = 0;
        foreach (DB::table('compliance_settings')->whereNotNull('photo_retention_days')->get(['organisation_id', 'photo_retention_days']) as $setting) {
            $cutoff = now()->utc()->subDays((int) $setting->photo_retention_days);
            $audit = DB::table('compliance_audit_photos as p')->join('compliance_audits as a', 'a.id', '=', 'p.audit_id')
                ->where('p.organisation_id', $setting->organisation_id)->whereNull('p.purged_at')->where('a.status', 'submitted')->where('a.submitted_at', '<', $cutoff)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('compliance_tasks as t')->whereColumn('t.audit_id', 'a.id')->whereIn('t.status', ['open', 'done'])
                    ->where(fn ($q2) => $q2->where('t.status', 'open')->orWhere('t.verify_required', true)))
                ->get(['p.id', 'p.stored_path']);
            $task = DB::table('compliance_task_photos as p')->join('compliance_tasks as t', 't.id', '=', 'p.task_id')
                ->where('p.organisation_id', $setting->organisation_id)->whereNull('p.purged_at')->where('p.created_at', '<', $cutoff)
                ->where(fn ($q) => $q->whereIn('t.status', ['verified', 'cancelled'])->orWhere(fn ($q2) => $q2->where('t.status', 'done')->where('t.verify_required', false)))
                ->get(['p.id', 'p.stored_path']);
            $count += $this->purge('compliance_audit_photos', $audit) + $this->purge('compliance_task_photos', $task);
        }

        return $count;
    }

    /** @param  Collection<int, \stdClass>  $rows */
    private function purge(string $table, $rows): int
    {
        $done = 0;
        foreach ($rows as $row) {
            $changed = DB::transaction(fn (): int => DB::table($table)->where('id', $row->id)->whereNull('purged_at')->update(['stored_path' => null, 'purged_at' => now()->utc()]));
            if ($changed > 0) {
                $this->forget((string) $row->stored_path);
                $done++;
            }
        }

        return $done;
    }
}
